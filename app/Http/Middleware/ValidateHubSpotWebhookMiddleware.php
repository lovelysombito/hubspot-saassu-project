<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ValidateHubSpotWebhookMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        Log::info("ValidateHubSpotWebhookMiddleware.handle - Verifying HubSpot Webhook.");
        $hubspot_signature = $request->header('X-Hubspot-Signature');
        if (!$hubspot_signature) {
            Log::info("ValidateHubSpotWebhookMiddleware.handle - Hubspot signature does not exists.");
            return response('Hubspot signature does not exists', 403);
        }

        $client_secret = env('HUBSPOT_CLIENT_SECRET');
        $request_body = $request->getContent();
        $source_string = $client_secret . $request_body;
        $computed_signature = hash('sha256', $source_string, false);

        if ($hubspot_signature !== $computed_signature) {
            Log::warning("ValidateHubSpotWebhookMiddleware.handle - Unauthorised request from HubSpot webhook");
            return response('Unauthorised', 401);
        }

        return $next($request);
    }
}
