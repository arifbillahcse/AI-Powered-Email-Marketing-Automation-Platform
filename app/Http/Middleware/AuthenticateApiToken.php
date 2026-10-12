<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization: Bearer <API key>. Resolves the key's workspace and checks
 * the ability the route needs (read or write). Errors are JSON.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findByPlain($plain) : null;

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Invalid or expired API key.'], 401);
        }

        $workspace = Workspace::query()->find($token->workspace_id);

        if (! $workspace || $workspace->isSuspended()) {
            return response()->json(['message' => 'This workspace is suspended.'], 403);
        }

        if (! app(Entitlements::class)->allowsModule($workspace, 'api')) {
            return response()->json(['message' => 'API access isn\'t included in your plan.'], 403);
        }

        if (! $token->allows($ability)) {
            return response()->json(['message' => "This API key can't {$ability}."], 403);
        }

        // At most one write per minute per key.
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_token', $token);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
