<?php

namespace App\Console\Commands;

use App\Jobs\CreateHubSpotDealJob;
use App\Jobs\CreateSaasuInvoiceJob;
use App\Jobs\UpdateHubSpotDealJob;
use App\Jobs\UpdateSaasuInvoiceJob;
use App\Models\Deal;
use App\Models\User;
use App\Workers\SaasuWorker;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SaasuInvoicesSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:saasu-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Saasu Invoices to HubSpot Deals';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info("SaasuInvoicesSyncCommand.handle - Sync command processing");
        $fromDate = date('Y-m-d');
        $toDate = date('Y-m-d', strtotime(' +1 day')) ;

        foreach (User::all() as $user) {

            Log::info("SaasuInvoicesSyncCommand.handle - Invoice sync command start to process  for Start date is <{$fromDate}> / End Date is <{$toDate}> ", [ "req" => [ "user" => $user ] ]);

            if($user->hubSpotConnected() && $user->saasuConnected()){ 

                try {

                    $pageNumber = 1;
                    $total_invoices = 0;

                    do {

                        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                        $get_invoices_request = $saasuWorker->generateSaasuRequest($saasuWorker->getInvoicesByDateRange($user->saasu_file_id, $fromDate, $toDate, $pageNumber));
                        $total_invoices = count($get_invoices_request->Invoices);

                        Log::info("SaasuInvoicesSyncCommand.handle - Total Invoices for Page <{$pageNumber}> : {$total_invoices}", [ "req" => [ "get_invoices_request" => $get_invoices_request ]]);
                        foreach ($get_invoices_request->Invoices as $invoice) { 

                            Log::debug("Dates: ", ["req" => ["fromDate" => $fromDate, "toDate" => $toDate, "invoice" => $invoice]]);
                            Log::info("SaasuInvoicesSyncCommand.handle - Invoice <{$invoice->TransactionId}> sync command start to process ", [ "req" => [ "invoice" => $invoice ] ]);
                            if($is_invoice_stored = Deal::where("saasu_invoice_id", $invoice->TransactionId)->first()){ 
                                UpdateHubSpotDealJob::dispatch($user, $is_invoice_stored, $invoice)->onConnection('sqs');
                                Log::info("SaasuInvoicesSyncCommand.handle - Invoice id <{$invoice->TransactionId}> is stored and has been successfully dispatched.");
                            } else {
                                CreateHubSpotDealJob::dispatch($user, $invoice)->onConnection('sqs');
                                Log::info("SaasuInvoicesSyncCommand.handle - Invoice id <{$invoice->TransactionId}> is not stored, has been successfully dispatched.");
                            }
    
                        }

                        $pageNumber++;

                    } while($total_invoices != 0);

                } catch (Exception $e) {
                    Log::error("SaasuInvoicesSyncCommand.handle - Something has gone wrong. " . $e->getMessage(), [
                        "event" => ["user" => $user->id],
                        'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                    ]);            
                }

            }

        }
    }
}
