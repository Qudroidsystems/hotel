@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-mail-open-line" :subtitle="$message->subject" :back="route('website.enquiries.index')" back-label="Enquiries">
        <x-slot:actions>
            <a class="cb-hero-btn" href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: ' . $message->subject) }}"><i class="ri-reply-line"></i> Reply by e-mail</a>
            @if ($message->phone)<a class="cb-hero-btn" href="tel:{{ preg_replace('/[^0-9+]/', '', $message->phone) }}"><i class="ri-phone-line"></i> Call</a>@endif
        </x-slot:actions>
        <x-slot:pills><span class="cb-meta-pill">{{ $message->kind_label }}</span><span class="cb-meta-pill">{{ $message->created_at->format('D j M Y, g:i A') }}</span></x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Message" icon="ri-chat-3-line">
                <dl class="row mb-3">
                    <dt class="col-sm-3">From</dt><dd class="col-sm-9">{{ $message->name }}</dd>
                    <dt class="col-sm-3">E-mail</dt><dd class="col-sm-9"><a href="mailto:{{ $message->email }}">{{ $message->email }}</a></dd>
                    @if ($message->phone)<dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $message->phone) }}">{{ $message->phone }}</a></dd>@endif
                    @foreach ($message->detail_lines as $k => $v)<dt class="col-sm-3">{{ $k }}</dt><dd class="col-sm-9">{{ $v }}</dd>@endforeach
                </dl>
                <div class="p-3 rounded" style="background:var(--cb-surface-2);white-space:pre-wrap">{{ $message->message ?: '(no message)' }}</div>
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <form method="POST" action="{{ route('website.enquiries.update', $message) }}" class="cb-card">
                @csrf @method('PUT')
                <div class="cb-card-header"><h5><i class="ri-check-double-line"></i>Follow-up</h5></div>
                <div class="cb-card-body">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select mb-3">@foreach (\App\Models\Website\WebsiteMessage::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($message->status === $k)>{{ $l }}</option>@endforeach</select>
                    <label class="form-label fw-semibold" for="note">Note for the team</label>
                    <textarea id="note" name="note" class="form-control" rows="4" maxlength="2000" placeholder="e.g. Sent a quote for the Grand Ballroom">{{ old('note', $message->note) }}</textarea>
                    @if ($message->handler)<p class="small text-muted mt-2 mb-0">Last handled by {{ $message->handler->name }}, {{ $message->handled_at?->diffForHumans() }}.</p>@endif
                </div>
                <div class="cb-card-body border-top"><button class="btn btn-primary w-100"><i class="ri-save-3-line me-1"></i> Save</button></div>
            </form>
        </div>
    </div>

</div>
</div>
</div>
@endsection
