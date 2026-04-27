<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CrmImpersonationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user();
        if (!$actor) {
            return $next($request);
        }

        $targetUserId = (int) $request->header('X-CRM-Impersonate-User', 0);
        if ($targetUserId <= 0 || $targetUserId === (int) $actor->id) {
            return $next($request);
        }

        if (($actor->role ?? null) !== 'admin') {
            return response()->json([
                'message' => 'Only admins can impersonate CRM users.',
            ], 403);
        }

        $target = User::query()->find($targetUserId);
        if (!$target) {
            return response()->json([
                'message' => 'The requested CRM user could not be found.',
            ], 404);
        }

        if (($target->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Inactive CRM users cannot be opened in impersonation mode.',
            ], 422);
        }

        if (($target->role ?? null) === 'admin') {
            return response()->json([
                'message' => 'Admin accounts cannot be opened in impersonation mode.',
            ], 422);
        }

        $request->attributes->set('crm_impersonator', $actor);
        $request->attributes->set('crm_impersonated_user', $target);
        $request->setUserResolver(static fn () => $target);
        Auth::setUser($target);
        Auth::guard('sanctum')->setUser($target);

        return $next($request);
    }
}
