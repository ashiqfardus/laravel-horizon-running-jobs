<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PanelController extends Controller
{
    /**
     * Whitelist of panel components we'll re-render via the AJAX refresh
     * endpoint. Anything not on this list returns 404 — no path for the
     * client to render arbitrary Blade templates.
     */
    private const PANELS = [
        'diagnose-banner',
        'supervisors-panel',
        'queues-panel',
        'running-jobs-table',
    ];

    public function show(Request $request, string $panel): View|Response
    {
        if (! in_array($panel, self::PANELS, true)) {
            return response('Unknown panel', 404);
        }

        $data = ['poll' => 0]; // poll=0 in the refresh fragment so it doesn't double-poll

        if ($panel === 'running-jobs-table') {
            $data['orphanedOnly'] = (bool) $request->query('orphaned_only');
            $data['allowRelease'] = (bool) $request->query('allow_release', true);
        }

        return view("horizon-running-jobs::components.{$panel}", $data);
    }
}
