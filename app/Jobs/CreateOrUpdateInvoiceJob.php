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

class CreateOrUpdateInvoiceJob implements ShouldQueue
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
            $hubspot_deal_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;

            /**
             * Get the deal details
             */
            $deal_data = [];
            $contact_data = [];
            $line_items = [];
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
            $deal = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealById($hubspot_deal_id));

            if(!$deal['properties']) {
                Log::info("CreateOrUpdateInvoiceJob.handle - HubSpot deal id <{$hubspot_deal_id}> properties not found. Please see in HubSpot");
                return false;
            }

            if(!$deal['properties']['dealstage']) {
                Log::info("CreateOrUpdateInvoiceJob.handle - Invalid deal stage value. Please check the value. Sync process stopped");
                return false;
            }

            Log::info("CreateOrUpdateInvoiceJob.handle - Deal Property List", ['req' => [ 'deal_properties' => $deal['properties'], "deal_stage"  => $deal['properties']['dealstage']] ]);

            if($deal['properties']['dealstage'] != config('hubspot.hubspot_test_quote_column_id')){
            // if($deal['properties']['dealstage'] != config('hubspot.hubspot_ppa_quote_column_id') && $deal['properties']['dealstage'] != config('hubspot.husbpot_oparable_wall')){
                Log::warning("CreateOrUpdateInvoiceJob.handle - HubSpot dealname {$deal['properties']['dealname']} and id <{$hubspot_deal_id}> hasn't been moved to Quote. Stopping the process.");
                return;
            }

            /**
             * Get the associated contact id
             */
            $deal_contact = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealAssociation($hubspot_deal_id, "contact"));
            if(empty($deal_contact['results'])) {
                Log::warning("CreateOrUpdateInvoiceJob.handle - DealId <{$hubspot_deal_id}> doesn't have associated contact. Stopping the process");
                return false;
                abort(400, "CreateOrUpdateInvoiceJob.handle - DealId <{$hubspot_deal_id}> doesn't have associated contact. Stopping the process");
            }
            $contact_id = $deal_contact['results'][0]['toObjectId'];
            $saasu_contact_id = '';

            /**
             * Check the contact id of exists 
             */

            $is_contact_stored = Contact::where("hubspot_contact_id", $contact_id)->first();

            if(!$is_contact_stored){
                Log::warning("CreateOrUpdateInvoiceJob.handle - HubSpot Contact Id <{$contact_id}> doesn't exists in Saasu portal. Creating it now.");
                /**
                 * Get Contact deatails and format it
                 */
                $contact_data = [];
                $get_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactById($contact_id));
                if(!$get_contact_request['properties']){
                    Log::warning("CreateOrUpdateInvoiceJob.handle - Contact Id <{$contact_id}> properties not found. Please see in HubSpot");
                    return;
                }
                $contact_data = Helper::constructHubSpotContactDetails($get_contact_request);
                /* Get the contact to get the LastUpdatedId needed for update*/
                $saasu_create_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->createContact($user->saasu_file_id, $contact_data));
                if($saasu_create_contact_response){
                    $saasu_contact_id = $saasu_create_contact_response->InsertedContactId;
                    Contact::create([
                        "hubspot_contact_id" => $contact_id,
                        "saasu_contact_id" => $saasu_create_contact_response->InsertedContactId
                    ]);
                    Log::info("CreateOrUpdateInvoiceJob.handle - Saasu contact <{$saasu_create_contact_response->InsertedContactId}> is successfully created");
                }
            } else {
                $saasu_contact_id = $is_contact_stored->saasu_contact_id;
                Log::info("CreateOrUpdateInvoiceJob.handle - HubSpot Contact Id <{$contact_id}> is stored.");
            }

            /**
             * Get the associated line item details
             */
            $deal_line_items = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealAssociation($hubspot_deal_id, "line_item"));
            if(empty($deal_line_items['results'])) {
                Log::warning("CreateOrUpdateInvoiceJob.handle - No line items to process.  Sync process stopped.");
                return false;
            }

            foreach ($deal_line_items['results'] as $key => $deal_line_item) {

                $line_item_data = [];
                $line_item = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getLineItemById($deal_line_item['toObjectId']));
                if(!$line_item){
                    Log::warning("CreateOrUpdateInvoiceJob.handle - Line Item id <{$deal_line_item['toObjectId']}> doesn't match to any of the line items in HubSpot.");
                    return false;
                }

                /*
                * Set up the line item
                */
                $line_item_data["Description"] = $line_item['properties']['description'];
                $line_item_data["TaxCode"] = $line_item['properties']['tax_code'] ?? "G1";
                $line_item_data["TotalAmount"] = $line_item['properties']['product_amount'];
                $line_item_data["Quantity"] = $line_item['properties']['quantity'];
                $line_item_data["UnitPrice"] = $line_item['properties']['price'];
                $line_item_data["InventoryId"] = $line_item['properties']['item_id'];
                $line_item_data["ItemCode"] = $line_item['properties']['hs_sku'];
                // $line_item_data["InventoryId"] = 6187010;
                // $line_item_data["ItemCode"] = "NEWTEST";
                // $line_item_data["Tags"] = ;
                $line_items[] = $line_item_data;

            }

            /*
            * Set up the invoice
            * Deal/Invoice properties: dealname,invoice_type,transaction_type,layout,transaction_date,amount
            */
            $deal_data["InvoiceType"] = "Quote";
            $deal_data["TransactionType"] = 'S';
            $deal_data["Layout"] = 'I';
            $deal_data["TransactionDate"] = date("Y-m-d");
            $deal_data["TotalAmount"] = $deal['properties']['amount'];
            $deal_data["BillingContactId"] = $saasu_contact_id;
            $deal_data["LineItems"] = $line_items;
            
            /**
             *  Create a Saasu invoice from HubSpot and store on company Table
             */
            Log::info("CreateOrUpdateInvoiceJob.handle - Saasu invoice create", ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id, "deal_data" => $deal_data]);
            $is_deal_stored = Deal::where("hubspot_deal_id", $hubspot_deal_id)->first();

            if($is_deal_stored){

                /**
                 * Get the invoice to get the LastUpdatedId needed for update
                */
                $saasu_get_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->getInvoiceById($is_deal_stored->saasu_invoice_id, $user->saasu_file_id));
                if(!$saasu_get_invoice_response){
                    Log::warning("CreateOrUpdateInvoiceJob.handle - Saasu company <{$is_deal_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return false;
                }
                if(!$saasu_get_invoice_response->LastUpdatedId) {
                    Log::warning("CreateOrUpdateCompanyJob.handle - Saasu company <{$is_deal_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return false;
                }
                if(!$saasu_get_invoice_response->TransactionDate){
                    Log::warning("CreateOrUpdateCompanyJob.handle - Transaction Date doesn't exists for invoice id <{$is_deal_stored->saasu_invoice_id}>. Setting it to today's date.");
                    $deal_data["TransactionDate"] = date("Y-m-d");
                }

                $deal_data['LastUpdatedId'] = $saasu_get_invoice_response->LastUpdatedId;
                $deal_data["TransactionDate"] = $saasu_get_invoice_response->TransactionDate;
                $saasu_update_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateInvoiceById($is_deal_stored->saasu_invoice_id, $user->saasu_file_id, $deal_data));
                if($saasu_update_invoice_response){
                    Log::info("UpdateSaasuInvoiceJob.handle - Saasu invoice/quote <{$is_deal_stored->saasu_invoice_id}> is successfully updated");
                }

            } else {
                /* Create Invoice to Saasu*/
                $saasu_create_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->createInvoice($user->saasu_file_id, $deal_data));
                Log::info("CreateOrUpdateInvoiceJob.handle - saasu_create_invoice_response", [ 'req' => [ "saasu_create_invoice_response" => $saasu_create_invoice_response ]]);

                if($saasu_create_invoice_response){
                    Deal::create([
                        "hubspot_deal_id" => $hubspot_deal_id,
                        "saasu_invoice_id" => $saasu_create_invoice_response->InsertedEntityId
                    ]);
                    Log::info("CreateOrUpdateInvoiceJob.handle - Saasu invoice <{$saasu_create_invoice_response->InsertedEntityId}> is successfully created");
                }
            }

            return true;

        } catch (Exception $e) {
            Log::error("CreateOrUpdateInvoiceJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }
    }
}
