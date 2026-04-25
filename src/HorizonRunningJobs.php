<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Http\Request;

/**
 * Package-wide static configuration entry-point.
 *
 * Currently exposes the authorization callback used by the bundled
 * Authorize middleware to gate the HTTP API. Mirrors the access-control
 * pattern Laravel Horizon uses for its dashboard, but applied per-request.
 */
class HorizonRunningJobs
{
    /**
     * @var (callable(Request): bool)|null
     */
    protected static $authCallback = null;

    /**
     * Register a callback that decides whether the current request is
     * allowed to view running jobs. Called only outside local/testing env
     * and when not running in console.
     *
     * Pass null to clear an existing callback.
     */
    public static function auth(?callable $callback): void
    {
        static::$authCallback = $callback;
    }

    public static function hasAuthCallback(): bool
    {
        return static::$authCallback !== null;
    }

    /**
     * Decide whether the request is authorized to access the API.
     *
     * Smart defaults:
     *   - local / testing env  → allow (frictionless dev)
     *   - otherwise            → use registered callback, deny if none
     *
     * Console-only contexts (artisan commands, queue workers) bypass HTTP
     * middleware entirely and do not call this method.
     */
    public static function check(Request $request): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        if (static::$authCallback === null) {
            return false;
        }

        return (bool) call_user_func(static::$authCallback, $request);
    }
}
