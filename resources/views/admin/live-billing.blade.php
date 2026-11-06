@extends('layouts.app')

@section('title', 'Live Billing')

@section('content')
    <x-ui.page-header title="Live Billing" description="The counters in real time. View only: invoices are settled at the main cashier, or at a counter that takes payment." />

    <div class="h-[calc(100vh-12rem)] min-h-[32rem]">
        @include('pos.partials.live-billing')
    </div>
@endsection
