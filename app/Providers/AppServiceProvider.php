<?php

namespace App\Providers;

use App\Models\Item;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        User::creating(function (User $user) {
            $user->id = Str::uuid();
        });

        Item::creating(function (Item $item) {
            $item->id = Str::uuid();
        });

        Contact::creating(function (Contact $contact) {
            $contact->id = Str::uuid();
        });

        Company::creating(function (Company $company) {
            $company->id = Str::uuid();
        });

        Deal::creating(function (Deal $deal) {
            $deal->id = Str::uuid();
        });


    }
}
