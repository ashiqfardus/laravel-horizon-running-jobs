<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests\Feature;

use Ashiqfardus\HorizonRunningJobs\Http\Middleware\Authorize;
use Ashiqfardus\HorizonRunningJobs\Tests\TestCase;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteRegistrationTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function expectedRouteNames(): array
    {
        return [
            'running-jobs index' => ['horizon.running-jobs.index'],
            'running-jobs stats' => ['horizon.running-jobs.stats'],
            'supervisors index' => ['horizon.supervisors.index'],
        ];
    }

    #[DataProvider('expectedRouteNames')]
    public function test_named_route_is_registered(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Expected named route '{$name}' to be registered.");
    }

    #[DataProvider('expectedRouteNames')]
    public function test_named_route_has_authorize_middleware(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route);

        $this->assertContains(
            Authorize::class,
            $route->gatherMiddleware(),
            "Expected route '{$name}' to be guarded by the package's Authorize middleware."
        );
    }

    #[DataProvider('expectedRouteNames')]
    public function test_named_route_has_throttle_middleware(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route);

        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle'));

        $this->assertTrue(
            $hasThrottle,
            "Expected route '{$name}' to apply a throttle middleware."
        );
    }

    public function test_routes_use_the_configured_prefix(): void
    {
        $route = Route::getRoutes()->getByName('horizon.running-jobs.index');

        $this->assertSame('api/horizon/running-jobs', $route->uri());
    }

    public function test_supervisors_route_uri_is_horizon_supervisors(): void
    {
        $route = Route::getRoutes()->getByName('horizon.supervisors.index');

        $this->assertSame('api/horizon/supervisors', $route->uri());
    }
}
