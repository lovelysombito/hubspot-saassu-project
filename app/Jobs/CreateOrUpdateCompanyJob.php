<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Models\Company;
use App\Workers\HubSpotWorker;
use App\Workers\SaasuWorker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateOrUpdateCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $event;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $event)
    {
        $this->user = $user;
        $this->event = $event;
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
            $event = $this->event;
            $hubspot_company_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            Log::info("CreateOrUpdateCompanyJob.handle - Saasu company create", ["user" => $user->id, "hubspot_company_id" => $hubspot_company_id]);

            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

            $company_data = [];
            $company = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getCompanyById($hubspot_company_id));
            if(!$company['properties']){
                Log::warning("CreateOrUpdateCompanyJob.handleWebhook - The company <{$hubspot_company_id}> doesn't have properties. Aborting the process");
                return;
            }
            $company_data = Helper::constructHubSpotCompanyDetails($company);

            $is_company_stored = Company::where("hubspot_company_id", $hubspot_company_id)->first();
            if($is_company_stored){

                $saasu_get_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->getCompanyById($is_company_stored->saasu_company_id, $user->saasu_file_id));
                if(!$saasu_get_company_response){
                    Log::warning("CreateOrUpdateCompanyJob.handle - Saasu company <{$is_company_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return false;
                }
                if(!$saasu_get_company_response->LastUpdatedId) {
                    Log::warning("CreateOrUpdateCompanyJob.handle - Saasu company <{$is_company_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return false;
                }
                
                $company_data['LastUpdatedId'] = $saasu_get_company_response->LastUpdatedId;
                $saasu_update_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateCompanyById($is_company_stored->saasu_company_id, $user->saasu_file_id, $company_data));
                if($saasu_update_company_response){
                    Log::info("CreateOrUpdateCompanyJob.handle - Saasu company <{$is_company_stored->saasu_company_id}> is successfully updated");
                }

                return true;

            } else {
                $saasu_create_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->createCompany($user->saasu_file_id, $company_data));
                if($saasu_create_company_response){
                    Company::create([
                        "hubspot_company_id" => $hubspot_company_id,
                        "saasu_company_id" => $saasu_create_company_response->InsertedCompanyId
                    ]);
                    Log::info("CreateSaasuCompanyJob.handle - Saasu company <{$saasu_create_company_response->InsertedCompanyId}> is successfully created");
                }

                return true;
                
            }

            return true;

        } catch (Exception $e) {
            Log::error("CreateOrUpdateCompanyJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_company_id" => $hubspot_company_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }
    }
}
