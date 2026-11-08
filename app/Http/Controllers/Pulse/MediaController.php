<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Models\PulseMedia;
use App\Services\Pulse\Entities\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Streams a stored image after MediaService::canView says yes. A refusal is a 404, so existence is not revealed. */
class MediaController extends Controller
{
    public function __invoke(Request $request, MediaService $media, PulseMedia $mediaFile, string $size = 'medium'): Response
    {
        $variant = $mediaFile->variant($size);

        abort_if($variant === null, 404);
        abort_unless($media->canView($mediaFile, $request->user()), 404);

        // A variant never changes once written (a replaced image gets a new id), so the tag is cheap and exact. A
        // revalidation answers 304 after the same authorisation check, without touching the disk.
        $etag = '"'.$mediaFile->id.'-'.$size.'-'.($variant['bytes'] ?? 0).'"';
        $public = $mediaFile->purpose !== 'message' && $mediaFile->purpose !== 'avatar';
        $cache = $public
            ? ($request->user() === null ? 'public' : 'private').', max-age=604800, immutable'
            : 'private, max-age=300';
        $headers = ['Cache-Control' => $cache, 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, $headers);
        }

        $disk = Storage::disk($mediaFile->disk);
        abort_unless($disk->exists($variant['path']), 404);

        return $disk->response($variant['path'], null, $headers + [
            'Content-Type' => $mediaFile->mime,
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
