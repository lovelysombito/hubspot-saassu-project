<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Models\Contact;
use App\Models\Deal;
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

class CreateHubSpotDealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user, $invoice;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $invoice)
    {
        $this->queue = env("SQS_QUEUE");
        $this->user = $user;
        $this->invoice = $invoice;
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
            $invoice = $this->invoice;
    
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                
            /**
             * Format Saasu invoice details
             */
            $deal_data = Helper::constructSaasuInvoiceDetails($user, $invoice, $saasuWorker);

            Log::info("CreateHubSpotDealJob.handle - DEBUGGING:: deal_data", [
                "req" => [
                    "data" => $deal_data
                ]
            ]);

            if(!$deal_data){
                Log::error("CreateHubSpotDealJob.handle - Saaus Invoice properties are empty. No data to process.", [
                    "event" => ["user" => $user->id, "saasu_invoice_id" => $invoice->TransactionId], 
                ]);
                return false;
            }

            /**
             * Process Deal data
             */
            $create_deal_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createDeal($deal_data));
            if($create_deal_request){
                /**
                 * Save in the Deal table
                 */
                Deal::create([
                    "saasu_invoice_id" => $invoice->TransactionId,
                    "hubspot_deal_id" => $create_deal_request['id']
                ]);
                Log::info("CreateHubSpotDealJob.handle - HubSpot deal {$create_deal_request['id']} is successfully created");
            }

            /**
             * Check if associated to a contact
             */
            if($invoice->BillingContactId){
                
                /**
                 * Check if contact exists in the db
                 */
                if($is_stored_contact = Contact::where("saasu_contact_id", $invoice->BillingContactId)->first()){
                     /**
                     * Exists, associate it
                     */
                    $hubspot_contact_id = $is_stored_contact->hubspot_contact_id;
                } else {
                    
                    /**
                     * Does not exists, create it
                     */
                    $get_contact_request = $saasuWorker->generateSaasuRequest($saasuWorker->getContactById($invoice->BillingContactId, $user->saasu_file_id));
                    $contact_data = Helper::constructSaasuContactDetails($get_contact_request);

                    Log::info("DEBUGGING:: contact_data", [
                        "req" => [
                            "data" => $contact_data
                        ]
                    ]);

                    $create_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContact($contact_data));
                    if($create_contact_request){
                        $hubspot_contact_id = $create_contact_request['id'];

                        /**
                         * Save in the Contact table
                         */
                        Contact::create([
                            "saasu_contact_id" => $invoice->BillingContactId,
                            "hubspot_contact_id" => $create_contact_request['id']
                        ]);
                        Log::info("CreateHubSpotDealJob.handle - HubSpot Contact {$create_contact_request['id']} is successfully created");
                    }
                }

                /**
                 * Associate Deal to Contact
                 */
                $associate_deal_to_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createDealAssociation($create_deal_request['id'], "contact", $hubspot_contact_id));
                if($associate_deal_to_contact_request){
                    Log::info("CreateHubSpotDealJob.handle - HubSpot deal <{$create_deal_request['id']}> is successfully associated to contact <{$hubspot_contact_id}>");
                }

            }

        } catch (Exception $e) {
            Log::error("CreateHubSpotDealJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_invoice_id" => $invoice->TransactionId],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);

            if(str_contains($e->getMessage(), "One or more associations are invalid")){
                $is_stored_contact = Contact::where("saasu_contact_id", $invoice->BillingContactId)->first();
                Log::warning("CreateHubSpotDealJob.handle - Stored HuSpot contact id <{$is_stored_contact->hubspot_contact_id}> doesn't exists anymore, creating it.");
                
                /**
                 * Stored HuSpot contact id doesn't exists anymore, creating it.
                 */
                $get_contact_request = $saasuWorker->generateSaasuRequest($saasuWorker->getContactById($invoice->BillingContactId, $user->saasu_file_id));
                $contact_data = Helper::constructSaasuContactDetails($get_contact_request);
                $create_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createContact($contact_data));
                if($create_contact_request){
                    $hubspot_contact_id = $create_contact_request['id'];

                    /**
                     * Save in the Contact table
                     */
                    $is_stored_contact->update([
                        "hubspot_contact_id" => $create_contact_request['id']
                    ]);
                    Log::info("CreateHubSpotDealJob.handle - HubSpot Contact {$create_contact_request['id']} is successfully created");

                    /**
                     * Associate Deal to Contact
                     */
                    $associate_deal_to_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->createDealAssociation($create_deal_request['id'], "contact", $hubspot_contact_id));
                    if($associate_deal_to_contact_request){
                        Log::info("CreateHubSpotDealJob.handle - HubSpot deal <{$create_deal_request['id']}> is successfully associated to contact <{$hubspot_contact_id}>");
                    }
                }

            }
        }

    }
}
