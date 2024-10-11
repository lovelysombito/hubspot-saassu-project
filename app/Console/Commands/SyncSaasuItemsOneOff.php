<?php

namespace App\Console\Commands;

use App\Jobs\CreateSaasuItemJob;
use App\Jobs\UpdateSaasuItemJob;
use App\Models\Item;
use App\Models\User;
use App\Workers\SaasuWorker;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use DateTime;
use DateTimeZone;
use Exception;

class SyncSaasuItemsOneOff extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:saasu-items-one-off';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Saasu item one off';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info("SyncSaasuItemsOneOff.handle - Sync command processing");
        $startDate = date('Y-m-d') . ' ' . env('START_TIME');
        $endDate =  date('Y-m-d', strtotime(' +1 day')) . ' ' . env('END_TIME');

        foreach (User::all() as $user) {

            Log::info("SyncSaasuItemsOneOff.handle - Items sync command start to process ", [
                "req" => [
                    "user" => $user,
                    "startDate" => $startDate,
                    "endDate" => $endDate,
                ]
            ]);

            if($user->hubSpotConnected() && $user->saasuConnected()){
                
                try {
                    $total_items = 885;
                    $number_of_items = 30;
                    $total_number_pages = ceil($total_items/$number_of_items);
                   
                    for ($page_num=1; $page_num <= $total_number_pages; $page_num++) { 
                        Log::info("Page number <{$page_num}> with total of <{$number_of_items}>");
                        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                        $get_items_request = $saasuWorker->generateSaasuRequest($saasuWorker->getItems($user->saasu_file_id, $page_num, $number_of_items));
                        if(count($get_items_request->Items) == 0){
                            Log::info("SyncSaasuItemsOneOff.handle - No Saasu item for page number <{$page_num}>", [
                                "req" => ["user_id" => $user->id]
                            ]);
                            return;
                        }

                        foreach ($get_items_request->Items as $item) {
                            
                            Log::info("SyncSaasuItemsOneOff.handle - ItemId <{$item->Id}> sync command start to process. ", [
                                "req" => [
                                    "item_data" => $item
                                ]
                            ]);
    
                            if($is_item_stored = Item::where("saasu_item_id", $item->Id)->first()){
                                UpdateSaasuItemJob::dispatch($user, $is_item_stored, $item);
                                Log::info("SyncSaasuItemsOneOff.handle - Item id <{$item->Id}> is stored and has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            } else {
                                CreateSaasuItemJob::dispatch($user, $item);
                                Log::info("SyncSaasuItemsOneOff.handle - Item id <{$item->Id}> is not stored, has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            }
                        }

                    }

                } catch (Exception $e) {
                    Log::error("SyncSaasuItemsOneOff.handle - Something has gone wrong. " . $e->getMessage(), [
                        "event" => ["user" => $user->id],
                        'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                    ]);            
                }
                
            }

        }

    }
}
