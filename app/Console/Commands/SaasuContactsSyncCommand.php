<?php

namespace App\Console\Commands;

use App\Jobs\CreateHubSpotContactJob;
use App\Jobs\UpdateHubSpotContactJob;
use App\Models\Contact;
use App\Models\User;
use App\Workers\SaasuWorker;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;


class SaasuContactsSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:saasu-contacts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Saasu Contacts to HubSpot Contacts';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info("SaasuContactsSyncCommand.handle - Sync command processing");
        $fromDate = date('Y-m-d');
        $toDate = date('Y-m-d', strtotime(' +1 day')) ;

        foreach (User::all() as $user) {

            Log::info("SaasuContactsSyncCommand.handle - Contact sync command start to process for Start date is <{$fromDate}> / End Date is <{$toDate}> ", [ "req" => [ "user" => $user] ]);

            if($user->hubSpotConnected() && $user->saasuConnected()){

                try {
                    $pageNumber = 1;
                    $total_contacts = 0;

                    do {
                        $saasuWorker = new SaasuWorker($user->updateSaasuTokens());
                        $get_contacts_request = $saasuWorker->generateSaasuRequest($saasuWorker->getContactsByDateRange($user->saasu_file_id, $fromDate, $toDate, $pageNumber));
                        $total_contacts = count($get_contacts_request->Contacts);
                        Log::info("SaasuContactsSyncCommand.handle - Total Contacts for Page <{$pageNumber}> : {$total_contacts}", [ "req" => [ "get_contacts_request" => $get_contacts_request ]]);

                        foreach ($get_contacts_request->Contacts as $contact) { 

                            Log::info("SaasuContactsSyncCommand.handle - Contact <{$contact->Id}> sync command start to process ", [ "req" => [ "contact" => $contact ] ]);
                            if($is_contact_stored = Contact::where("saasu_contact_id", $contact->Id)->first()){ 
                                UpdateHubSpotContactJob::dispatch($user, $is_contact_stored, $contact)->onConnection('sqs');
                                Log::info("SaasuContactsSyncCommand.handle - Contact <{$contact->Id}> is stored and has been successfully dispatched.");
                            } else {
                                CreateHubSpotContactJob::dispatch($user, $contact)->onConnection('sqs');
                                Log::info("SaasuContactsSyncCommand.handle - Contact <{$contact->Id}> is not stored, has been successfully dispatched.");
                            }
                            
                        }
                        $pageNumber++;

                    } while ($total_contacts != 0);

                } catch (Exception $e) {
                    Log::error("SaasuContactsSyncCommand.handle - Something has gone wrong. " . $e->getMessage(), [
                        "event" => ["user" => $user->id],
                        'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                    ]);            
                }

            }        
        }
    }

}
