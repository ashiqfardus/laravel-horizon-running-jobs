<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AssetController extends Controller
{
    /**
     * Serve the package's CSS / JS straight from its `resources/` dir so
     * users don't have to run `vendor:publish` just to see the dashboard.
     * The whitelist prevents path traversal.
     */
    private const ASSETS = [
        'horizon-running-jobs.css' => [
            'path' => 'resources/css/horizon-running-jobs.css',
            'mime' => 'text/css',
        ],
        'horizon-running-jobs.js' => [
            'path' => 'resources/js/horizon-running-jobs.js',
            'mime' => 'application/javascript',
        ],
    ];

    public function show(string $file): Response
    {
        if (! isset(self::ASSETS[$file])) {
            return response('Asset not found', 404);
        }

        $absolutePath = dirname(__DIR__, 2) . '/' . self::ASSETS[$file]['path'];

        if (! is_file($absolutePath)) {
            return response('Asset missing on disk', 500);
        }

        return response(
            (string) file_get_contents($absolutePath),
            200,
            [
                'Content-Type' => self::ASSETS[$file]['mime'],
                'Cache-Control' => 'no-cache, must-revalidate',
            ]
        );
    }
}
