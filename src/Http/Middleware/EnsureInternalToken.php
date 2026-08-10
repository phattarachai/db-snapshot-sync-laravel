<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal()) {
            abort(404);
        }

        $expected = config('db-snapshot-sync.token');
        $provided = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || ! is_string($provided)) {
            abort(401);
        }

        if (! hash_equals($expected, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
