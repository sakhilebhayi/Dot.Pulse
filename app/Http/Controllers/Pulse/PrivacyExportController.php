<?php

namespace App\Http\Controllers\Pulse;

use App\Http\Controllers\Controller;
use App\Services\Pulse\Lines\AuditLog;
use App\Services\Pulse\Lines\PrivacyExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Download my data": everything Pulse holds about the signed-in person, as one JSON file. Rate limited by the route. */
class PrivacyExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        $data = PrivacyExport::forUser($user);
        AuditLog::record($user, 'privacy.export', null);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, 'pulse-my-data-'.now()->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
