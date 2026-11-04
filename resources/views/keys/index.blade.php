@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $connected = $svc->connected(); @endphp
<style>
    .rk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }
    .rk-room { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-radius: 14px; padding: 14px 16px; }
    .rk-room header { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 8px; }
    .rk-no { min-width: 56px; padding: 5px 8px; border-radius: 10px; background: #14213d; color: #fff; font-weight: 800; font-size: 20px; text-align: center; font-variant-numeric: tabular-nums; }
    .rk-key { display: flex; gap: 8px; align-items: center; padding: 7px 0; border-top: 1px dashed var(--cb-border); font-size: 13.5px; }
    .rk-key .grow { flex: 1; min-width: 0; }
    .rk-pin { font: 700 18px/1 ui-monospace, Menlo, monospace; letter-spacing: 3px; }
    .rk-acts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .rk-acts form { display: flex; gap: 6px; flex: 1 1 auto; }
    .rk-acts input { flex: 1; min-width: 0; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-key-2-line" subtitle="Keys for every in-house room: the door code and phone key made at check-in, and key cards given at the desk. Every key stops working at check-out.">
        <x-slot:actions>
            @can('View door log')<a class="cb-hero-btn" href="{{ route('keys.log') }}"><i class="ri-door-open-line"></i> Door log</a>@endcan
            @if ($canManage)<a class="cb-hero-btn" href="{{ route('keys.locks') }}"><i class="ri-lock-2-line"></i> Link door locks</a>@endif
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'keys']) }}"><i class="ri-settings-3-line"></i> Lock system</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if (! $svc->enabled())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>Room keys are switched off. Choose your lock system in Hotel Settings → Room Keys: key cards made at the desk, TTLock smart locks, or another system through a bridge.</div></div>
    @elseif (! $connected)
        <div class="cb-banner info"><i class="ri-information-line"></i><div>{{ $provider->label() }}. @if ($provider->key() !== 'manual')The account isn't set up yet, so keys are recorded here only.@else Record the cards you give out, so you know which are out and which were lost.@endif</div></div>
    @elseif ($unlinked)
        <div class="cb-banner warning"><i class="ri-information-line"></i><div>{{ $unlinked }} {{ str('room')->plural($unlinked) }} have no lock linked: no codes or phone keys are made for them. <a href="{{ route('keys.locks') }}">Link door locks</a>.</div></div>
    @endif

    @if ($stays->isEmpty())
        <div class="empty-state py-5"><i class="ri-key-2-line"></i><h6>No guests in-house</h6><p class="small text-muted mb-0">Keys appear here when guests check in.</p></div>
    @else
    <div class="rk-grid">
        @foreach ($stays as $r)
            @foreach ($r->rooms as $line)
                @continue(! $line->room)
                @php $list = $keys->get($r->id . ':' . $line->room_id, collect()); @endphp
                <article class="rk-room">
                    <header>
                        <span class="rk-no">{{ $line->room->number }}</span>
                        <div class="grow"><strong>{{ $r->guest?->full_name }}</strong>@if ($r->guest?->is_vip) <span class="status-pill st-warning">VIP</span>@endif
                            <div class="small text-muted">{{ $r->reference }} · until {{ $r->check_out_date->format('D j M') }}{{ $line->room->lock_id ? '' : ' · no lock linked' }}</div></div>
                    </header>
                    @forelse ($list as $k)
                        <div class="rk-key">
                            <div class="grow">
                                <span class="fw-semibold">{{ $k->kind_label }}</span>
                                @if ($k->kind === 'pin' && $k->status === 'active')<span class="rk-pin ms-2">{{ $k->secret }}</span>@endif
                                @if ($k->card_number) <span class="text-muted">#{{ $k->card_number }}</span>@endif
                                <div class="small text-muted">{{ $k->status === 'failed' ? $k->note : 'Works until ' . $k->valid_until->format('D j M g:i A') }}{{ $k->last_used_at ? ' · last used ' . $k->last_used_at->diffForHumans() : '' }}</div>
                            </div>
                            <span class="status-pill {{ $k->status_pill }}">{{ $k->status_label }}</span>
                            @if ($canManage)
                                <form method="POST" action="{{ route('keys.revoke', $k) }}" data-once onsubmit="return confirm('Stop this key working?')">@csrf<button class="btn btn-sm btn-link text-danger p-0">Cancel</button></form>
                                @if ($k->kind === 'card')<form method="POST" action="{{ route('keys.revoke', $k) }}" data-once>@csrf<input type="hidden" name="lost" value="1"><button class="btn btn-sm btn-link p-0">Lost</button></form>@endif
                            @endif
                        </div>
                    @empty
                        <div class="small text-muted">No keys recorded.</div>
                    @endforelse
                    @if ($canManage)
                        <div class="rk-acts">
                            @if ($connected && $provider->makesPins() && $line->room->lock_id)
                                <form method="POST" action="{{ route('keys.act', [$r, $line->room, 'pin']) }}" data-once>@csrf<button class="action-btn btn-go"><i class="ri-lock-password-line"></i> New code</button></form>
                            @endif
                            <form method="POST" action="{{ route('keys.act', [$r, $line->room, 'card']) }}" data-once>@csrf<input name="card_number" class="form-control form-control-sm" maxlength="40" placeholder="Card no. (optional)"><button class="action-btn btn-more text-nowrap"><i class="ri-bank-card-line"></i> Card given</button></form>
                            @if ($connected && $provider->opensDoors() && $line->room->lock_id)
                                <form method="POST" action="{{ route('keys.act', [$r, $line->room, 'unlock']) }}" data-once>@csrf<input name="reason" class="form-control form-control-sm" maxlength="255" required placeholder="Why open it?"><button class="action-btn btn-more text-nowrap"><i class="ri-door-open-line"></i> Open door</button></form>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        @endforeach
    </div>
    @endif
</div>
</div>
</div>
@endsection
