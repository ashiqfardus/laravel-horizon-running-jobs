<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\HorizonRunningJobs;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Ashiqfardus\HorizonRunningJobs\JobReleaser;
use App\Http\Middleware\QueueMonitorBasicAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__ . '/../Fixtures/QueueMonitorBasicAuth.php';

/**
 * Exercises the pattern the README documents for apps that authenticate
 * their API with tokens: HTTP Basic auth middleware in front of the package
 * routes, and an auth callback that only accepts requests it has passed.
 */
class TokenAuthAppsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Middleware is read when the routes are registered, so it has to be
        // configured before the service provider boots.
        $app['config']->set('horizon-running-jobs.ui.middleware', ['web', QueueMonitorBasicAuth::class]);
        $app['config']->set('horizon-running-jobs.routes.middleware', ['api', 'throttle:60,1', QueueMonitorBasicAuth::class]);
        $app['config']->set('services.queue_monitor', ['user' => 'ops', 'password' => 's3cret']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';

        HorizonRunningJobs::auth(
            fn ($request) => $request->attributes->get('queue_monitor_authenticated') === true
        );
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('queue-monitor-auth:127.0.0.1');
        HorizonRunningJobs::auth(null);
        parent::tearDown();
    }

    public function test_dashboard_asks_for_credentials_when_none_are_sent(): void
    {
        $this->get('/horizon/queue-monitor')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="Queue monitor"');
    }

    /** withBasicAuth() arrived in Laravel 9.48; the package supports 9.0. */
    private function basicAuth(string $user, string $password): static
    {
        return $this->withHeader('Authorization', 'Basic ' . base64_encode("{$user}:{$password}"));
    }

    public function test_readme_example_matches_this_file(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/QueueMonitorBasicAuth.php');
        $code = substr($fixture, strpos($fixture, 'namespace App'));

        $this->assertStringContainsString(
            "// app/Http/Middleware/QueueMonitorBasicAuth.php\n" . $code,
            $readme,
            'README.md and tests/Fixtures/QueueMonitorBasicAuth.php have drifted apart'
        );
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->basicAuth('ops', 'nope')->get('/horizon/queue-monitor')->assertStatus(401);
        $this->basicAuth('ops', 'nope')->getJson('/api/horizon/running-jobs')->assertStatus(401);
    }

    public function test_wrong_user_with_the_right_password_is_rejected(): void
    {
        $this->basicAuth('intruder', 's3cret')->get('/horizon/queue-monitor')->assertStatus(401);
    }

    public function test_repeated_wrong_credentials_are_throttled_but_the_credential_less_challenge_is_not(): void
    {
        // A browser's first request carries no credentials; it must not burn attempts.
        for ($i = 0; $i < 10; $i++) {
            $this->get('/horizon/queue-monitor')->assertStatus(401);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->basicAuth('ops', 'guess-' . $i)->get('/horizon/queue-monitor')->assertStatus(401);
        }

        // Sixth attempt inside the minute: locked out, even with the right password.
        $this->basicAuth('ops', 's3cret')->get('/horizon/queue-monitor/assets/css')->assertStatus(429);
    }

    public function test_release_action_requires_the_credentials_too(): void
    {
        // A valid CSRF token, so the request gets past `web` and reaches the auth layer.
        $form = ['_token' => 'csrf-token', 'job_id' => 'abc'];

        $this->withSession(['_token' => 'csrf-token'])
            ->post('/horizon/queue-monitor/release', $form)
            ->assertStatus(401);

        $releaser = \Mockery::mock(JobReleaser::class);
        $releaser->shouldReceive('findReleasable')->once()->with(['job_id' => 'abc'])->andReturn([]);
        $this->app->instance(JobReleaser::class, $releaser);

        // Past the middleware and the callback: the controller answers.
        $this->withSession(['_token' => 'csrf-token'])
            ->basicAuth('ops', 's3cret')
            ->post('/horizon/queue-monitor/release', $form)
            ->assertStatus(404);
    }

    public function test_correct_credentials_pass_both_the_ui_and_the_api_route_groups(): void
    {
        // Routes that need no Redis, one per route group: the middleware and
        // the callback are what is under test here.
        $this->app->instance(RunningJobsManager::class, new class(['cache' => ['enabled' => false]]) extends RunningJobsManager {
            public function getRunningJobs(?string $serverId = null, bool $showAll = false, ?array $queues = null, bool $orphanedOnly = false): array
            {
                return ['jobs' => [], 'warnings' => [], 'total_count' => 0];
            }
        });

        $this->basicAuth('ops', 's3cret')->get('/horizon/queue-monitor/assets/css')->assertOk();
        $this->basicAuth('ops', 's3cret')->getJson('/api/horizon/running-jobs')->assertOk();
    }

    public function test_an_unset_password_locks_everyone_out(): void
    {
        config(['services.queue_monitor' => ['user' => '', 'password' => '']]);

        $this->basicAuth('', '')->get('/horizon/queue-monitor')->assertStatus(401);
    }

    public function test_callback_denies_requests_that_did_not_pass_the_middleware(): void
    {
        // A request the middleware never marked, e.g. a route it was not applied to.
        $this->assertFalse(HorizonRunningJobs::check(Request::create('/horizon/queue-monitor')));
    }
}
