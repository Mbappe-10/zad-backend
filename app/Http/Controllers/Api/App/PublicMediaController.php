<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function show(Request $request): BinaryFileResponse
    {
        $requestedPath = rawurldecode((string) $request->query('path', ''));
        $requestedPath = ltrim(
            str_replace('\\', '/', trim($requestedPath)),
            '/',
        );

        abort_if(
            $requestedPath === '' || str_contains($requestedPath, "\0"),
            404,
        );

        abort_if(
            str_contains($requestedPath, '../') ||
                str_starts_with($requestedPath, '..'),
            404,
        );

        $allowedPrefix = null;

        foreach (['catalog/', 'storage/'] as $prefix) {
            if (str_starts_with($requestedPath, $prefix)) {
                $allowedPrefix = $prefix;
                break;
            }
        }

        abort_if($allowedPrefix === null, 404);

        $allowedRoot = realpath(
            public_path(rtrim($allowedPrefix, '/')),
        );
        $absolutePath = realpath(public_path($requestedPath));

        abort_if(
            $allowedRoot === false ||
                $absolutePath === false ||
                ! is_file($absolutePath),
            404,
        );

        $allowedRootWithSeparator = rtrim(
            $allowedRoot,
            DIRECTORY_SEPARATOR,
        ).DIRECTORY_SEPARATOR;

        abort_unless(
            str_starts_with($absolutePath, $allowedRootWithSeparator),
            404,
        );

        return response()->file($absolutePath, [
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Timing-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
        ]);
    }
}
