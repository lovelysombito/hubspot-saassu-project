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

class CreateHubSpotContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $contact;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $contact)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
        $this->contact = $contact;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $user = $this->user;
        $contact = $this->contact;
        $company_data = [];
        $hubspot_company_id = "";

        $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

        try {

            /**
             * Format Saasu contact details
             */
            $contact_data = Helper::constructSaasuContactDetails($contact);
            Log::info("CreateHubSpotContactJob.handle - Contact Data Properties", [ "req" => [ "contact_data" => $contact_data, "contact" => $contact ]]);

            /**
             * Process Contact creation in HubSpot
             */
            $create_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContact($contact_data));

            /**
             * Save in the Contact table
             */
            Contact::create([
                "saasu_contact_id" => $contact->Id,
                "hubspot_contact_id" => $create_contact_request['id']
            ]);
            Log::info("CreateHubSpotContactJob.handle - HubSpot Contact {$create_contact_request['id']} is successfully created", [
                "req" => [
                    "created_company_data" => $create_contact_request
                ]
            ]);
        } catch (Exception $e) {
            Log::error("CreateHubSpotContactJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_contact_id" => $contact->Id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);
            if(str_contains($e->getMessage(), "Contact already exists")){
                /** 
                 * Contact already Exists update it and create a field in table
                */
                Log::warning("CreateHubSpotContactJob.handle - Contact already Exists. Get contact data using email {$contact->EmailAddress} and create a field in table. ");
                $get_contact_by_email = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactProfile($contact->EmailAddress));
                $hs_contact_id = $get_contact_by_email['vid'];
                $update_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->updateContactById($hs_contact_id, $contact_data));
               
                /**
                 * Update in the Contact table
                 */
                Contact::create([
                    "saasu_contact_id" => $contact->Id,
                    "hubspot_contact_id" => $hs_contact_id
                ]);
                Log::info("CreateHubSpotContactJob.handle - HubSpot Contact {$hs_contact_id} is successfully updated", ["req" => ["updated_company_data" => $update_contact_request]]);
            }
        }

        try {

            /**
             * Get the associated company details
             */

            if($contact->CompanyId){

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

                    Log::info("CreateHubSpotContactJob.handle - HubSpot company {$create_hubspot_company_request['id']} is successfully created", [
                        "req" => [
                            "created_company_data" => $create_hubspot_company_request
                        ]
                    ]);

                    /**
                     * Associate Contact to Company
                     */
                    $associate_contact_to_company_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContactAssociation($create_contact_request['id'], "company", $hubspot_company_id));
                    if($associate_contact_to_company_request){
                        Log::info("CreateHubSpotContactJob.handle - HubSpot company <{$create_contact_request['id']}> is successfully associated to company <{$hubspot_company_id}>");
                    }
                }

            } else {
                Log::info("CreateHubSpotContactJob.handle - Saasu Contact <{$contact->Id}> doesn't have associated company");
            }

        } catch (Exception $e) {
            Log::error("CreateHubSpotContactJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_contact_id" => $contact->Id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);
        }

    }
}
