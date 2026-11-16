<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ($user->role !== UserRole::Admin && ! in_array($user->role->value, $roles, true))) {
            return response()->json(['message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}