@extends('layout.app')
@section('title', 'Portable Partitions')

@section('content')
<div class="header-title text-center mt-5 mb-2">
    <h1>THANK YOU!</h1>
</div>
<div class="row">
    <div class="col-6 offset-3">
        <div class="alert alert-success text-center my-4">
            Authorisation successfully done. You can start the integration by going back to your HubSpot Account.
        </div>
    </div>
</div>
@endsection
@push('scripts')
    <script>
        console.log('scripts... @CIN7Authorisation')
    </script>
@endpush
