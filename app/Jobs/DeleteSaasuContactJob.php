<?php

namespace App\Jobs;

use App\Workers\SaasuWorker;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSaasuContactJob implements ShouldQueue
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
        
        /**
         * Delete Saasu Contact
        */
        try {
            Log::info("DeleteSaasuContactJob.handle - Saasu contact delete", ["user" => $user->id, "saasu_contact_id" => $contact->saasu_contact_id]);
            $saasuWorker = new SaasuWorker($user->updateSaasuTokens());

            $saasu_delete_contact_response = $saasuWorker->generateSaasuRequest($saasuWorker->deleteContact($contact->saasu_contact_id, $user->saasu_file_id));
            if($saasu_delete_contact_response){
                Log::info("DeleteSaasuContactJob.handle - Saasu contact <{$contact->saasu_contact_id}> is successfully deleted");
                if(!$contact->delete()){
                    Log::error("DeleteSaasuContactJob.handle - An error occured while deleting contact in table.");
                } 
                Log::info("DeleteSaasuContactJob.handle - Saasu contact is successfully deleted in the table");
            }

        } catch (Exception $e) {
            Log::error("DeleteSaasuContactJob.handle - Something has gone wrong. " . $e->getMessage(), [
                'error' => ["message"=>$e->getMessage(), "code"=>$e->getCode(), "file"=>$e->getFile(), "line"=>$e->getLine()],
                'user' => "saasu_http_requests"
            ]);
        }
    }
}
