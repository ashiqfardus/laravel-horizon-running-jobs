<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AssetController extends Controller
{
    /**
     * Serve the package's CSS / JS straight from its `resources/` dir so
     * users don't have to run `vendor:publish` just to see the dashboard.
     *
     * Keys are deliberately `css` / `js` (no `.css` / `.js` suffix) so the
     * generated URLs (`/horizon/queue-monitor/assets/css`) don't match the
     * `location ~* \.(css|js|...)$` block typical in nginx production
     * configs. That block would otherwise short-circuit the request to a
     * static-file lookup that 404s before PHP runs.
     *
     * The whitelist also prevents path traversal: only these two keys are
     * accepted, and the route's `where('file', 'css|js')` enforces it
     * upstream too.
     */
    private const ASSETS = [
        'css' => [
            'path' => 'resources/css/horizon-running-jobs.css',
            'mime' => 'text/css',
        ],
        'js' => [
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
