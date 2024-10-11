<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Contact;
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

class CreateOrUpdateContactJob implements ShouldQueue
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
            $hubspot_contact_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            Log::info("CreateOrUpdateContactJob.handle - Data for HS Contact Id <{$hubspot_contact_id}>", [ "req" => [ "data" => $event] ]);

            $contact_data = [];
            $company_data = [];
            $saasu_company_id = "";

            /**
             * Get Contact in HubSpot
             */
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
            $contact = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactById($hubspot_contact_id));
            if(!$contact['properties']){
                Log::warning("CreateOrUpdateContactJob.handle - The contact <{$hubspot_contact_id}> doesn't have properties. Aborting the process");
                return;
            }

            /**
             * Format Contact Details
             */
            $contact_data = Helper::constructHubSpotContactDetails($contact);
            Log::info("CreateOrUpdateContactJob.handle - Contact Data Properties", [ "req" => [ "contact" => $contact, "contact_data" => $contact_data ]]);

            /**
             * Get Contact/Company Association
             */
            $contact_company_association = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactAssociation($hubspot_contact_id, 'company'));

            if(count($contact_company_association['results']) > 0){

                /**
                 * Get Associated company id
                 */
                $associated_company_id = $contact_company_association['results'][0]['toObjectId'];
                Log::warning("CreateOrUpdateContactJob.handle - Contact <{$hubspot_contact_id}> has an associated company <{$associated_company_id}>");

                /**
                 * Get company details
                 */
                $associated_deal_details = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getCompanyById($associated_company_id));
                if($associated_deal_details['properties']){

                    /**
                     * Format company details
                     */
                    $company_data = Helper::constructHubSpotCompanyDetails($associated_deal_details); 

                    /**
                     * Check if company is stored, if yes get the saasu company id, if no create it
                     */
                    if(!$company_is_stored = Company::where("hubspot_company_id", $hubspot_contact_id)->first()){
                        Log::warning("CreateOrUpdateContactJob.handle - Associated Company doesn't exists in saasu portal, create company");
                        $saasu_create_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->createCompany($user->saasu_file_id, $company_data));
                        if($saasu_create_company_response){
                            Company::create([
                                "hubspot_company_id" => $associated_company_id,
                                "saasu_company_id" => $saasu_create_company_response->InsertedCompanyId
                            ]);
                            Log::info("CreateOrUpdateContactJob.handle - Saasu contact <{$saasu_create_company_response->InsertedCompanyId}> is successfully created");
                        }
                        $saasu_company_id = $saasu_create_company_response->InsertedCompanyId;
                    } else {
                        $saasu_company_id =  $company_is_stored->saasu_company_id;
                    }
                }
            }

            /**
             * Add Company Id
             */
            $contact_data['CompanyId'] = $saasu_company_id;

            /**
             * Check if contact exists in the table
             */

            $is_contact_stored = Contact::where("hubspot_contact_id", $hubspot_contact_id)->first();
            if($is_contact_stored){

                /**
                 * Get the contact to get the LastUpdatedId needed for update
                */
                $saasu_get_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->getContactById($is_contact_stored->saasu_contact_id, $user->saasu_file_id));
                if(!$saasu_get_contact_response){
                    Log::warning("CreateOrUpdateContactJob.handle - Saasu contact <{$is_contact_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return false;
                }
                if(!$saasu_get_contact_response->LastUpdatedId) {
                    Log::warning("CreateOrUpdateContactJob.handle - Saasu company <{$is_contact_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return false;
                }

                /**
                 * Update the contact
                */
                $contact_data['LastUpdatedId'] = $saasu_get_contact_response->LastUpdatedId;
                $saasu_update_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateContactById($is_contact_stored->saasu_contact_id, $user->saasu_file_id, $contact_data));
                if($saasu_update_contact_response){
                    Log::info("CreateOrUpdateContactJob.handle - Saasu contact <{$is_contact_stored->saasu_contact_id}> is successfully updated");
                }

            } else {

                /**
                 * Process contact creation in Saasu
                 */
                $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                $saasu_create_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->createContact($user->saasu_file_id, $contact_data));
                if($saasu_create_contact_response){
                    
                    /**
                     * Add Ids in the contacts table
                    */
                    Contact::create([
                        "hubspot_contact_id" => $hubspot_contact_id,
                        "saasu_contact_id" => $saasu_create_contact_response->InsertedContactId
                    ]);
                    Log::info("CreateOrUpdateContactJob.handle - Saasu contact <{$saasu_create_contact_response->InsertedContactId}> is successfully created");
                }
                
            }

            return true;

        } catch (Exception $e) {
            Log::error("CreateOrUpdateContactJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_contact_id" => $hubspot_contact_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }

    }
}
