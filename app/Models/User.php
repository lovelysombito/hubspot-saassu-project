<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Workers\HubSpotWorker;
use App\Workers\SaasuWorker;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        "hubspot_account_id",
        "hubspot_user_id",
        "hubspot_refresh_token",
        "hubspot_access_token",
        "hubspot_state",
        "saasu_username",
        "saasu_password",
        "saasu_access_token",
        "saasu_refresh_token",
        "saasu_state",
        "saasu_file_id"
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    const SAASU_ACCESS_TOKEN_EXPIRY = 10800;

    public function updateHubspotTokens()
    {
        $tokens = HubSpotWorker::getRefreshToken($this->hubspot_refresh_token);
        $this->update([
            'hubspot_access_token' => $tokens->access_token,
            'hubspot_refresh_token' => $tokens->refresh_token,
        ]);
        return $this->hubspot_access_token;
    }

    public function updateSaasuTokens()
    {
        $tokens = SaasuWorker::getRefreshToken($this->saasu_refresh_token);
        $scope = str_replace('fileid:', '', $tokens->scope);
        $this->update([
            'saasu_access_token' => $tokens->access_token,
            'saasu_refresh_token' => $tokens->refresh_token,
            'saaus_file_id' =>  $scope
        ]);
        return $this->saasu_access_token;
    }

    public function hubSpotConnected()
    {
        return $this->hubspot_state == "CONNECTED";
    }
    
    public function saasuConnected()
    {
        return $this->saasu_state == "CONNECTED";
    }


}
