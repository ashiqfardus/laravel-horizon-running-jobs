<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Unit;

use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;

class TracksServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('horizon-running-jobs.server_identifier', 'web-01');
    }

    public function test_supervisor_id_is_readable_when_the_constructor_never_initialised_it(): void
    {
        $job = new JobUsingTraitTags;

        $this->assertNull($job->supervisor_id);

        // A job dispatched that way has no fallback identifier in its payload.
        $manager = new RunningJobsManager(['cache' => ['enabled' => false]]);
        $this->assertSame('unknown', $manager->extractServerIdentifier([
            'tags' => [],
            'data' => ['command' => serialize($job)],
        ]));

        $job->initializeServerTracking();
        $this->assertSame('web-01', $manager->extractServerIdentifier([
            'tags' => [],
            'data' => ['command' => serialize($job)],
        ]));
    }

    public function test_initialize_server_tracking_sets_supervisor_id(): void
    {
        $job = new JobUsingTraitTags;
        $job->initializeServerTracking();

        $this->assertSame('web-01', $job->supervisor_id);
    }

    public function test_trait_tags_carry_the_server_tag(): void
    {
        $this->assertSame(
            ['server:web-01', 'environment:testing', 'type:JobUsingTraitTags'],
            (new JobUsingTraitTags)->tags()
        );
    }

    public function test_trait_resolves_the_same_identifier_as_the_package(): void
    {
        config()->set('horizon-running-jobs.server_identifier', null);
        $this->app['env'] = 'production-eu';
        config()->set('horizon.environments', ['production*' => ['eu-worker' => []]]);
        $this->app->forgetInstance(RunningJobsManager::class);

        $this->assertSame(
            app(RunningJobsManager::class)->getServerIdentifier(),
            substr((new JobUsingTraitTags)->tags()[0], strlen('server:'))
        );
        $this->assertSame('server:eu-worker', (new JobUsingTraitTags)->tags()[0]);
    }

    public function test_job_with_its_own_tags_keeps_the_server_tag_by_merging_server_tags(): void
    {
        $tags = (new JobWithOwnTags)->tags();

        $this->assertSame(['order:42', 'server:web-01'], $tags);

        // Through Horizon's own tag resolution, as on dispatch.
        $this->assertSame($tags, \Laravel\Horizon\Tags::for(new JobWithOwnTags));

        $manager = new RunningJobsManager(['cache' => ['enabled' => false]]);
        $this->assertSame('web-01', $manager->extractServerIdentifier(['tags' => $tags]));
    }
}

class JobUsingTraitTags
{
    use TracksServer;
}

class JobWithOwnTags
{
    use TracksServer;

    public function tags(): array
    {
        return ['order:42', ...$this->serverTags()];
    }
}
