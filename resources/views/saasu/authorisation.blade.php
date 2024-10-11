@extends('layout.app')
@section('title', 'Portable Partitions')

@section('content')
<div class="header-title text-center mt-5 mb-2">
    <h3>Saasu <> HubSpot Integration</h3>
</div>
<div class="row">
    <div class="col-6 offset-3">
        <div class="alert alert-success my-4">
            <center>Please enter your Saasu account details</center>
        </div>
        <p class="mt-2 mb-3">NOTE: Please make sure you enter the correct details to integrate properly.</p>
    </div>
    <div class="col-6 offset-3">
        <form method="POST" action="{{ route('saasu.authorisation.process', $user->id) }}">
        @csrf
        <div class="card mb-3">
            <div class="card-header">
                Integrations & API
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="cin7-username">Username</label>
                    <input type="text" class="form-control" id="cin7-username" name="saasu_username" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <label for="cin7-api-key">Password</label>
                    <input type="password" class="form-control" id="cin7-api-key" name="saasu_password" placeholder="Password" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-success">Submit</button>
                </div>
            </div>
        </div>
        </form>
    </div>
</div>  

@endsection