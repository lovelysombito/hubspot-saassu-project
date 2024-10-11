<?php
namespace App\Helpers;

use Exception;
use Illuminate\Support\Facades\Log;

class Helper {
    
    public static function checkContactType($contact_type, $contact_data)
    {
        $exploded_contact_type = explode(";", $contact_type);

        if(count($exploded_contact_type) > 1) {
           foreach($exploded_contact_type as $type){
                if(strtolower($type) == 'partner'){
                    $contact_data['IsPartner'] = true;
                }
        
                if(strtolower($type) == 'customer'){
                    $contact_data['IsCustomer'] = true;
                }
        
                if(strtolower($type) == 'supplier'){
                    $contact_data['IsSupplier'] = true;
                }
        
                if(strtolower($type) == 'contractor'){
                    $contact_data['IsContractor'] = true;
                }
           }
           return $contact_data;
        }

        if(!empty($exploded_contact_type[0])){
            $contact_type_prop = "is" . ucfirst($exploded_contact_type[0]);
            $contact_data[$contact_type_prop] = true;
        }
        return $contact_data;
    }

    public static function constructHubSpotContactDetails($contact)
    {
        $contact_properties = $contact['properties'];
        if(isset($contact_properties['phone'])){
            Log::info("phone: " . $contact_properties['phone']);
            $clean = preg_replace('/[^A-Za-z0-9\-]/', '', $contact_properties['phone']);
            Log::info("clean-phone: " . $clean);
            $final_main_ph = str_replace("-", "", $clean);
            Log::info("final_main_ph-phone: " . $final_main_ph);
        }

        $address = [
            'Street' => $contact_properties['address'] ?? '',
            'City' => $contact_properties['city'] ?? '',
            'State' => $contact_properties['state'] ?? '',
            'Postcode' => $contact_properties['zip'] ?? '',
            'Country' => $contact_properties['country'] ?? '',
        ];
        $contact_data = [
            'GivenName' => $contact_properties['firstname'] ?? '',
            'FamilyName' => $contact_properties['lastname'] ?? '',
            'EmailAddress' => $contact_properties['email'],
            // 'MobilePhone' => $contact_properties['mobilephone'] ?? '',
            'PrimaryPhone' => $final_main_ph ?? "",
            'Salutation' => $contact_properties['salutation'] ?? '',
            'PostalAddress' => $address,
            'OtherAddress' => $address,
        ];
        
        /**
         *  Get the value of contact type 
         */
        $contact_data_to_process = Helper::checkContactType($contact_properties['contact_type'], $contact_data);
        return $contact_data_to_process;

    }

    public static function constructHubSpotCompanyDetails($company)
    {
        $company_data = [];
        $company_properties = $company["properties"];

        $address = $company_properties['address'] ?? "";
        $city = $company_properties['city'] ?? "";
        $state = $company_properties['state'] ?? "";
        $country = $company_properties['country'] ?? "";
        $zip = $company_properties['zip'] ?? "";

        $company_data['Name'] = $company_properties['name'] ?? "";
        $company_data['CompanyEmail'] = $company_properties['email'] ?? "";
        $company_data['TradingName'] = $company_properties['trading_name'] ?? "";
        $company_data['LongDescription'] = "{$address} {$city} {$state} {$country} {$zip}";

        return $company_data;
    }

    public static function constructSaasuContactDetails($contact)
    {
        $contact_types = [];
        $contact_type_list = "";

        $contact_data['properties']['firstname'] = $contact->GivenName ?? '';
        $contact_data['properties']['lastname'] = $contact->FamilyName ?? '';
        $contact_data['properties']['email'] = $contact->EmailAddress ?? '';
        // $contact_data['properties']['mobilephone'] = $contact->MobilePhone ?? '';
        $contact_data['properties']['phone'] = $contact->PrimaryPhone ?? '';
        $contact_data['properties']['salutation'] = $contact->Salutation ?? '';

        $contact_data['properties']['address'] = $contact->PostalAddress->Street ?? '';
        $contact_data['properties']['city'] = $contact->PostalAddress->City ?? '';
        $contact_data['properties']['state'] = $contact->PostalAddress->State ?? '';
        $contact_data['properties']['zip'] = $contact->PostalAddress->Postcode ?? '';
        $contact_data['properties']['country'] = $contact->PostalAddress->Country ?? '';

        if($contact->IsPartner){
            // $contact_data['properties']['contact_type'] = 'partner';
            $contact_types[] = "partner";
        }

        if($contact->IsCustomer){
            // $contact_data['properties']['contact_type'] = 'customer';
            $contact_types[] = "customer";
        }

        if($contact->IsSupplier){
            // $contact_data['properties']['contact_type'] = 'supplier';
            $contact_types[] = "supplier";
        }

        if($contact->IsContractor){
            // $contact_data['properties']['contact_type'] = 'contractor';
            $contact_types[] = "contractor";
        }

        if($contact_types){
            $contact_type_list = implode(';', $contact_types);
        }

        Log::info("Helper.constructSaasuContactDetails - Contact Properties: ", [
            "req" => [
                "contact_types" => $contact_types,
                "contact_type_list" => $contact_type_list
            ]
        ]);

        $contact_data['properties']['contact_type'] = $contact_type_list;
       
        return $contact_data;
        
    }

    public static function constructSaasuInvoiceDetails($user, $invoice, $saasuWorker)
    {
        $deal_data = [];
        $total_amount = 0;
        $company_name = $invoice->BillingContactOrganisationName ? "- {$invoice->BillingContactOrganisationName}" : "";

        try {
            // $deal_data["properties"]['dealname'] = $invoice->InvoiceNumber ? "INV-" .$invoice->InvoiceNumber : "INV-TransactionID-".$invoice->TransactionId;
            $deal_data["properties"]['dealname'] = "{$invoice->BillingContactFirstName} {$invoice->BillingContactLastName} {$company_name}";
            $deal_data["properties"]['invoice_number'] = $invoice->InvoiceNumber ?? "";
            $amount = $invoice->TotalAmount ?? 0;
            // $gst = $invoice->TotalTaxAmount ?? 0;
            // $total_amount = $amount + $gst;
            $deal_data["properties"]['bank_account'] = "";
            $deal_data["properties"]['date_paid'] = "";
            $deal_data["properties"]['amount'] = $amount;

            /**Local Saasu Account */
            if($user->saasu_file_id == "86536"){
                $deal_data['properties']['deal_currency'] = $invoice->Currency;
            }

            /**Live Saasu Account */
            if($user->saasu_file_id == "78831"){
                $deal_data['properties']['deal_currency_code'] = $invoice->Currency;
            }

            if($invoice->PaymentStatus == "P"){
                $get_payment_request = $saasuWorker->generateSaasuRequest($saasuWorker->getPaymentUsingForInvoiceId($invoice->TransactionId, $user->saasu_file_id));
                if($get_payment_request->PaymentTransactions){
                    $deal_data["properties"]['date_paid'] = $get_payment_request->PaymentTransactions[0]?->TransactionDate ? date('Y-m-d', strtotime($get_payment_request->PaymentTransactions[0]->TransactionDate)) : "";
                    // $deal_data["properties"]['bank_account'] = $get_payment_request->PaymentTransactions[0]?->Summary ?? "";

                    /**
                     * Get the PaymentAccountId
                     */
                    if($get_payment_request->PaymentTransactions[0]->PaymentAccountId){
                        $get_payment_account_request = $saasuWorker->generateSaasuRequest($saasuWorker->getAccountByPaymentId($get_payment_request->PaymentTransactions[0]->PaymentAccountId, $user->saasu_file_id));
                        $deal_data["properties"]['bank_account'] = $get_payment_account_request->Name;
                    }
                }
            }
        } catch (Exception $e) {
            Log::error("Helper.constructSaasuInvoiceDetails - Something has gone wrong while contructing invoice properties. " . $e->getMessage(), [
                "event" => ["user" => $user->id, "saasu_invoice_id" => $invoice->TransactionId],
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);
        }
        
        return $deal_data;
    }
}

?>