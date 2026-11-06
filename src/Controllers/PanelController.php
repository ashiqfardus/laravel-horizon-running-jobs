<?php

namespace Ashiqfardus\HorizonRunningJobs\Controllers;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
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

    public function show(Request $request, RunningJobsManager $manager, string $panel): Response
    {
        if (! in_array($panel, self::PANELS, true)) {
            return response('Unknown panel', 404);
        }

        // Render the fragment exactly like the panel on the page, controls
        // included: the page keeps its own component and timer and only
        // swaps in the fragment's contents. Without ?poll= (URLs from older
        // published views) the component's own default applies.
        $data = $request->has('poll') ? ['poll' => max(0, (int) $request->query('poll'))] : [];

        if ($panel === 'running-jobs-table') {
            $data['orphanedOnly'] = (bool) $request->query('orphaned_only');
            $data['allowRelease'] = (bool) $request->query('allow_release', true);
        }

        // Panels poll every few seconds, faster than the API cache TTL, so
        // they render against live data. Rendering inside the callback also
        // covers copies of the views published before they bypassed the
        // cache themselves.
        return response($manager->withoutCache(
            fn () => view("horizon-running-jobs::components.{$panel}", $data)->render()
        ));
    }
}
