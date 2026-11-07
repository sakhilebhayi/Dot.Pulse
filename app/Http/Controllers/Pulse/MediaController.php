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

        $disk = Storage::disk($mediaFile->disk);
        abort_unless($disk->exists($variant['path']), 404);

        // Public images may be cached by shared caches for a short time; everything else only by the person's browser.
        $public = $mediaFile->purpose !== 'message' && $mediaFile->purpose !== 'avatar' && $request->user() === null;

        return $disk->response($variant['path'], null, [
            'Content-Type' => $mediaFile->mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => $public ? 'public, max-age=300' : 'private, max-age=300',
        ]);
    }
}
