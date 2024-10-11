<?php

namespace App\Console\Commands;

use App\Jobs\CreateHubSpotCompanyJob;
use App\Jobs\UpdateHubSpotCompanyJob;
use App\Models\Company;
use App\Models\User;
use App\Workers\SaasuWorker;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SaasuCompaniesSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:saasu-companies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Saasu Companies to HubSpot Companies';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        /**
         * Set datetime for the day
         */
        Log::info("SaasuCompaniesSyncCommand.handle - Sync command processing");
        $fromDate = date('Y-m-d');
        $toDate = date('Y-m-d', strtotime(' +1 day')) ;

        foreach (User::all() as $user) { 

            Log::info("SaasuCompaniesSyncCommand.handle - Company sync command start to process for Start date is <{$fromDate}> / End Date is <{$toDate}>", [ "req" => [ "user" => $user] ]);

            /**
             * Check if the user is connected on both app
             */
            if($user->hubSpotConnected() && $user->saasuConnected()){

                try {
                    
                    $pageNumber = 1;
                    $fromDate = date('Y-m-d');
                    $toDate = date('Y-m-d', strtotime(' +1 day')) ;
                    $total_companies = 0;

                    do {
                        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                        $get_companies_request = $saasuWorker->generateSaasuRequest($saasuWorker->getCompaniesByDateRange($user->saasu_file_id, $fromDate, $toDate, $pageNumber));
                        $total_companies = count($get_companies_request->Companies);

                        Log::info("SaasuCompaniesSyncCommand.handle - Total Companies for Page <{$pageNumber}> : {$total_companies}", [ "req" => [ "get_companies_request" => $get_companies_request ]]);
                        foreach ($get_companies_request->Companies as $company) { 

                            Log::info("SaasuCompaniesSyncCommand.handle - Company sync command start to process ", [ "req" => [ "company" => $company ] ]);
                            if($is_company_stored = Company::where("saasu_company_id", $company->Id)->first()){ 
                                UpdateHubSpotCompanyJob::dispatch($user, $is_company_stored, $company)->onConnection('sqs');
                                Log::info("SaasuCompaniesSyncCommand.handle - Company id <{$company->Id}> is stored and has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            } else {
                                CreateHubSpotCompanyJob::dispatch($user, $company)->onConnection('sqs');
                                Log::info("SaasuCompaniesSyncCommand.handle - Company id <{$company->Id}> is not stored and has been successfully dispatched.", [
                                    "req" => ["user_id" => $user->id]
                                ]);
                            }

                        }
                        $pageNumber++;

                    } while($total_companies != 0);

                } catch (Exception $e) {
                    Log::error("SaasuCompaniesSyncCommand.handle - Something has gone wrong. " . $e->getMessage(), [
                        "event" => ["user" => $user->id],
                        'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                    ]);            
                }
            
            }
            
        }
    }
}
