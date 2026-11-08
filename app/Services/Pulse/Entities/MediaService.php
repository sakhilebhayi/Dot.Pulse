<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseMedia;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Lines\Access;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Images, and nothing else. An upload is judged by its bytes (never its name or the browser's mime type),
 * decoded, and re-encoded to WebP (JPEG where the server's GD has no WebP) at three sizes, which strips EXIF and
 * anything appended to or hidden in the original. Files live on the private disk and are served only through the
 * authorised route `pulse.media`; there is no public path to guess.
 *
 * Who can read what (see canView): logos, covers, offer images and gallery images of a live page, to anyone;
 * the same on a page that is not live, to its members; an avatar to signed-in people unless the profile is
 * anonymous (then the owner only); message media to the people in that conversation and the page speaking in it.
 */
class MediaService
{
    public const PURPOSES = ['logo', 'cover', 'offer', 'gallery', 'avatar', 'message'];

    public const SIZES = ['thumb', 'medium', 'large'];

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return int the PulseMedia id
     *
     * @throws ValidationException when the file is not an acceptable image
     */
    public function store(User $by, UploadedFile $file, string $purpose, ?PulseEntity $entity = null, ?int $conversationId = null): int
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw ValidationException::withMessages(['file' => 'Unknown image purpose.']);
        }

        $this->authoriseUpload($by, $purpose, $entity, $conversationId);
        $this->throttle($by);

        $bytes = $this->readUpload($file);
        [$image, $mime] = $this->decode($bytes);

        try {
            $image = $this->orient($image, $file, $mime);
            $variants = $this->encodeVariants($image);
        } finally {
            imagedestroy($image);
        }

        $disk = (string) config('pulse.entities.media.disk', 'local');
        $dir = 'pulse/media/'.now()->format('Y/m').'/'.Str::uuid();
        $stored = [];

        try {
            foreach ($variants as $size => $v) {
                $path = "{$dir}-{$size}.{$v['ext']}";
                Storage::disk($disk)->put($path, $v['data']);
                $stored[$size] = ['path' => $path, 'width' => $v['width'], 'height' => $v['height'], 'bytes' => strlen($v['data'])];
            }
        } catch (Throwable $e) {
            foreach ($stored as $s) {
                Storage::disk($disk)->delete($s['path']);
            }

            throw $e;
        }

        $largest = end($stored);

        $media = PulseMedia::create([
            'user_id' => $by->id,
            'purpose' => $purpose,
            'entity_id' => $entity?->id,
            'conversation_id' => $purpose === 'message' ? $conversationId : null,
            'disk' => $disk,
            'mime' => end($variants)['mime'],
            'width' => $largest['width'],
            'height' => $largest['height'],
            'bytes' => $largest['bytes'],
            'variants' => $stored,
            'sha256' => hash('sha256', $bytes),
        ]);

        Audit::record($by, 'media.store', $media, ['purpose' => $purpose, 'entity_id' => $entity?->id]);

        return $media->id;
    }

    /** The authorised URL of an image; the route decides again, on every request, who may read it. */
    public function url(int $mediaId, string $size = 'medium'): string
    {
        if (! in_array($size, self::SIZES, true)) {
            $size = 'medium';
        }

        return route('pulse.media', ['mediaFile' => $mediaId, 'size' => $size], false);
    }

    /** Removes the files and the row. The uploader, or a person who manages the page it belongs to. */
    public function delete(User $by, int $mediaId): void
    {
        $media = PulseMedia::findOrFail($mediaId);

        $allowed = $media->user_id === $by->id
            || ($media->entity_id && Gate::forUser($by)->allows('manageListings', PulseEntity::withTrashed()->findOrFail($media->entity_id)));

        abort_unless($allowed, 403);

        foreach ((array) $media->variants as $variant) {
            Storage::disk($media->disk)->delete($variant['path']);
        }

        PulseEntity::query()->where('logo_media_id', $media->id)->update(['logo_media_id' => null]);
        PulseEntity::query()->where('cover_media_id', $media->id)->update(['cover_media_id' => null]);
        PulseProfile::query()->where('avatar_media_id', $media->id)->update(['avatar_media_id' => null]);

        $media->delete();
        Audit::record($by, 'media.delete', null, ['media_id' => $mediaId]);
    }

    /** Can this viewer (null = signed out) read this image? The route calls this; so can any caller about to link one. */
    public function canView(PulseMedia $media, ?User $viewer): bool
    {
        if ($viewer !== null && $media->user_id === $viewer->id) {
            return true;
        }

        return match ($media->purpose) {
            'message' => $this->inConversation($media, $viewer),
            'avatar' => $this->avatarVisible($media, $viewer),
            default => $this->entityImageVisible($media, $viewer),
        };
    }

    // ---- internals ------------------------------------------------------------------------------------------

    private function authoriseUpload(User $by, string $purpose, ?PulseEntity $entity, ?int $conversationId): void
    {
        switch ($purpose) {
            case 'logo':
            case 'cover':
                abort_if($entity === null, 422, 'A page is required.');
                Gate::forUser($by)->authorize('update', $entity);
                break;
            case 'offer':
            case 'gallery':
                abort_if($entity === null, 422, 'A page is required.');
                Gate::forUser($by)->authorize('manageListings', $entity);
                break;
            case 'message':
                abort_if($conversationId === null, 422, 'A conversation is required.');
                abort_unless($this->participates($by, $conversationId), 403);
                break;
            default:
                break; // avatar: your own
        }
    }

    private function throttle(User $by): void
    {
        $key = 'pulse-media:'.$by->id;
        $max = (int) config('pulse.entities.media.uploads_per_hour', 40);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['file' => 'That is a lot of images in an hour. Try again later.']);
        }

        RateLimiter::hit($key, 3600);
    }

    private function readUpload(UploadedFile $file): string
    {
        $max = (int) config('pulse.entities.media.max_bytes', 5 * 1024 * 1024);
        $tooBig = ValidationException::withMessages(['file' => 'Images can be up to '.round($max / 1048576).' MB.']);

        if (! $file->isValid() || ! is_file((string) $file->getRealPath())) {
            throw ValidationException::withMessages(['file' => 'The upload did not complete. Try again.']);
        }

        if ((int) $file->getSize() > $max) {
            throw $tooBig;
        }

        $bytes = (string) file_get_contents((string) $file->getRealPath());

        if (strlen($bytes) > $max) {
            throw $tooBig;
        }

        return $bytes;
    }

    /** @return array{0: GdImage, 1: string} */
    private function decode(string $bytes): array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        $invalid = ValidationException::withMessages(['file' => 'Upload a JPEG, PNG or WebP image.']);

        if (! in_array($mime, self::ALLOWED_MIME, true) || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw $invalid;
        }

        [$w, $h] = $info;

        if ($w < 16 || $h < 16 || $w * $h > (int) config('pulse.entities.media.max_pixels', 16_000_000)) {
            throw ValidationException::withMessages(['file' => 'That image is too small or too large in pixels.']);
        }

        set_error_handler(static fn () => true);

        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        if (! $image instanceof GdImage) {
            throw $invalid;
        }

        return [$image, $mime];
    }

    /** Applies the EXIF orientation of a JPEG before the metadata is thrown away. */
    private function orient(GdImage $image, UploadedFile $file, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $exif = @exif_read_data((string) $file->getRealPath());
        } catch (Throwable) {
            return $image;
        }

        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /** @return array<string, array{data: string, ext: string, mime: string, width: int, height: int}> */
    private function encodeVariants(GdImage $image): array
    {
        $sizes = (array) config('pulse.entities.media.sizes', ['thumb' => 160, 'medium' => 640, 'large' => 1600]);
        $out = [];

        foreach ($sizes as $name => $max) {
            $out[$name] = $this->encode($this->fit($image, (int) $max));
        }

        return $out;
    }

    private function fit(GdImage $source, int $max): GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $copy = imagecreatetruecolor($nw, $nh);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $copy;
    }

    /** @return array{data: string, ext: string, mime: string, width: int, height: int} */
    private function encode(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($image, null, 82);
            $result = ['ext' => 'webp', 'mime' => 'image/webp'];
        } else {
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            imagejpeg($flat, null, 85);
            imagedestroy($flat);
            $result = ['ext' => 'jpg', 'mime' => 'image/jpeg'];
        }

        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $result + ['data' => $data, 'width' => $width, 'height' => $height];
    }

    /**
     * On the line as it stands now. A person on a line directly counts; a person who answers for a page counts only while
     * they still hold a role that reads that page's lines (a responder who was removed, or an editor, does not).
     */
    private function participates(User $user, int $conversationId): bool
    {
        if (! Schema::hasTable('pulse_conversation_user')) {
            return false;
        }

        $row = DB::table('pulse_conversation_user')->where('pulse_conversation_id', $conversationId)->where('user_id', $user->id)->first();

        if ($row !== null && ($row->entity_id === null || Access::canRespondFor($user, (int) $row->entity_id))) {
            return true;
        }

        $sides = DB::table('pulse_conversations')->where('id', $conversationId)->first(['entity_id', 'initiator_entity_id']);

        foreach ([$sides?->entity_id, $sides?->initiator_entity_id] as $entityId) {
            if ($entityId !== null && Access::canRespondFor($user, (int) $entityId)) {
                return true;
            }
        }

        return false;
    }

    private function inConversation(PulseMedia $media, ?User $viewer): bool
    {
        return $viewer !== null && $media->conversation_id !== null && $this->participates($viewer, (int) $media->conversation_id);
    }

    private function avatarVisible(PulseMedia $media, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $profile = PulseProfile::query()->where('avatar_media_id', $media->id)->first();

        return $profile !== null && $profile->discoverability !== 'anonymous';
    }

    private function entityImageVisible(PulseMedia $media, ?User $viewer): bool
    {
        $entity = $media->entity_id ? PulseEntity::query()->find($media->entity_id) : null;

        if ($entity === null) {
            return false;
        }

        if ($viewer !== null && $entity->memberRole($viewer) !== null) {
            return true;
        }

        if (! $entity->isLive()) {
            return false;
        }

        return match ($media->purpose) {
            'logo', 'cover' => in_array($media->id, [$entity->logo_media_id, $entity->cover_media_id], true),
            'offer' => $entity->offers()->live()->whereJsonContains('media_ids', $media->id)->exists(),
            'gallery' => true,
            default => false,
        };
    }
}
