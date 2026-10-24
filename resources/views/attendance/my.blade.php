@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="My Attendance" icon="ri-user-follow-line" subtitle="Your clock-ins, lateness and leave" />
    <div class="cb-card"><div class="empty-state py-5"><i class="ri-user-follow-line"></i><h6>You're not set up for attendance yet</h6>
        <p class="small text-muted mb-0">Ask HR to add you — they'll give you a staff badge to scan at the staff entrance.</p></div></div>
</div>
</div>
</div>
@endsection
