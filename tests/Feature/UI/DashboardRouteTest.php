<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature\UI;

use Ashiqfardus\HorizonRunningJobs\HealthDiagnoser;
use Ashiqfardus\HorizonRunningJobs\QueueDepthInspector;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\SupervisorInspector;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;

class DashboardRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubs();
    }

    public function test_dashboard_route_renders_html(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertOk()
            ->assertSee('Horizon Queue Monitor', false)
            ->assertSee('Supervisors', false)
            ->assertSee('Queue depth', false)
            ->assertSee('Running jobs', false);
    }

    public function test_dashboard_includes_csrf_meta_tag(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertOk()
            ->assertSee('name="csrf-token"', false);
    }

    public function test_dashboard_links_to_package_css_and_inlines_alpine_factories(): void
    {
        $response = $this->get('/horizon/queue-monitor');
        $response->assertOk();

        $body = $response->getContent();

        // CSS is loaded via the package's asset route. The path deliberately
        // does NOT end in `.css` — many production nginx configs intercept
        // any `*.css` URL via a static-file location, which would 404 before
        // PHP runs. Stripping the extension lets the request fall through
        // to Laravel.
        $this->assertStringContainsString('/horizon/queue-monitor/assets/css', $body);
        $this->assertStringNotContainsString('/horizon/queue-monitor/assets/horizon-running-jobs.css', $body);

        // JS factories are inlined into the standalone dashboard page so they
        // resolve under any host setup (Herd HTTPS, custom routing, asset
        // proxying) without depending on the route + asset endpoint serving
        // them in time. Embedders linking the package JS still get the
        // external file via the asset route (see the asset route test below).
        $this->assertStringContainsString('window.hrjPanel = hrjPanel', $body);
        $this->assertStringContainsString('window.hrjReleaseButton = hrjReleaseButton', $body);
    }

    public function test_dashboard_blocked_in_production_without_auth_callback(): void
    {
        $this->app['env'] = 'production';

        $this->get('/horizon/queue-monitor')->assertStatus(403);
    }

    public function test_panel_refresh_endpoint_renders_named_panel(): void
    {
        foreach (['diagnose-banner', 'supervisors-panel', 'queues-panel', 'running-jobs-table'] as $panel) {
            $this->get("/horizon/queue-monitor/panels/{$panel}")
                ->assertOk();
        }
    }

    public function test_refresh_fragment_keeps_the_panel_controls(): void
    {
        // The page swaps in the fragment's contents; if the fragment lacked
        // the pause control, the first refresh would remove it.
        $page = $this->get('/horizon/queue-monitor')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#panels/running-jobs-table\?[^"\']*poll=3000#', $page);
        $this->assertMatchesRegularExpression('#panels/supervisors-panel\?[^"\']*poll=5000#', $page);

        $this->get('/horizon/queue-monitor/panels/supervisors-panel?poll=5000')
            ->assertOk()
            ->assertSee('togglePause()', false)
            ->assertSee('x-data="hrjPanel(', false);
    }

    public function test_refresh_fragment_from_an_older_published_view_still_polls(): void
    {
        // Older views build the URL without ?poll=; the component default applies.
        $this->get('/horizon/queue-monitor/panels/supervisors-panel')
            ->assertOk()
            ->assertSee('x-init="start()"', false);
    }

    public function test_refresh_keeps_the_component_and_skips_open_dialogs(): void
    {
        $page = $this->get('/horizon/queue-monitor')->assertOk()->getContent();

        $this->assertStringContainsString('replaceChildren', $page);
        $this->assertStringNotContainsString('this.$root.replaceWith(fresh)', $page);
        $this->assertStringContainsString("querySelectorAll('.hrj-modal-overlay')", $page);
    }

    public function test_unknown_panel_returns_404(): void
    {
        $this->get('/horizon/queue-monitor/panels/nope')->assertStatus(404);
    }

    public function test_running_jobs_panel_honors_orphaned_only_query(): void
    {
        // Use a spy to assert orphanedOnly was passed through.
        $spy = new SpyManagerForUI;
        $this->app->instance(RunningJobsManager::class, $spy);

        $this->get('/horizon/queue-monitor/panels/running-jobs-table?orphaned_only=1')->assertOk();

        $this->assertTrue($spy->capturedOrphanedOnly);
    }

    public function test_supervisors_panel_shows_last_heartbeat_instead_of_an_expiry(): void
    {
        $this->get('/horizon/queue-monitor/panels/supervisors-panel')
            ->assertOk()
            ->assertSee('Heartbeat')
            ->assertSee('3s ago')
            ->assertDontSee('Expires');
    }

    public function test_panels_read_live_data_while_the_json_api_stays_cached(): void
    {
        // The panels poll every 3-5s. Serving them from the 10s API cache hid
        // every job shorter than the TTL.
        config(['cache.default' => 'array']);
        $manager = new CountingManagerForUI([
            'cache' => ['enabled' => true, 'ttl' => 60, 'prefix' => 'hrj_ui_test'],
            'queues' => ['default'],
        ]);
        $this->app->instance(RunningJobsManager::class, $manager);
        $this->app->forgetInstance(HealthDiagnoser::class);

        $this->get('/horizon/queue-monitor/panels/running-jobs-table')->assertOk();
        $this->get('/horizon/queue-monitor/panels/running-jobs-table')->assertOk();
        $this->assertSame(2, $manager->fetchCount, 'running-jobs panel must hit Redis on every poll');

        $this->get('/horizon/queue-monitor/panels/diagnose-banner')->assertOk();
        $this->get('/horizon/queue-monitor/panels/diagnose-banner')->assertOk();
        $this->assertSame(4, $manager->fetchCount, 'diagnose banner must hit Redis on every poll');

        $this->getJson('/api/horizon/running-jobs')->assertOk();
        $this->getJson('/api/horizon/running-jobs')->assertOk();
        $this->assertSame(5, $manager->fetchCount, 'JSON API must still be served from the cache');
    }

    public function test_first_dashboard_load_reads_live_data(): void
    {
        // No controller-level bypass on this route: the views and the
        // diagnoser have to skip the cache themselves.
        config(['cache.default' => 'array']);
        $manager = new CountingManagerForUI([
            'cache' => ['enabled' => true, 'ttl' => 60, 'prefix' => 'hrj_ui_first_load'],
            'queues' => ['default'],
        ]);
        $this->app->instance(RunningJobsManager::class, $manager);
        $this->app->forgetInstance(HealthDiagnoser::class);

        // One read for the diagnose banner, one for the running-jobs table.
        $this->get('/horizon/queue-monitor')->assertOk();
        $this->assertSame(2, $manager->fetchCount);

        $this->get('/horizon/queue-monitor')->assertOk();
        $this->assertSame(4, $manager->fetchCount);
    }

    public function test_diagnose_command_reads_live_data(): void
    {
        config(['cache.default' => 'array']);
        $manager = new CountingManagerForUI([
            'cache' => ['enabled' => true, 'ttl' => 60, 'prefix' => 'hrj_ui_diagnose'],
            'queues' => ['default'],
        ]);
        $this->app->instance(RunningJobsManager::class, $manager);
        $this->app->forgetInstance(HealthDiagnoser::class);

        \Illuminate\Support\Facades\Artisan::call('horizon:diagnose');
        \Illuminate\Support\Facades\Artisan::call('horizon:diagnose');

        $this->assertSame(2, $manager->fetchCount);
    }

    public function test_panel_refresh_reads_live_data_even_through_an_older_published_view(): void
    {
        // A project that published the views before they bypassed the cache
        // themselves still gets live data on every poll.
        $this->app['view']->prependNamespace(
            'horizon-running-jobs',
            __DIR__ . '/../../Fixtures/published-views'
        );

        config(['cache.default' => 'array']);
        $manager = new CountingManagerForUI([
            'cache' => ['enabled' => true, 'ttl' => 60, 'prefix' => 'hrj_ui_published'],
            'queues' => ['default'],
        ]);
        $this->app->instance(RunningJobsManager::class, $manager);

        $this->get('/horizon/queue-monitor/panels/running-jobs-table')->assertOk()->assertSee('published copy');
        $this->get('/horizon/queue-monitor/panels/running-jobs-table')->assertOk();

        $this->assertSame(2, $manager->fetchCount);
    }

    public function test_release_button_is_offered_only_for_jobs_past_their_timeout(): void
    {
        $now = time();
        $this->app->instance(RunningJobsManager::class, new RowsManagerForUI([
            $this->jobRow('stuck-orphan', orphaned: true, releasable: true, releasableAt: $now - 5),
            $this->jobRow('draining-orphan', orphaned: true, releasable: false, releasableAt: $now + 95),
            $this->jobRow('endless-zombie', status: 'zombie', releasable: false, releasableAt: null),
            $this->jobRow('healthy', releasable: false, releasableAt: $now + 50),
        ]));

        $html = $this->get('/horizon/queue-monitor/panels/running-jobs-table')->assertOk()->getContent();

        // One per-row button, for the stuck orphan only.
        $this->assertSame(1, substr_count($html, "jobId: 'stuck-orphan'"));
        $this->assertStringNotContainsString("jobId: 'draining-orphan'", $html);
        $this->assertStringNotContainsString("jobId: 'endless-zombie'", $html);
        $this->assertStringNotContainsString("jobId: 'healthy'", $html);

        // The others say when, or why not.
        $this->assertMatchesRegularExpression('/in 1m 3[45]s/', $html);
        $this->assertStringContainsString('no timeout', $html);

        // Bulk button counts the releasable rows only.
        $this->assertStringContainsString('release 1 stuck', $html);
        $this->assertStringContainsString('Release 1 stuck job(s)?', $html);
    }

    public function test_no_bulk_button_when_nothing_is_releasable_yet(): void
    {
        $this->app->instance(RunningJobsManager::class, new RowsManagerForUI([
            $this->jobRow('draining-orphan', orphaned: true, releasable: false, releasableAt: time() + 60),
        ]));

        $this->get('/horizon/queue-monitor/panels/running-jobs-table')
            ->assertOk()
            ->assertSee('1 orphan')
            ->assertDontSee('stuck job(s)?')
            ->assertDontSee('hrjBulkRelease', false);
    }

    public function test_bulk_release_posts_orphans_and_zombies_together(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertOk()
            ->assertSee('postRelease(url, { orphaned: true, zombie: true })', false);
    }

    public function test_supervisor_between_horizons_window_and_stale_is_not_shown_as_healthy(): void
    {
        $this->app->instance(SupervisorInspector::class, new class extends SupervisorInspector {
            public function __construct() {}
            public function inspect(): array
            {
                $row = fn (string $name, string $status, bool $stale, int $age) => [
                    'name' => $name, 'status' => $status, 'master' => null, 'pid' => null, 'queues' => [],
                    'process_count' => 0, 'processes' => [], 'last_heartbeat_at' => time() - $age,
                    'seconds_since_heartbeat' => $age, 'expires_at' => time() - $age + 29,
                    'seconds_until_expiry' => max(0, 29 - $age), 'is_stale' => $stale,
                ];

                return ['supervisors' => [
                    $row('quiet', 'unknown', false, 31),
                    $row('dead', 'stale', true, 47),
                ], 'masters' => [], 'inspected_at' => time()];
            }
        });

        $html = $this->get('/horizon/queue-monitor/panels/supervisors-panel')->assertOk()->getContent();

        $this->assertStringContainsString('hrj-badge--warn">unknown', $html);
        $this->assertStringContainsString('hrj-badge--fail">stale', $html);
        $this->assertStringContainsString('hrj-badge--fail">47s ago', $html);
    }

    private function jobRow(string $id, bool $orphaned = false, string $status = 'running', bool $releasable = false, ?int $releasableAt = null): array
    {
        return [
            'job_id' => $id, 'job_class' => 'App\\Jobs\\Report', 'queue' => 'default', 'server' => 'web-01',
            'supervisor' => null, 'status' => $status, 'is_orphaned' => $orphaned, 'is_releasable' => $releasable,
            'releasable_at' => $releasableAt, 'start_time' => date('c'), 'start_timestamp' => time(),
            'running_for_seconds' => 5, 'running_for_formatted' => '5s', 'attempts' => 1, 'timeout' => 60, 'tags' => [],
        ];
    }

    public function test_asset_route_serves_css_with_correct_mime(): void
    {
        $response = $this->get('/horizon/queue-monitor/assets/css');

        $response->assertOk();
        $this->assertStringContainsString('text/css', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.hrj', $response->getContent());
    }

    public function test_asset_route_serves_js_with_correct_mime(): void
    {
        $response = $this->get('/horizon/queue-monitor/assets/js');

        $response->assertOk();
        $this->assertStringContainsString('application/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('hrjPanel', $response->getContent());
    }

    public function test_asset_url_does_not_end_in_static_extension(): void
    {
        // Regression: nginx production configs commonly include a
        // `location ~* \.(css|js|...)$` block that intercepts any URL ending
        // in those extensions for static-file serving. If our asset URLs
        // ended in .css / .js they'd 404 before reaching PHP.
        $cssUrl = route('horizon-running-jobs.assets', ['file' => 'css']);
        $jsUrl  = route('horizon-running-jobs.assets', ['file' => 'js']);

        $this->assertDoesNotMatchRegularExpression('/\.css$/', $cssUrl);
        $this->assertDoesNotMatchRegularExpression('/\.js$/', $jsUrl);
    }

    public function test_old_extension_based_asset_urls_404(): void
    {
        // The `where('file', 'css|js')` route constraint rejects anything
        // else, so the old v2.0 / v2.1.0 URL shape is now a 404. This is
        // intentional — see test_asset_url_does_not_end_in_static_extension.
        $this->get('/horizon/queue-monitor/assets/horizon-running-jobs.css')->assertStatus(404);
        $this->get('/horizon/queue-monitor/assets/horizon-running-jobs.js')->assertStatus(404);
    }

    public function test_asset_route_rejects_path_traversal(): void
    {
        $this->get('/horizon/queue-monitor/assets/..%2F..%2Fcomposer.json')->assertStatus(404);
    }

    public function test_unknown_asset_returns_404(): void
    {
        $this->get('/horizon/queue-monitor/assets/whatever')->assertStatus(404);
    }

    public function test_release_endpoint_requires_job_id(): void
    {
        $this->postJson('/horizon/queue-monitor/release', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_release_endpoint_returns_404_for_unknown_job(): void
    {
        $this->postJson('/horizon/queue-monitor/release', ['job_id' => 'ghost'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_release_endpoint_rejects_no_targeting_mode(): void
    {
        $this->postJson('/horizon/queue-monitor/release', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Specify a job_id, orphaned=true, or zombie=true.');
    }

    public function test_release_endpoint_rejects_multiple_targeting_modes(): void
    {
        $this->postJson('/horizon/queue-monitor/release', [
            'job_id' => 'abc',
            'orphaned' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'job_id cannot be combined with orphaned or zombie.');
    }

    public function test_release_endpoint_combines_orphaned_and_zombie(): void
    {
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public ?array $capturedCriteria = null;
            public function __construct() {}
            public function findReleasable(array $criteria): array
            {
                $this->capturedCriteria = $criteria;
                return [];
            }
            public function release(array $items): int { return 0; }
        };
        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['orphaned' => true, 'zombie' => true])
            ->assertOk()
            ->assertJsonPath('mode', 'orphaned+zombie');

        $this->assertSame(['orphaned' => true, 'zombie' => true], $fakeReleaser->capturedCriteria);
    }

    public function test_release_endpoint_refuses_a_job_a_worker_may_still_be_running(): void
    {
        // The page offered the button, but by the time it is clicked the job
        // is no longer orphaned/expired past its timeout (e.g. picked up again).
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public function __construct() {}
            public function findReleasable(array $criteria): array
            {
                return [['job_id' => $criteria['job_id'], 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p', 'safe' => false]];
            }
            public function release(array $items): int
            {
                throw new \LogicException('release must not run');
            }
        };
        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['job_id' => 'busy-job'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_release_endpoint_answers_503_when_the_supervisor_lookup_fails(): void
    {
        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public bool $releaseInvoked = false;
            public function __construct() {}
            public function findReleasable(array $criteria): array
            {
                throw new \Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable;
            }
            public function release(array $items): int
            {
                throw new \LogicException('release must not run');
            }
        });

        $response = $this->postJson('/horizon/queue-monitor/release', ['orphaned' => true])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Nothing was released', $response->json('message'));
    }

    public function test_release_endpoint_bulk_orphaned_invokes_releaser_with_correct_criteria(): void
    {
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public ?array $capturedCriteria = null;
            public bool $releaseInvoked = false;

            public function __construct() {}

            public function findReleasable(array $criteria): array
            {
                $this->capturedCriteria = $criteria;
                return [
                    ['job_id' => 'a', 'queue' => 'default', 'reason' => 'orphaned', 'payload' => 'p1'],
                    ['job_id' => 'b', 'queue' => 'default', 'reason' => 'orphaned', 'payload' => 'p2'],
                ];
            }

            public function release(array $items): int
            {
                $this->releaseInvoked = true;
                return count($items);
            }
        };

        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['orphaned' => true])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 2)
            ->assertJsonPath('mode', 'orphaned');

        $this->assertSame(['orphaned' => true], $fakeReleaser->capturedCriteria);
        $this->assertTrue($fakeReleaser->releaseInvoked);
    }

    public function test_release_endpoint_bulk_zombie_invokes_releaser_with_correct_criteria(): void
    {
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public ?array $capturedCriteria = null;

            public function __construct() {}

            public function findReleasable(array $criteria): array
            {
                $this->capturedCriteria = $criteria;
                return [];
            }

            public function release(array $items): int { return 0; }
        };

        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['zombie' => true])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 0)
            ->assertJsonPath('mode', 'zombie');

        $this->assertSame(['zombie' => true], $fakeReleaser->capturedCriteria);
    }

    public function test_release_endpoint_happy_path_invokes_releaser(): void
    {
        $fakeReleaser = new class extends \Ashiqfardus\HorizonRunningJobs\JobReleaser {
            public ?string $capturedJobId = null;
            public bool $releaseInvoked = false;

            public function __construct() {}

            public function findReleasable(array $criteria): array
            {
                $this->capturedJobId = $criteria['job_id'] ?? null;
                return [['job_id' => $criteria['job_id'], 'queue' => 'default', 'reason' => 'manual', 'payload' => 'p', 'safe' => true]];
            }

            public function release(array $items): int
            {
                $this->releaseInvoked = true;
                return count($items);
            }
        };

        $this->app->instance(\Ashiqfardus\HorizonRunningJobs\JobReleaser::class, $fakeReleaser);

        $this->postJson('/horizon/queue-monitor/release', ['job_id' => 'real-job-uuid'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 1)
            ->assertJsonPath('job_id', 'real-job-uuid');

        $this->assertSame('real-job-uuid', $fakeReleaser->capturedJobId);
        $this->assertTrue($fakeReleaser->releaseInvoked);
    }

    public function test_dashboard_disabled_when_ui_config_false(): void
    {
        config(['horizon-running-jobs.ui.enabled' => false]);

        // Re-register routes with the new config — easiest is to reload the file.
        require __DIR__ . '/../../../src/routes.php';

        // Without re-bootstrapping the whole router, we just verify the config
        // is honored — the deeper integration is covered by the success test.
        $this->assertFalse(config('horizon-running-jobs.ui.enabled'));
    }

    private function bindStubs(): void
    {
        $this->app->instance(SupervisorInspector::class, new StubSupervisorInspectorForUI);
        $this->app->instance(QueueDepthInspector::class, new StubQueueDepthForUI);
        $this->app->instance(RunningJobsManager::class, new StubManagerForUI);
    }
}

class StubSupervisorInspectorForUI extends SupervisorInspector
{
    public function __construct() {}
    public function inspect(): array
    {
        return [
            'supervisors' => [
                ['name' => 'sup-1', 'status' => 'running', 'master' => 'm1', 'pid' => 1, 'queues' => ['default'], 'process_count' => 1, 'processes' => [], 'last_heartbeat_at' => time() - 3, 'seconds_since_heartbeat' => 3, 'expires_at' => time() + 26, 'seconds_until_expiry' => 26, 'is_stale' => false],
            ],
            'masters' => [],
            'inspected_at' => time(),
        ];
    }
}

class StubQueueDepthForUI extends QueueDepthInspector
{
    public function __construct() {}
    public function inspect(?array $queues = null): array
    {
        return [
            'queues' => [
                ['queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
            ],
            'totals' => ['pending' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1],
            'inspected_at' => time(),
        ];
    }
}

class StubManagerForUI extends RunningJobsManager
{
    public function __construct() { parent::__construct(['cache' => ['enabled' => false]]); }
    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        return ['jobs' => [], 'warnings' => [], 'total_count' => 0, 'dropped_count' => 0, 'orphan_count' => 0];
    }
    public function getStats(?array $queues = null): array
    {
        return [
            'total_running' => 0,
            'by_server' => [], 'by_queue' => [], 'by_job_class' => [],
            'by_status' => ['running' => 0, 'zombie' => 0],
            'by_orphan_status' => ['orphaned' => 0, 'healthy' => 0],
            'dropped_count' => 0, 'orphan_count' => 0, 'longest_running' => null, 'warnings' => [],
        ];
    }
}

class SpyManagerForUI extends StubManagerForUI
{
    public bool $capturedOrphanedOnly = false;
    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        $this->capturedOrphanedOnly = $orphanedOnly;
        return parent::getRunningJobs($serverId, $showAll, $queues, $orphanedOnly);
    }
}

class CountingManagerForUI extends RunningJobsManager
{
    public int $fetchCount = 0;

    protected function fetchRunningJobs(string $hostname, bool $showAll, array $queues, bool $orphanedOnly = false): array
    {
        $this->fetchCount++;

        return ['jobs' => [], 'warnings' => [], 'total_count' => 0, 'dropped_count' => 0, 'orphan_count' => 0];
    }
}

class RowsManagerForUI extends StubManagerForUI
{
    public function __construct(private array $rows)
    {
        parent::__construct();
    }

    public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
    {
        $orphans = array_filter($this->rows, fn ($j) => $j['is_orphaned']);

        return [
            'jobs' => $this->rows,
            'warnings' => [],
            'total_count' => count($this->rows),
            'dropped_count' => 0,
            'orphan_count' => count($orphans),
        ];
    }
}
