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

class UpdateHubSpotContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $stored_contact, $contact;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $stored_contact, $contact)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
        $this->stored_contact = $stored_contact;
        $this->contact = $contact;
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
            $stored_contact = $this->stored_contact;
            $contact = $this->contact;

            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
            
            /**
             * Format Saasu contact details
             */
            $contact_data = Helper::constructSaasuContactDetails($contact);
            Log::info("UpdateHubSpotContactJob.handle - Contact Data Properties", [
                "req" => [
                    "contact" => $contact,
                    "contact_data" => $contact_data
                ]
            ]);
            /**
             * Process Contact update in HubSpot
             */
            $update_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->updateContactById($stored_contact->hubspot_contact_id, $contact_data));
            if($update_contact_request){
                /**
                 * Update in the Contact table
                 */
                $stored_contact->update([
                    "hubspot_contact_id" => $update_contact_request['id']
                ]);
                Log::info("UpdateHubSpotContactJob.handle - HubSpot Contact {$update_contact_request['id']} is successfully updated",  [
                    "req" => [
                        "updated_company_data" => $update_contact_request
                    ]
                ]);
            }

            /**
             * Get the associated company details
            */
            $this->contactCompanyAssociation($contact, $hubspotWorker, $update_contact_request, $saasuWorker, $user);

        } catch (Exception $e) {
            Log::error("UpdateHubSpotContactJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_contact_id" => $contact->Id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);

            /**
             * HubSpot contact id doesn't not exist
             */
            if(str_contains($e->getMessage(), "resource not found")){
                $create_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContact($contact_data));
                if($create_contact_request){
    
                    /**
                     * Update in the Contact table
                     */
                    $stored_contact->update([
                        "hubspot_contact_id" => $create_contact_request['id']
                    ]);
                    Log::info("UpdateHubSpotContactJob.handle - HubSpot Contact {$create_contact_request['id']} is successfully created");
                }

                /**
                 * Get the associated company details
                 */
                $this->contactCompanyAssociation($contact, $hubspotWorker, $create_contact_request, $saasuWorker, $user);
            }
        }
    }

    public function contactCompanyAssociation($contact, $hubspotWorker, $create_contact_request, $saasuWorker, $user)
    {
        if($contact->CompanyId){
            Log::info("UpdateHubSpotContactJob.handle - Saasu Contact <{$contact->Id}> is associated to saasu company <{$contact->CompanyId}>");
            /**
             * Check if company exists
             */
            $stored_company = Company::where("saasu_company_id", $contact->CompanyId)->first();

            if($stored_company){

                /**
                 * Company is stored, associate it now.
                 */
                $associate_contact_to_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContactAssociation($create_contact_request['id'], "company", $stored_company->hubspot_company_id));
                if($associate_contact_to_company_request){
                    Log::info("UpdateHubSpotContactJob.handle - HubSpot contact <{$create_contact_request['id']}> is successfully associated to company <{$stored_company->hubspot_company_id}>");
                }

            } else {
                /**
                 * Company is not store, create and associate.
                 */
                $associated_company_details = $saasuWorker->generateSaasuRequest($saasuWorker->getCompanyById($contact->CompanyId, $user->saasu_file_id));

                /**
                 * Format Saasu Associated Company Details
                 */
                $company_data['properties']['name'] = $associated_company_details->Name ?? "";
                $company_data['properties']['email'] = $associated_company_details->CompanyEmail ?? "";
                $company_data['properties']['trading_name'] = $associated_company_details->TradingName ?? "";
                
                /**
                 * Create HubSpot Company
                 */
                $create_hubspot_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createCompany($company_data));

                if($create_hubspot_company_request){
                    $hubspot_company_id = $create_hubspot_company_request['id'];

                    /**
                     * Save in the company table
                     */
                    Company::create([
                        "hubspot_company_id" => $create_hubspot_company_request['id'],
                        "saasu_company_id" => $contact->CompanyId
                    ]);

                    Log::info("UpdateHubSpotContactJob.handle - HubSpot Company {$create_hubspot_company_request['id']} is successfully created");

                    /**
                     * Associate Contact to Company
                     */
                    $associate_contact_to_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContactAssociation($create_contact_request['id'], "company", $hubspot_company_id));
                    if($associate_contact_to_company_request){
                        Log::info("UpdateHubSpotContactJob.handle - HubSpot contact <{$create_contact_request['id']}> is successfully associated to company <{$hubspot_company_id}>");
                    }
                
                }

            }

        } else {
            Log::info("UpdateHubSpotContactJob.handle - Saasu Contact <{$contact->Id}> doesn't have associated company");
        }
    }
}
