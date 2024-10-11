<?php


namespace App\Workers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class SaasuWorker
{
    protected $saasu_api_domain = 'https://api.saasu.com';
    protected $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public static function generateSaasuAccessToken($saasu_username, $saasu_password)
    {
        Log::info("SaasuWorker.generateSaasuAccessToken - Generating Access Token");
        try {
            $request = (new Client())->request("POST", "https://api.saasu.com/authorisation/token", [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [
                        "grant_type" => "password",
                        "username" => $saasu_username,
                        "password" => $saasu_password,
                        "scope" => "full",
                ]
            ]);

            return $response = json_decode($request->getBody()->getContents());

        } catch (ClientException $e) {
            $response = $e->getResponse();
            Log::error("SaasuWorker.generateSaasuAccessToken - {$e->getMessage()}, response code: {$response->getStatusCode()}");
            throw new Exception($e->getMessage());
        } catch (Exception $e) {
            Log::error("SaasuWorker.generateSaasuAccessToken - Error while generating access token for Saasu{$e->getMessage()}");
            throw new Exception($e->getMessage());
        }

    }

    public static function getRefreshToken($refresh_token)
    {
        try {
            $request = (new Client())->request('POST', 'https://api.saasu.com/authorisation/refresh', [
                'json' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token'=> $refresh_token
                ]
            ]);

            return json_decode($request->getBody()->getContents());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    
    public function generateSaasuRequest(array $data)
    {
        try {
            $request = (new Client())->request($data['method'], "{$this->saasu_api_domain}/{$data['path']}", [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$this->token}"
                ],
                'json' => $data['body']
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            Log::error("SaasuWorker.generateHubSpotRequest - Something has gone wrong. ", [
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
            ]);
            throw new Exception($e->getMessage());
        } catch (Exception $e) {
            Log::error("SaasuWorker.generateHubSpotRequest - Something has gone wrong. " . $e->getMessage(), [
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
            throw new Exception($e->getMessage());
        }

        return json_decode($request->getBody()->getContents());

    } 

    public function getContactsByModifiedDate($file_id, $start_date, $end_date)
    {
        return [
            'method' => 'GET',
            'path' => "contacts?fileId={$file_id}&IsActive=true&LastModifiedFromDate={$start_date}&LastModifiedToDate={$end_date}&PageSize=1000",
            'body' => []
        ];
    }

    public function getContactsByDateRange($file_id, $lastModifiedFromDate, $lastModifiedToDate, $pageNumber)
    {
        return [
            'method' => 'GET',
            'path' => "contacts?fileId={$file_id}&LastModifiedFromDate={$lastModifiedFromDate}&LastModifiedToDate={$lastModifiedToDate}&Page={$pageNumber}&IsActive=true",
            
            'body' => []
        ];
    }

    public function getContactById($contact_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "contact/{$contact_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function updateContactById($contact_id, $file_id, $body)
    {
        return [
            'method' => 'PUT',
            'path' => "contact/{$contact_id}?fileId={$file_id}",
            'body' => $body
        ];
    }

    public function createContact($file_id, $body)
    {
        return [
            'method' => 'POST',
            'path' => "contact?fileId={$file_id}",
            'body' => $body
        ];
    }

    public function deleteContact($contact_id, $file_id)
    {
        return [
            'method' => 'DELETE',
            'path' => "contact/{$contact_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function getCompaniesWithModifiedDate($file_id, $start_date, $end_date)
    {
        return [
            'method' => 'GET',
            'path' => "companies?fileId={$file_id}&IsActive=true&LastModifiedFromDate={$start_date}&LastModifiedToDate={$end_date}&PageSize=1000",
            'body' => []
        ];
    }

    public function getCompaniesByDateRange($file_id, $lastModifiedFromDate, $lastModifiedToDate, $pageNumber)
    {
        return [
            'method' => 'GET',
            'path' => "companies?fileId={$file_id}&LastModifiedFromDate={$lastModifiedFromDate}&LastModifiedToDate={$lastModifiedToDate}&Page={$pageNumber}&IsActive=true",
            'body' => []
        ];
    }

    public function getCompanyById($company_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "company/{$company_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function updateCompanyById($company_id, $file_id, $body)
    {
        return [
            'method' => 'PUT',
            'path' => "company/{$company_id}?fileId={$file_id}",
            'body' => $body
        ];
    }

    public function createCompany($file_id, $body)
    {
        return [
            'method' => 'POST',
            'path' => "company?fileId={$file_id}",
            'body' => $body
        ];
    }

    public function deleteCompany($company_id, $file_id)
    {
        return [
            'method' => 'DELETE',
            'path' => "company/{$company_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function getInvoiceById($invoice_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "invoice/{$invoice_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function createInvoice($file_id, $invoice_data)
    {
        return [
            'method' => 'POST',
            'path' => "invoice?fileId={$file_id}",
            'body' => $invoice_data
        ];
    }

    public function updateInvoiceById($invoice_id, $file_id, $invoice_data)
    {
        return [
            'method' => 'PUT',
            'path' => "Invoice/$invoice_id?fileId={$file_id}",
            'body' => $invoice_data
        ];
    }

    public function deleteInvoice($invoice_id, $file_id)
    {
        return [
            'method' => 'DELETE',
            'path' => "invoice/$invoice_id?fileId={$file_id}",
            'body' => []
        ];
    }

    public function getInvoicesWithModifiedDate($file_id, $start_date, $end_date)
    {
        return [
            'method' => 'GET',
            'path' => "invoices?fileId={$file_id}&IsActive=true&LastModifiedFromDate={$start_date}&LastModifiedToDate={$end_date}&PageSize=1000",
            'body' => []
        ];
    }

    public function getInvoicesByDateRange($file_id, $lastModifiedFromDate, $lastModifiedToDate, $pageNumber)
    {
        return [
            'method' => 'GET',
            'path' => "invoices?fileId={$file_id}&LastModifiedFromDate={$lastModifiedFromDate}&LastModifiedToDate={$lastModifiedToDate}&Page={$pageNumber}&IsActive=true",
            'body' => []
        ];
    }

    public function getPaymentUsingForInvoiceId($invoice_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "payments?ForInvoiceId={$invoice_id}&fileId={$file_id}",
            'body' => []
        ];
    }

    public function getItemsWithModifiedDate($file_id, $start_date, $end_date)
    {
        return [
            'method' => 'GET',
            'path' => "items?fileId={$file_id}&IsActive=true&LastModifiedFromDate={$start_date}&LastModifiedToDate={$end_date}&PageSize=1000",
            'body' => []
        ];
    }

    public function getItemById($item_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "item/{$item_id}?fileId={$file_id}",
            'body' => []
        ];
    }

    public function getItemsByDateRange($file_id, $lastModifiedFromDate, $lastModifiedToDate, $pageNumber)
    {
        return [
            'method' => 'GET',
            'path' => "items?fileId={$file_id}&LastModifiedFromDate={$lastModifiedFromDate}&LastModifiedToDate={$lastModifiedToDate}&Page={$pageNumber}&IsActive=true",
            'body' => []
        ];
    }
  
    public function getAccountByPaymentId($payment_account_id, $file_id)
    {
        return [
            'method' => 'GET',
            'path' => "account/$payment_account_id?fileId={$file_id}",
            'body' => []
        ];
    }

    public function search($file_id)
    {
        return [
            'method' => 'GET',
            'path' => "search?Keywords=Combo Items&Scope=ComboItems&FileId={$file_id}",
            'body' => []
        ]; 
    }

}

?>
