<?php

namespace App\Http\Controllers;

use App\Jobs\WebhookEventJob;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HubSpotController extends Controller
{
    public function handleWebhook(Request $request)
    {
        try {

            $events = json_decode($request->getContent());
            Log::info("HubSpotController.handleWebhook- Webhook data ", [ 'req' => [ "events" => $events ]]);

            if(!$events){
                Log::warning("HubSpotController.handleWebhook- No events to process");
                return false;
            }

            WebhookEventJob::dispatch($events);

            Log::info("HubSpotController.handleWebhook - Webhook successfully processed.");
            return response()->json([
                'message'=> 'HubSpotController.handleWebhook - Webhook successfully processed.'
            ], 200);

        } catch (Exception $e) {
            Log::error("HubSpotController.handleWebhook - {$e->getMessage()}");
            throw new Exception($e->getMessage());
        }
    }
}
