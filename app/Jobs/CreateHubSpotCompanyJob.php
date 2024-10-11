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

class CreateHubSpotCompanyJob implements ShouldQueue
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
        $this->queue = env("SQS_QUEUE");
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
        try {

            $user = $this->user;
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
            $create_hubspot_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createCompany($company_data));
            if($create_hubspot_company_request){
                /**
                 * Save in the company table
                 */
                Company::create([
                    "hubspot_company_id" => $create_hubspot_company_request['id'],
                    "saasu_company_id" => $company->Id
                ]);

                Log::info("CreateHubSpotCompanyJob.handle - HubSpot company {$create_hubspot_company_request['id']} is successfully created", [
                    "req" => [
                        "created_company_data" => $create_hubspot_company_request
                    ]
                ]);

            }

        } catch (Exception $e) {
            Log::error("CreateHubSpotCompanyJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_contact_id" => $company->Id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);
        }
    }
}
