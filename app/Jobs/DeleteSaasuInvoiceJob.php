<?php

namespace App\Jobs;

use App\Workers\SaasuWorker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSaasuInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $user, $deal;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $deal)
    {
        $this->user = $user;
        $this->deal = $deal;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $user = $this->user;
        $deal = $this->deal;

        try {
            Log::info("DeleteSaasuInvoiceJob.handle - Saasu invoice delete", ["user" => $user->id, "saasu_details" => $deal]);
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

            $saasu_delete_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->deleteInvoice($deal->saasu_invoice_id, $user->saasu_file_id));
            if($saasu_delete_invoice_response){
                Log::info("DeleteSaasuInvoiceJob.handle - Saasu invoice <{$deal->saasu_invoice_id}> is successfully deleted");
                if(!$deal->delete()){
                    Log::error("DeleteSaasuInvoiceJob.handle - An error occured while deleting deal in table.");
                } 
                Log::info("DeleteSaasuInvoiceJob.handle - Saasu invoice is successfully deleted in the table");
            }

        } catch (Exception $e) {
            Log::error("DeleteSaasuInvoiceJob.handle - Something has gone wrong. " . $e->getMessage(), [
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            throw new Exception($e->getMessage());
        }

    }
}
