<?php

namespace App\Jobs;

use App\Models\Item;
use App\Workers\HubSpotWorker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateSaasuItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $stored_item, $item;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $stored_item, $item)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
        $this->stored_item = $stored_item;
        $this->item = $item;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $user = $this->user;
            $stored_item = $this->stored_item;
            $item = $this->item;
            $item_data = [];

            $item_data['properties']['name'] = $item->Code ?? "";
            $item_data['properties']['hs_sku'] = $item->Code ?? "";
            $item_data['properties']['item_id'] = $item->Id ?? "";
            $item_data['properties']['description'] = $item->Description ?? "";
            $item_data['properties']['price'] = $item->SellingPrice ?? 0;
            $item_data['properties']['tax_code'] = "G1";
            $item_data['properties']['product_amount'] = $item->SellingPrice ?? 0;

            Log::info("UpdateSaasuItemJob.handle - Item id <{$item->Id}>.", [ "req" => [ "item_data" => $item_data ] ]);

            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $update_hs_product_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->updateProductById($stored_item->hubspot_product_id, $item_data));

            if($update_hs_product_request){
                Log::info("UpdateSaasuItemJob.handle - Item id <{$item->Id}> is successfully updated to HubSpot.", [
                    "req" => [
                        "saasu_item_id" => $item->Id,
                        "hubspot_product_id" => $update_hs_product_request['id'],
                    ]
                ]);
            }

        } catch (Exception $e) {
            Log::error("UpdateSaasuItemJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);

            if(str_contains($e->getMessage(), "resource not found")){
                $create_new_hs_product_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createProduct($item_data));
                if($create_new_hs_product_request){
                    $stored_item->update([
                        "hubspot_product_id" => $create_new_hs_product_request["id"],
                    ]);
                    if($stored_item) {
                        Log::info("UpdateSaasuItemJob.handle - Item id <{$item->Id}> is successfully added to the DB.");
                    }
                    Log::info("UpdateSaasuItemJob.handle - Item id <{$item->Id}> is successfully synced to HubSpot.", [
                        "req" => [
                            "saasu_item_id" => $item->Id,
                            "hubspot_product_id" => $create_new_hs_product_request['id'],
                        ]
                    ]);
                }
            }
        }
    }
}
