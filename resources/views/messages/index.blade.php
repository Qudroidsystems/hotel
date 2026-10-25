@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .gm-bubble { border-radius: 14px; padding: 9px 12px; max-width: 520px; font-size: 14px; line-height: 1.45; white-space: pre-line; }
    .gm-bubble.out { background: rgba(15, 118, 110, .08); border: 1px solid rgba(15, 118, 110, .18); }
    .gm-bubble.in { background: rgba(183, 131, 47, .1); border: 1px solid rgba(183, 131, 47, .3); }
    .gm-unread { box-shadow: inset 4px 0 0 #b7832f; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Guest Messages" icon="ri-message-3-line"
               subtitle="Every SMS or WhatsApp sent to guests, what happened to it, and their replies.">
        @can('Manage hotel settings')
            <x-slot:actions>
                <a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'messaging']) }}"><i class="ri-settings-4-line"></i> Message settings</a>
            </x-slot:actions>
        @endcan
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if (! $ready['enabled'])
        <div class="cb-banner warning"><i class="ri-information-line"></i><div>Guest messages are switched off, so nothing is being sent. Turn them on under Hotel Settings → Guest Messages.</div></div>
    @elseif (! $ready['configured'])
        <div class="cb-banner warning"><i class="ri-information-line"></i><div>The {{ $ready['provider'] }} account isn't set up yet, so messages are logged but not sent. Still needed: @foreach ($ready['missing'] as $key)<strong>{{ $key }}</strong>{{ $loop->remaining > 1 ? ', ' : ($loop->remaining === 1 ? ' and ' : '') }}@endforeach. @can('Manage hotel settings')<a href="{{ route('settings.hotel.index', ['tab' => 'sms_accounts']) }}">Enter them under Hotel Settings → SMS &amp; WhatsApp Accounts</a>.@else An administrator enters them under Hotel Settings.@endcan</div></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Replies to read" :value="$unread" icon="ri-chat-unread-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Sent today" :value="(int) (($today['sent'] ?? 0) + ($today['delivered'] ?? 0) + ($today['read'] ?? 0))" icon="ri-send-plane-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Delivered today" :value="(int) (($today['delivered'] ?? 0) + ($today['read'] ?? 0))" icon="ri-check-double-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Not sent today" :value="(int) (($today['failed'] ?? 0) + ($today['skipped'] ?? 0))" icon="ri-error-warning-line" accent="rose" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Guest, number, booking reference or words…" aria-label="Search messages"></div>
            <select name="show" class="cb-select" onchange="this.form.submit()" aria-label="Show">
                @foreach (['all' => 'All messages', 'replies' => 'Replies from guests', 'failed' => 'Not sent or not delivered'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Search</button>
        </form>

        @if ($messages->isEmpty())
            <div class="empty-state py-5"><i class="ri-message-3-line"></i><h6>No messages here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Guest</th><th>Message</th><th>Status</th><th style="min-width:260px"></th></tr></thead>
                    <tbody>
                        @foreach ($messages as $m)
                            <tr class="{{ $m->direction === 'in' && ! $m->read_at ? 'gm-unread' : '' }}">
                                <td data-label="Guest">
                                    @if ($m->guest)<a href="{{ route('guests.show', $m->guest) }}" class="fw-semibold">{{ $m->guest->full_name }}</a>@else<span class="fw-semibold">Unknown number</span>@endif
                                    <div class="small text-muted">{{ $m->direction === 'in' ? $m->from : $m->to }}</div>
                                    @if ($m->reservation)<a href="{{ route('reservations.show', $m->reservation) }}" class="small">{{ $m->reservation->reference }}</a>@endif
                                </td>
                                <td data-label="Message">
                                    <div class="small text-muted mb-1"><i class="{{ $m->channel === 'whatsapp' ? 'ri-whatsapp-line' : 'ri-chat-1-line' }}"></i> {{ $m->channel_label }} · {{ $m->direction === 'in' ? 'from the guest' : $m->template_label . ($m->sender ? ' by ' . $m->sender->name : '') }} · {{ $m->created_at->format('D j M, g:i A') }}</div>
                                    <div class="gm-bubble {{ $m->direction }}">{{ $m->body }}</div>
                                </td>
                                <td data-label="Status"><span class="status-pill {{ $m->status_pill }}">{{ $m->status_label }}</span>
                                    @if ($m->error)<div class="small text-muted mt-1" style="max-width:220px">{{ $m->error }}</div>@endif</td>
                                <td data-label="">
                                    @if ($m->direction === 'in' && $m->guest)
                                        <form method="POST" action="{{ route('guests.messages.store', $m->guest) }}" class="d-flex gap-2" data-once>@csrf
                                            <input type="hidden" name="reply_to" value="{{ $m->id }}">
                                            <input name="body" class="form-control form-control-sm" maxlength="640" required placeholder="Reply…" aria-label="Reply to {{ $m->guest->full_name }}">
                                            <button class="action-btn btn-go"><i class="ri-send-plane-line"></i></button>
                                        </form>
                                        @if (! $m->read_at)<form method="POST" action="{{ route('messages.read', $m) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0">Mark as read</button></form>@endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $messages->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
