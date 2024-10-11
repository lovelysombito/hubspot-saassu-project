<?php

namespace App\Workers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class HubSpotWorker
{
    protected $token;
    protected $hubspot_api_domain = "https://api.hubapi.com";

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public static function getHubSpotToken($code)
    {
        try {

            $client = new Client();
            $request = $client->request('POST', 'https://api.hubapi.com/oauth/v1/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => env('HUBSPOT_CLIENT_ID'),
                    'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
                    'redirect_uri' => env('HUBSPOT_CALLBACK_URL'),
                    'code' => $code
                ]
            ]);

            $response = json_decode($request->getBody()->getContents());
            return $response;

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function generateHubSpotRequest(array $data)
    {
        try {
            $response = (new Client())->request($data['method'], "{$this->hubspot_api_domain}/{$data['path']}", [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$this->token}"
                ],
                'json' => $data['body']
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            Log::error("HubSpotWorker.generateHubSpotRequest - {$e->getMessage()}, response code: {$response->getStatusCode()}");
            throw new Exception($e->getMessage());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this->parseHubSpotResponse(json_decode($response->getBody()->getContents(), true));
    }

    public function generateGetAccessToken()
    {
        return [
            'method' => 'GET',
            'path' => "oauth/v1/access-tokens/{$this->token}",
            'body' => []
        ];
    }

    public static function getRefreshToken($refresh_token)
    {
        try {
            $request = (new Client())->request('POST', 'https://api.hubapi.com/oauth/v1/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => env('HUBSPOT_CLIENT_ID'),
                    'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
                    'refresh_token' => $refresh_token
                ]
            ]);

            return json_decode($request->getBody()->getContents());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    private function parseHubSpotResponse($input)
    {
    	if (!isset($input['errors'])) { return $input; }
    	$message = $input['errors'][0]['message'] ?? "Invalid Request";
        Log::error($input['message'] ?? $message);
        throw new Exception($input['message'] ?? $message);
    }

    public function getContactById($contact_id)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/objects/contacts/{$contact_id}?properties={$this->getEnvContactProperties()}",
            'body' => []
        ];
    }

    public function createContact($body)
    {
        return [
            'method' => 'POST',
            'path' => "crm/v3/objects/contacts",
            'body' => $body
        ];
    }

    public function updateContactById($contact_id, $body)
    {
        return [
            'method' => 'PATCH',
            'path' => "crm/v3/objects/contacts/{$contact_id}",
            'body' => $body
        ];
    }

    public function getCompanyById($company_id)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/objects/companies/{$company_id}?properties={$this->getEnvCompanyProperties()}",
            'body' => []
        ];
    }

    public function createCompany($body)
    {
        return [
            'method' => 'POST',
            'path' => "crm/v3/objects/companies",
            'body' => $body
        ];
    }

    public function updateCompanyById($company_id, $body)
    {
        return [
            'method' => 'PATCH',
            'path' => "crm/v3/objects/companies/{$company_id}",
            'body' => $body
        ];
    }
    
    public function getDealById($deal_id)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/objects/deals/{$deal_id}?properties={$this->getEnvDealProperties()}",
            'body' => []
        ];
    }

    public function updateDealById($deal_id, $body)
    {
        return [
            'method' => 'PATCH',
            'path' => "crm/v3/objects/deals/{$deal_id}",
            'body' => $body
        ];
    }

    public function createDeal($body)
    {
        return [
            'method' => 'POST',
            'path' => "crm/v3/objects/deals",
            'body' => $body
        ];
    }

    public function getLineItemById($line_item_id)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/objects/line_items/{$line_item_id}?properties={$this->getEnvProductProperties()}",
            'body' => []
        ];
    }

    public function getLineItems()
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/objects/line_items?limit=100&properties={$this->getEnvProductProperties()}",
            'body' => []
        ];
    }

    public function getProperties($object_type)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v3/properties/{$object_type}",
            'body' => []
        ];
    }

    public function getDealAssociation($deal_id, $object_type)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v4/objects/deals/{$deal_id}/associations/{$object_type}?properties={$this->getEnvDealProperties()}",
            'body' => []
        ];
    }

    public function createDealAssociation($deal_id, $object_type, $object_id)
    {
        return [
            'method' => 'PUT',
            'path' => "crm/v4/objects/deals/{$deal_id}/associations/{$object_type}/{$object_id}",
            'body' => []
        ];
    }

    public function getContactAssociation($contact_id, $object_type)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v4/objects/contacts/{$contact_id}/associations/{$object_type}",
            'body' => []
        ];
    }

    public function createContactAssociation($contact_id, $object_type, $object_id)
    {
        return [
            'method' => 'PUT',
            'path' => "crm/v4/objects/contacts/{$contact_id}/associations/{$object_type}/{$object_id}",
            'body' => []
        ];
    }

    public function createProduct($product_body)
    {
        return [
            'method' => "POST",
            'path' => "crm/v3/objects/products",
            'body' => $product_body
        ];
    }

    public function updateProductById($product_id, $product_body)
    {
        return [
            'method' => "PATCH",
            'path' => "crm/v3/objects/products/{$product_id}",
            'body' => $product_body
        ];
    }

    public function getProductById($product_id)
    {
        return [
            "method" => "GET",
            "path" => "crm/v3/objects/products/{$product_id}",
            "body" => []
        ];
    }

    private function getEnvContactProperties()
    {
        $contact_properties = explode(',', env('HUBSPOT_CONTACT_PROPERTIES'));
        $contact_properties = implode('%2C', array_map(
            function ($v, $k) {
                return "{$v}";
            },
            $contact_properties,
            array_keys($contact_properties)
        ));

        return $contact_properties;
    }

    private function getEnvCompanyProperties()
    {
        $company_properties = explode(',', env('HUBSPOT_COMPANY_PROPERTIES'));
        $company_properties = implode('%2C', array_map(
            function ($v, $k) {
                return "{$v}";
            },
            $company_properties,
            array_keys($company_properties)
        ));

        return $company_properties;
    }

    private function getEnvDealProperties()
    {
        $deal_properties = explode(',', env('HUBSPOT_DEAL_PROPERTIES'));
        $deal_properties = implode('%2C', array_map(
            function ($v, $k) {
                return "{$v}";
            },
            $deal_properties,
            array_keys($deal_properties)
        ));

        return $deal_properties;
    }

    private function getEnvProductProperties()
    {
        $product_properties = explode(',', env('HUBSPOT_PRODUCT_PROPERTIES'));
        $product_properties = implode('%2C', array_map(
            function ($v, $k) {
                return "{$v}";
            },
            $product_properties,
            array_keys($product_properties)
        ));

        return $product_properties;
    }

    public function hubSpotSearch($property_name, $property_value)
    {
        return [
            'method' => "POST",
            'path' => "crm/v3/objects/products/search",
            'body' => [
                "filterGroups" => [
                    [
                        "filters" => [
                            [
                                "propertyName" => $property_name, 
                                "operator" => "EQ", 
                                "value" => $property_value 
                            ] 
                        ], 
                        "sorts" => [
                            [
                                "propertyName" => "createdate", 
                                "direction" => "DESCENDING" 
                            ] 
                        ] 
                    ] 
                ] 
            ]
        ];
    }

    public function getAssociations($object_id, $from_object_type, $to_object_type)
    {
        return [
            'method' => 'GET',
            'path' => "crm/v4/objects/{$from_object_type}/{$object_id}/associations/{$to_object_type}",
            'body' => []
        ];
    }

    public function getContactProfile($email)
    {
        return [
            'method' => 'GET',
            'path' => "contacts/v1/contact/email/{$email}/profile",
            'body' => []
        ];
    }

}

?>