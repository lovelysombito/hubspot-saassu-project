<?php

namespace App\Jobs;

use App\Models\Company;
use App\Workers\HubSpotWorker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateHubSpotCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $user, $stored_company, $company;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $stored_company, $company)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
        $this->stored_company = $stored_company;
        $this->company = $company;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        try{

            $user = $this->user;
            $stored_company = $this->stored_company;
            $company = $this->company;
    
            $company_data = [];
    
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
    
            /**
             * Format Saasu Company Details
             */
            $company_data['properties']['name'] = $company->Name ?? "";
            $company_data['properties']['email'] = $company->CompanyEmail ?? "";
            $company_data['properties']['trading_name'] = $company->TradingName ?? "";
    
            /**
             * Create HubSpot Company
             */
            $update_hubspot_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->updateCompanyById($stored_company->hubspot_company_id, $company_data));
            if($update_hubspot_company_request){
                Log::info("UpdateHubSpotCompanyJob.handle - HubSpot company {$update_hubspot_company_request['id']} is successfully updated", [
                    "req" => [
                        "updated_company_data" => $update_hubspot_company_request
                    ]
                ]);
            }

        } catch (Exception $e) {
            Log::error("UpdateHubSpotCompanyJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_contact_id" => $company->Id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);

             /**
             * HubSpot company id doesn't not exist
             */
            if(str_contains($e->getMessage(), "resource not found")){

                $create_hubspot_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createCompany($company_data));
                if($create_hubspot_company_request){
                    /**
                     * Save in the company table
                     */

                    $stored_company->update([
                    "hubspot_company_id" => $create_hubspot_company_request['id'],
                    ]);

                    Log::info("UpdateHubSpotCompanyJob.handle - HubSpot company {$create_hubspot_company_request['id']} is successfully created", [
                        "req" => [
                            "created_company_data" => $create_hubspot_company_request
                        ]
                    ]);

                }

            }


        }
    }
}
