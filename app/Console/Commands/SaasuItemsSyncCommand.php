<?php

namespace App\Console\Commands;

use App\Jobs\CreateSaasuItemJob;
use App\Jobs\UpdateSaasuItemJob;
use App\Models\Item;
use App\Models\User;
use App\Workers\SaasuWorker;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SaasuItemsSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:saasu-items';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Saasu Items to HubSpot Products';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info("SaasuItemsSyncCommand.handle - Sync command processing");
        $date_time_now = date('Y-m-d H:i:s');

        $fromDate = date('Y-m-d');
        $toDate = date('Y-m-d', strtotime(' +1 day')) ;

        foreach (User::all() as $user) {

            Log::info("SaasuInvoicesSyncCommand.handle - Item sync command start to process for Start date is <{$fromDate}> / End Date is <{$toDate}>. Date and Time now <$date_time_now>", ["req" => [ "user" => $user] ]);

            if($user->hubSpotConnected() && $user->saasuConnected()){
                
                try {
                    $pageNumber = 1;
                    $total_items = 0;
                    
                    do {
                        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                        $get_items_request = $saasuWorker->generateSaasuRequest($saasuWorker->getItemsByDateRange($user->saasu_file_id, $fromDate, $toDate, $pageNumber));
                        $total_items = count($get_items_request->Items);
                        Log::info("SaasuItemsSyncCommand.handle - Total Items for Page <{$pageNumber}> : {$total_items}", [ "req" => [ "get_items_request" => $get_items_request ]]);

                        foreach ($get_items_request->Items as $item) {

                            Log::info("SaasuItemsSyncCommand.handle - Item <{$item->Id}> sync command start to process ", [  "req" => ["item_data" => $item ] ]);
                            if($is_item_stored = Item::where("saasu_item_id", $item->Id)->first()){
                                UpdateSaasuItemJob::dispatch($user, $is_item_stored, $item)->onConnection('sqs');
                                Log::info("SaasuItemsSyncCommand.handle - Item id <{$item->Id}> is stored and has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            } else {
                                CreateSaasuItemJob::dispatch($user, $item)->onConnection('sqs');
                                Log::info("SaasuItemsSyncCommand.handle - Item id <{$item->Id}> is not stored, has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            }
                           
                        }
                        
                        $pageNumber++;

                    } while ($total_items != 0);

                } catch (Exception $e) {
                    Log::error("SaasuItemsSyncCommand.handle - Something has gone wrong. " . $e->getMessage(), [
                        "event" => ["user" => $user->id],
                        'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                    ]);            
                }
                
            }

        }

    }
}
