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

class CreateSaasuItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $user, $item;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $item)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
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
            $item = $this->item;
            $item_data = [];
            $item_code = $item->Code ?? "";

            $item_data['properties']['name'] = $item_code;
            $item_data['properties']['hs_sku'] = $item_code;
            $item_data['properties']['item_id'] = $item->Id ?? "";
            $item_data['properties']['description'] = $item->Description ?? "";
            $item_data['properties']['price'] = $item->SellingPrice ?? 0;
            $item_data['properties']['tax_code'] = "G1";
            $item_data['properties']['product_amount'] = $item->SellingPrice ?? 0;

            Log::info("CreateSaasuItemJob.handle - Item id <{$item->Id}>.", [ "req" => [ "item_data" => $item_data ] ]);

            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $create_new_hs_product_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createProduct($item_data));

            if($create_new_hs_product_request){
                $store_item = Item::create([
                    "saasu_item_id" => $item->Id,
                    "hubspot_product_id" => $create_new_hs_product_request["id"],
                ]);
                if($store_item) {
                    Log::info("CreateSaasuItemJob.handle - Item id <{$item->Id}> is successfully added to the DB.");
                }
                Log::info("CreateSaasuItemJob.handle - Item id <{$item->Id}> is successfully created to HubSpot.", [
                    "req" => [
                        "saasu_item_id" => $item->Id,
                        "hubspot_product_id" => $create_new_hs_product_request['id'],
                    ]
                ]);
            }

        } catch (Exception $e) {
            if(str_contains($e->getMessage(), "Cannot set PropertyValueCoordinates")){
                Log::warning("SaasuItemsSyncCommand.handle - SKU exists in HS products. ");
                if(!$item_code){
                    Log::warning("SaasuItemsSyncCommand.handle - Item Code doesn't exist. Unable to get HS Product ID. Item not save in the DB. ");
                    return;
                }

                $search_item_req = $hubspotWorker->generateHubSpotRequest($hubspotWorker->hubSpotSearch("hs_sku", $item_code));
                if($search_item_req["total"] > 0){
                    $hs_prod_id = $search_item_req["results"][0]["id"];
                    Item::create([
                        "saasu_item_id" => $item->Id,
                        "hubspot_product_id" => $hs_prod_id
                    ]);
                    Log::warning("SaasuItemsSyncCommand.handle - SKU <{$item_code}> successfully added to the table.");
                }
            } else {
                Log::error("SaasuItemsSyncCommand.handle - Something has gone wrong. " . $e->getMessage(), [
                    "event" => ["user" => $user->id],
                    'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                ]);
            }

        }
    
    }
}
