<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Workers\HubSpotWorker;
use App\Workers\SaasuWorker;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class AuthorisationController extends Controller
{
    public function hubspotCallback(Request $request)
    {
        Log::info("AuthorisationController.hubspotCallback - HubSpot Authorisation starting to process.");

        try {
            Log::info("AuthorisationController.hubspotCallback - HubSpot Generating tokens.");
            $token = HubSpotWorker::getHubSpotToken($request->code);
            $access_token = $token->access_token;
            $refresh_token = $token->refresh_token;

            $hubspot_worker = new HubSpotWorker($access_token);
            $authenticated_user = $hubspot_worker->generateHubSpotRequest($hubspot_worker->generateGetAccessToken());           

            if($authenticated_user) {
                $user = User::where([
                    ['hubspot_user_id', $authenticated_user['user_id']],
                    ['hubspot_account_id', $authenticated_user['hub_id']]
                ])->first();
                
                if($user){
                    $user->update([
                        'hubspot_access_token' => $access_token,
                        'hubspot_refresh_token' => $refresh_token,
                        'hubspot_state' => "CONNECTED",
                        
                    ]);
                    Log::info("AuthorisationController.hubspotCallback - User exists in the table, refresh token and access token updated. Redirecting to Saasu Auth Page.");
                    return redirect()->route('saasu.authorisation.page', $user->id);
                } else {
                    $new_user = User::create([
                        'hubspot_account_id' => $authenticated_user['hub_id'],
                        'hubspot_user_id' => $authenticated_user['user_id'],
                        'hubspot_refresh_token' => $refresh_token,
                        'hubspot_access_token' => $access_token,
                        'hubspot_state' => "CONNECTED",
                    ]);
                    if ($new_user) {
                        session(['user' => $new_user, 'user_in_session' => $authenticated_user['user']]);
                        Log::info("AuthorisationController.hubspotCallback - HubSpot Authorisation successfully done! Redirecting to Saasu Auth Page.");
                    }
                }
                return redirect()->route('saasu.authorisation.page', $new_user->id);
            }

            return response()->json([
                "code" => 500,
                "message" => "An error occured while processing authorisation"
            ]);

        } catch (Exception $e) {
            Log::error("AuthorisationController.hubspotCallback - {$e->getMessage()}");
            throw new Exception($e->getMessage());
        }

    }

    public function saasuAuthorisationPage(Request $request, $user_id)
    {
        if (!$user = User::find($user_id)) {
            Log::info("AuthorisationController.saasuAuthorisationPage - User <{$user_id}> not found. Aborting process");
            abort(404);
        }

        return view('saasu.authorisation', compact('user'));
    }

    public function saasuAuthorisationProcess(Request $request, $user_id)
    {
        $request->validate([
            'saasu_username' => 'required',
            'saasu_password' => 'required',
        ]);

        if (!$user = User::find($user_id)) {
            Log::info("AuthorisationController.saasuAuthorisationProcess - User <{$user_id}> not found");
            abort(404);
        }

        try {

            Log::info("AuthorisationController.saasuAuthorisationProcess - Saasu Generating tokens.");
            $saasu_tokens = SaasuWorker::generateSaasuAccessToken($request->saasu_username, $request->saasu_password);
            $scope = $saasu_tokens->scope;
            $exploded = explode("fileid:", $scope);
            $final_id = end($exploded);
            
            $user->update([
                'saasu_username' => Crypt::encryptString($request->saasu_username),
                'saasu_password' => Crypt::encryptString($request->saasu_password),
                'saasu_state' => "CONNECTED",
                'saasu_access_token' => $saasu_tokens->access_token,
                'saasu_refresh_token' => $saasu_tokens->refresh_token,
                'saasu_file_id' => $final_id
            ]);
            Log::info("AuthorisationController.saasuAuthorisationProcess - Saasu Authorisation Successfully done.");
            return view('saasu.successPage');

        } catch (Exception $e) {
            Log::error("AuthorisationController.saasuAuthorisationProcess - {$e->getMessage()}");
            throw new Exception($e->getMessage());
        }
    }
}
