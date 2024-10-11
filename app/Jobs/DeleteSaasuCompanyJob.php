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

class DeleteSaasuCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $company;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $company)
    {
        $this->user = $user;
        $this->company = $company;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $user = $this->user;
        $company = $this->company;

        /**
         * Delete Saasu Company
        */

        try {
            Log::info("DeleteSaasuCompanyJob.handle - Saasu company delete", ["user" => $user->id, "saasu_company_id" => $company->saasu_company_id]);
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

            $saasu_delete_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->deleteCompany($company->saasu_company_id, $user->saasu_file_id));
            if($saasu_delete_company_response){
                Log::info("DeleteSaasuCompanyJob.handle - Saasu company <{$company->saasu_company_id}> is successfully deleted");
                if(!$company->delete()){
                    Log::error("DeleteSaasuCompanyJob.handle - An error occured while deleting company in table.");
                } 
                Log::info("DeleteSaasuCompanyJob.handle - Saasu company is successfully deleted in the table");
            }

        } catch (Exception $e) {
            Log::error("DeleteSaasuCompanyJob.handle - Something has gone wrong. " . $e->getMessage(), [
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
        }
    }
}
