<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
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

class WebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $events;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($events)
    {
        $this->events = $events;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $events = $this->events;

        foreach ($events as $event) {

            if(!isset($event->subscriptionType)){
                Log::warning("WebhookEventJob.handle - Subscription type doesn't exists. Stopping the process.");
                return false;
            }

            if(!isset($event->portalId)){
                Log::warning("HubSpotController.handle - PortalId doesn't exists. Stopping the process.");
                return false;
            }
            
            Log::info("WebhookEventJob.handle - Webhook running with subscription type <{$event->subscriptionType}>.");
            $account_id = $event->portalId;
            $user = User::where('hubspot_account_id', $account_id)->first();

            if(!$user){
                Log::warning("WebhookEventJob.handle - User with account id <{$account_id}> doesn't exists. Unable to processs");
                return false;
            }

            if($event->subscriptionType == "deal.associationChange"){
                if($event->associationRemoved){
                    Log::warning("WebhookEventJob.handle - Deal association removed. Not part of the process.");
                    return false;
                }
            }            

            if($event->subscriptionType == "contact.associationChange"){
                if($event->associationRemoved){
                    Log::warning("WebhookEventJob.handle - Contact association removed. Not part of the process.");
                    return false;
                }
            }

            if($event->subscriptionType == "company.associationChange"){
                if($event->associationRemoved){
                    Log::warning("WebhookEventJob.handle - Company association removed. Not part of the process.");
                    return false;
                }
            }

            if($event->subscriptionType == "line_item.associationChange"){
                if($event->associationRemoved){
                    Log::warning("WebhookEventJob.handle - Line Item association removed. Not part of the process.");
                    return false;
                }
            }

            if($event->subscriptionType == "deal.deletion"){
                Log::warning("WebhookEventJob.handle - Deal deletion is not part of the process.");
                return false;
            }

            if($event->subscriptionType == "contact.deletion"){
                Log::warning("WebhookEventJob.handle - Contact deletion is not part of the process.");
                return false;
            }
            
            if($event->subscriptionType == "company.deletion"){
                Log::warning("WebhookEventJob.handle - Company deletion is not part of the process.");
                return false;
            }

            $subscription_type = $event->subscriptionType;
            $object = explode(".", $subscription_type);
            $object_name = $object[0];
            Log::info("WebhookEventJob.handle - Data.", [
                "req" => [
                    "data" => [
                        "object" => $object,
                        "object_name" => $object_name,
                    ]
                ]
            ]);

            if(!$user->hubSpotConnected()) { 
                Log::warning("WebhookEventJob.handle - User with account id <{$account_id}> not connected to HubSpot. Unable to processs");
                return false;
            }

            Log::info("WebhookEventJob.handle - Subscription type <{$subscription_type}> with object name <{$object_name}>", ["user_id" => $user->id]);

            switch ($object_name) {
                case 'contact':
                    if(!$user->saasuConnected()){
                        Log::warning("WebhookEventJob.handle - Saasu Account user <{$user->id}> not connected.");
                        return false;
                    }
                    $this->createOrUpdateContact($user, $event);
                    break;

                case 'company':
                    if(!$user->saasuConnected()){
                        Log::warning("WebhookEventJob.handle - Saasu Account user <{$user->id}> not connected.");
                        return false;
                    }
                    $this->createOrUpdateCompany($user, $event);
                    break;

                case 'deal':
                    if(!$user->saasuConnected()){
                        Log::warning("WebhookEventJob.handle - Saasu Account user <{$user->id}> not connected.");
                        return response()->json([
                            'message' => "Saasu Account user <{$user->id}> not connected"
                        ]);
                    }
                    $this->createOrUpdateInvoice($user, $event);
                    break;

                case 'line_item':
                    if(!$user->saasuConnected()){
                        Log::warning("WebhookEventJob.handle - Saasu Account user <{$user->id}> not connected.");
                        return response()->json([
                            'message' => "Saasu Account user <{$user->id}> not connected"
                        ]);
                    }
                    $this->createOrUpdateLineItem($user, $event);
                    break;

                default:
                    Log::error("WebhookEventJob.handle - Subscription type {$subscription_type} not applicable");
                    break;
            }   

            Log::info("WebhookEventJob.handle - Webhook with subscription type {$subscription_type} successfully processed");
        }

        return true;

    }

    public function createOrUpdateContact($user, $event)
    {
        try { 

            Log::info("WebhookEventJob.createOrUpdateContact - Starting . . .", ["user_id" => $user->id]);

            $hubspot_contact_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            Log::info("WebhookEventJob.handle - HubSpot contactId <{$hubspot_contact_id}> process");

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
                Log::warning("WebhookEventJob.handle - The contact <{$hubspot_contact_id}> doesn't have properties. Aborting the process");
                return;
            }

            /**
             * Format Contact Details
             */
            $contact_data = Helper::constructHubSpotContactDetails($contact);
            Log::info("WebhookEventJob.handle - Contact Data Properties", [ "req" => [ "contact" => $contact, "contact_data" => $contact_data ]]);

            /**
             * Get Contact/Company Association
             */
            $contact_company_association = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactAssociation($hubspot_contact_id, 'company'));

            if(count($contact_company_association['results']) > 0){

                /**
                 * Get Associated company id
                 */
                $associated_company_id = $contact_company_association['results'][0]['toObjectId'];
                Log::warning("WebhookEventJob.handle - Contact <{$hubspot_contact_id}> has an associated company <{$associated_company_id}>");

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
                        Log::warning("WebhookEventJob.handle - Associated Company doesn't exists in saasu portal, create company");
                        $saasu_create_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->createCompany($user->saasu_file_id, $company_data));
                        if($saasu_create_company_response){
                            Company::create([
                                "hubspot_company_id" => $associated_company_id,
                                "saasu_company_id" => $saasu_create_company_response->InsertedCompanyId
                            ]);
                            Log::info("WebhookEventJob.handle - Saasu contact <{$saasu_create_company_response->InsertedCompanyId}> is successfully created");
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
                    Log::warning("WebhookEventJob.handle - Saasu contact <{$is_contact_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return;
                }
                if(!$saasu_get_contact_response->LastUpdatedId) {
                    Log::warning("WebhookEventJob.handle - Saasu company <{$is_contact_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return;
                }

                /**
                 * Update the contact
                */
                $contact_data['LastUpdatedId'] = $saasu_get_contact_response->LastUpdatedId;
                $saasu_update_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateContactById($is_contact_stored->saasu_contact_id, $user->saasu_file_id, $contact_data));
                if($saasu_update_contact_response){
                    Log::info("WebhookEventJob.handle - Saasu contact <{$is_contact_stored->saasu_contact_id}> is successfully updated");
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
                    Log::info("WebhookEventJob.handle - Saasu contact <{$saasu_create_contact_response->InsertedContactId}> is successfully created");
                }
                
            }

            return true;

        } catch (Exception $e) {
            Log::error("WebhookEventJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_contact_id" => $hubspot_contact_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }
    }

    public function createOrUpdateCompany($user, $event)
    {
        try {

            Log::info("WebhookEventJob.createOrUpdateCompany - Starting . . .", ["user" => $user->id]);
            $hubspot_company_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            Log::info("WebhookEventJob.handle - HubSpot companyId <{$hubspot_company_id}> process");

            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

            $company_data = [];
            $company = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getCompanyById($hubspot_company_id));
            if(!$company['properties']){
                Log::warning("WebhookEventJob.handleWebhook - The company <{$hubspot_company_id}> doesn't have properties. Aborting the process");
                return;
            }
            $company_data = Helper::constructHubSpotCompanyDetails($company);

            $is_company_stored = Company::where("hubspot_company_id", $hubspot_company_id)->first();
            if($is_company_stored){

                $saasu_get_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->getCompanyById($is_company_stored->saasu_company_id, $user->saasu_file_id));
                if(!$saasu_get_company_response){
                    Log::warning("WebhookEventJob.handle - Saasu company <{$is_company_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return;
                }
                if(!$saasu_get_company_response->LastUpdatedId) {
                    Log::warning("WebhookEventJob.handle - Saasu company <{$is_company_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return;
                }
                
                $company_data['LastUpdatedId'] = $saasu_get_company_response->LastUpdatedId;
                $saasu_update_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateCompanyById($is_company_stored->saasu_company_id, $user->saasu_file_id, $company_data));
                if($saasu_update_company_response){
                    Log::info("WebhookEventJob.handle - Saasu company <{$is_company_stored->saasu_company_id}> is successfully updated");
                }

                return true;

            } else {
                $saasu_create_company_response = $saasuWorker->generateSaasuRequest($saasuWorker->createCompany($user->saasu_file_id, $company_data));
                if($saasu_create_company_response){
                    Company::create([
                        "hubspot_company_id" => $hubspot_company_id,
                        "saasu_company_id" => $saasu_create_company_response->InsertedCompanyId
                    ]);
                    Log::info("WebhookEventJob.handle - Saasu company <{$saasu_create_company_response->InsertedCompanyId}> is successfully created");
                }

                return true;
                
            }

            return true;

        } catch (Exception $e) {
            Log::error("WebhookEventJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_company_id" => $hubspot_company_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }
    }

    public function createOrUpdateInvoice($user, $event)
    {
        try {

            $hubspot_deal_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            Log::info("WebhookEventJob.handle - HubSpot dealId <{$hubspot_deal_id}> process", ["user" => $user->id]);
            $this->processInvoice($hubspot_deal_id, $user);

            return true;         

        } catch (Exception $e) {
            Log::error("WebhookEventJob.handle - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
        }

    }

    public function createOrUpdateLineItem($user, $event)
    {
        try {

            $line_item_id = isset($event->fromObjectId) ? $event->fromObjectId : $event->objectId;
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $deal_contact = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getAssociations($line_item_id, 'line_item', 'deal'));

            if(!$deal_contact['results']){
                Log::warning("WebhookEventJob.createOrUpdateLineItem - Line Item <{$line_item_id}> doesn't have associated deal. Abort the process.");
                return false;
            }

            $hubspot_deal_id = $deal_contact['results'][0]['toObjectId'];
            Log::info("WebhookEventJob.createOrUpdateLineItem - HubSpot dealId <{$hubspot_deal_id}> process", ["user" => $user->id, "deal_contact" => $deal_contact]);
            
            $this->processInvoice($hubspot_deal_id, $user);

            return true;         

        } catch (Exception $e) {
            Log::error("WebhookEventJob.createOrUpdateLineItem - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            throw new Exception($e->getMessage());
        }
    }

    public function processInvoice($hubspot_deal_id, $user)
    {
        try {

            /**
             * Get the deal details
             */
            $deal_data = [];
            $contact_data = [];
            $line_items = [];
            $total_amount = 0;
            $hubspotWorker = new HubSpotWorker($user->updateHubspotTokens());
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
            $deal = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealById($hubspot_deal_id));

            if(!$deal['properties']) {
                Log::info("WebhookEventJob.processInvoice - HubSpot deal id <{$hubspot_deal_id}> properties not found. Please see in HubSpot");
                return;
            }

            if(!$deal['properties']['dealstage']) {
                Log::info("WebhookEventJob.processInvoice - Invalid deal stage value. Please check the value. Sync process stopped");
                return;
            }

            Log::info("WebhookEventJob.processInvoice - Deal Property List", ['req' => [ 'deal_properties' => $deal['properties'], "deal_stage"  => $deal['properties']['dealstage']] ]);

            /**Local Saasu Account */
            if($user->saasu_file_id == "86536"){
                $deal_data["Currency"] = $deal['properties']['deal_currency'];
                if($deal['properties']['dealstage'] != config('hubspot.hubspot_test_quote_column_id')){
                    Log::warning("WebhookEventJob.processInvoice - HubSpot dealname {$deal['properties']['dealname']} and id <{$hubspot_deal_id}> hasn't been moved to Quote. Stopping the process.");
                    return;
                }
            }

            /**Live Saasu Account */
            if($user->saasu_file_id == "78831"){
                $deal_data["Currency"] = $deal['properties']['deal_currency_code'];
                if($deal['properties']['dealstage'] != config('hubspot.hubspot_ppa_quote_column_id') && $deal['properties']['dealstage'] != config('hubspot.husbpot_oparable_wall')){
                    Log::warning("WebhookEventJob.processInvoice - HubSpot dealname {$deal['properties']['dealname']} and id <{$hubspot_deal_id}> hasn't been moved to Quote. Stopping the process.");
                    return;
                }
            }
            
            /**
             * Get the associated contact id
             */
            $deal_contact = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealAssociation($hubspot_deal_id, "contact"));
            if(empty($deal_contact['results'])) {
                Log::warning("WebhookEventJob.processInvoice - DealId <{$hubspot_deal_id}> doesn't have associated contact. Stopping the process");
                return;
                abort(400, "WebhookEventJob.processInvoice - DealId <{$hubspot_deal_id}> doesn't have associated contact. Stopping the process");
            }
            $contact_id = $deal_contact['results'][0]['toObjectId'];
            $saasu_contact_id = '';

            /**
             * Check the contact id of exists 
             */
            $is_contact_stored = Contact::where("hubspot_contact_id", $contact_id)->first();

            if(!$is_contact_stored){
                Log::warning("WebhookEventJob.processInvoice - HubSpot Contact Id <{$contact_id}> doesn't exists in Saasu portal. Creating it now.");
                /**
                 * Get Contact deatails and format it
                 */
                $contact_data = [];
                $get_contact_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getContactById($contact_id));
                if(!$get_contact_request['properties']){
                    Log::warning("WebhookEventJob.processInvoice - Contact Id <{$contact_id}> properties not found. Please see in HubSpot");
                    return;
                }
                $contact_data = Helper::constructHubSpotContactDetails($get_contact_request);
                Log::info("WebhookEventJob.processInvoice - Saasu contact: Data", ["req" => [ "data" => $contact_data ] ]);

                /* Get the contact to get the LastUpdatedId needed for update*/
                $saasu_create_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->createContact($user->saasu_file_id, $contact_data));
                if($saasu_create_contact_response){
                    $saasu_contact_id = $saasu_create_contact_response->InsertedContactId;
                    Contact::create([
                        "hubspot_contact_id" => $contact_id,
                        "saasu_contact_id" => $saasu_create_contact_response->InsertedContactId
                    ]);
                    Log::info("WebhookEventJob.processInvoice - Saasu contact <{$saasu_create_contact_response->InsertedContactId}> is successfully created");
                }
            } else {
                $saasu_contact_id = $is_contact_stored->saasu_contact_id;
                Log::info("WebhookEventJob.processInvoice - HubSpot Contact Id <{$contact_id}> is stored.");
            }

            /**
             * Get the associated line item details
             */
            $deal_line_items = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getDealAssociation($hubspot_deal_id, "line_item"));
            if(empty($deal_line_items['results'])) {
                Log::warning("WebhookEventJob.processInvoice - No line items to process.  Sync process stopped.");
                return;
            }

            foreach ($deal_line_items['results'] as $key => $deal_line_item) {

                // $total_product_amount = 0;
                // $quantity = 0;
                $product_amount = 0;
                $line_item_data = [];
                $line_item = $hubspotWorker->generateHubSpotRequest($hubspotWorker->getLineItemById($deal_line_item['toObjectId']));
                if(!$line_item){
                    Log::warning("WebhookEventJob.processInvoice - Line Item id <{$deal_line_item['toObjectId']}> doesn't match to any of the line items in HubSpot.");
                    return ;
                }

                Log::info("WebhookEventJob.processInvoice - Line Item properties.", ["data" => $line_item]);
                /*
                * Set up the line item
                */
                $line_item_data["Description"] = $line_item['properties']['description'];
                $line_item_data["TaxCode"] = $line_item['properties']['tax_code'] ?? "G1";
                $line_item_data["TotalAmount"] = $line_item['properties']['amount'];
                $line_item_data["Quantity"] = $line_item['properties']['quantity'];
                $line_item_data["UnitPrice"] = $line_item['properties']['price'];
                $line_item_data["InventoryId"] = $line_item['properties']['item_id'];
                $line_item_data["ItemCode"] = $line_item['properties']['hs_sku'];
                $line_item_data["PercentageDiscount"] = $line_item['properties']['hs_discount_percentage'] ?? 0;
                $line_items[] = $line_item_data;
                $product_amount = $line_item['properties']['amount'] ?? 0;
                $total_amount += $product_amount;
                Log::info("WebhookEventJob.processInvoice - New Total Amount is {$total_amount}");
                // $quantity = $line_item['properties']['quantity'] ?? 0;
                // $total_product_amount = $product_amount * $quantity;
            }

            /*
            * Set up the invoice
            * Deal/Invoice properties: dealname,invoice_type,transaction_type,layout,transaction_date,amount
            */
           
            $deal_data["InvoiceType"] = "Quote";
            $deal_data["TransactionType"] = 'S';
            $deal_data["Layout"] = 'I';
            $deal_data["TransactionDate"] = date("Y-m-d");
            $deal_data["TotalAmount"] = $total_amount;
            $deal_data["BillingContactId"] = $saasu_contact_id;
            $deal_data["LineItems"] = $line_items;
                
            /**
             *  Create a Saasu invoice from HubSpot and store on company Table
             */
            Log::info("WebhookEventJob.processInvoice - Saasu invoice process", ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id, "deal_data" => $deal_data]);
            $is_deal_stored = Deal::where("hubspot_deal_id", $hubspot_deal_id)->first();

            if($is_deal_stored){

                /**
                 * Get the invoice to get the LastUpdatedId needed for update
                */
                $saasu_get_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->getInvoiceById($is_deal_stored->saasu_invoice_id, $user->saasu_file_id));
                Log::info("WebhookEventJob.processInvoice - get invoice data: ", [ "req" => [ "data" => $saasu_get_invoice_response ]]);

                if(!$saasu_get_invoice_response){
                    Log::warning("WebhookEventJob.processInvoice - Saasu company <{$is_deal_stored->saasu_contact_id}> unsuccessfully generated. Unable to get LastUpdatedId needed for update. Stopping the process.");
                    return;
                }
                if(!$saasu_get_invoice_response->LastUpdatedId) {
                    Log::warning("WebhookEventJob.processInvoice - Saasu company <{$is_deal_stored->saasu_contact_id}> doesn't have LastUpdatedId property. Stopping the process.");
                    return;
                }
                if(!$saasu_get_invoice_response->TransactionDate){
                    Log::warning("WebhookEventJob.processInvoice - Transaction Date doesn't exists for invoice id <{$is_deal_stored->saasu_invoice_id}>. Setting it to today's date.");
                    $deal_data["TransactionDate"] = date("Y-m-d");
                }

                $deal_data["InvoiceNumber"] = $saasu_get_invoice_response->InvoiceNumber;
                $deal_data['LastUpdatedId'] = $saasu_get_invoice_response->LastUpdatedId;
                $deal_data["TransactionDate"] = $saasu_get_invoice_response->TransactionDate;
                $saasu_update_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->updateInvoiceById($is_deal_stored->saasu_invoice_id, $user->saasu_file_id, $deal_data));
                if($saasu_update_invoice_response){
                    Log::info("WebhookEventJob.processInvoice - Saasu invoice/quote <{$is_deal_stored->saasu_invoice_id}> is successfully updated");
                }

            } else {
                $deal_data["InvoiceNumber"] = "<Auto Number>";
                /* Create Invoice to Saasu*/
                $saasu_create_invoice_response = $saasuWorker->generateSaasuRequest($saasuWorker->createInvoice($user->saasu_file_id, $deal_data));
                Log::info("WebhookEventJob.processInvoice - saasu_create_invoice_response", [ 'req' => [ "saasu_create_invoice_response" => $saasu_create_invoice_response ]]);

                if($saasu_create_invoice_response){
                    Deal::create([
                        "hubspot_deal_id" => $hubspot_deal_id,
                        "saasu_invoice_id" => $saasu_create_invoice_response->InsertedEntityId
                    ]);
                    Log::info("WebhookEventJob.processInvoice - Saasu invoice <{$saasu_create_invoice_response->InsertedEntityId}> is successfully created");
                }
            }

            $hubspot_deal_data["properties"]["amount"] = $total_amount * 1.1;
            $update_deal_request = $hubspotWorker->generateHubSpotRequest($hubspotWorker->updateDealById($hubspot_deal_id, $hubspot_deal_data));
            Log::info("WebhookEventJob.processInvoice - HubSpot with total amount {$total_amount} has been added 10%", ["req" => $update_deal_request]);

            return true;

        } catch (Exception $e) {
            Log::error("WebhookEventJob.processInvoice - Something has gone wrong. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "hubspot_deal_id" => $hubspot_deal_id],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            return false;
            // throw new Exception($e->getMessage());
        }
       
    }
}
