<?php

use App\Http\Controllers\AuthorisationController;
use App\Models\Item;
use App\Models\User;
use App\Workers\HubSpotWorker;
use App\Workers\SaasuWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {

    $a = 10;
    $b = 20;

    $arr = [$a, $b];
    return list($b, $a) = $arr;



    return "Welcome to Saasu <> HubSpot Integration";
});

Route::get('/debug-sentry', function () {
    throw new Exception('My first Sentry error!');
});

Route::group(['prefix' => 'health'], function () {
    Route::get('/', HealthCheckResultsController::class);
});

Route::group(['prefix' => 'hubspot'], function() {
    Route::group(['prefix' => 'auth'], function() {
        Route::match(['get', 'post'], '/callback', [AuthorisationController::class, 'hubspotCallback']);
    });
});

Route::group(['prefix' => 'saasu'], function() {
    Route::get('/authorisation-page/{user_id}', [AuthorisationController::class, 'saasuAuthorisationPage'])->name('saasu.authorisation.page');
    Route::post('/authorisation-process/{user_id}', [AuthorisationController::class, 'saasuAuthorisationProcess'])->name('saasu.authorisation.process');
});
