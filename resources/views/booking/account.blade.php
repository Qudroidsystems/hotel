@extends('layouts.guest', ['title' => 'Your account', 'noindex' => true])

@section('content')
<div class="wrap narrow">
    <div class="acct-head">
        <div>
            <h1 class="serif">{{ $account['first_name'] ? 'Hello, ' . $account['first_name'] : 'Your account' }}</h1>
            <p class="muted">{{ $account['contact'] }}</p>
        </div>
        <form method="POST" action="{{ route('account.logout') }}">@csrf<button class="btn quiet" type="submit"><i class="ri-logout-box-r-line"></i> Sign out</button></form>
    </div>
    @include('rooms.partials.flash-guest')

    @foreach ($account['memberships'] as $m)
        <div class="panel member"><i class="ri-vip-crown-2-line"></i><div><strong>{{ $m['tier_label'] }} · {{ number_format($m['points']) }} points</strong><div class="small muted">{{ $m['number'] }} · worth {{ $m['value_label'] }} · {{ $m['hotel'] }}</div></div></div>
    @endforeach

    <h2 class="serif">Your bookings</h2>
    @forelse ($account['stays'] as $s)
        <a class="panel stay" href="{{ route('stay.show', $s['token']) }}">
            <div>
                <strong>{{ $s['check_in_label'] }} · {{ $s['nights'] }} {{ Str::plural('night', $s['nights']) }}</strong>
                <div class="small muted">{{ $s['room_types'] }} · {{ $s['hotel']['name'] }} · {{ $s['reference'] }}</div>
            </div>
            <span class="tag {{ $s['state'] }}">{{ $s['status_label'] }}</span>
        </a>
    @empty
        <div class="panel"><p class="muted" style="margin:0">No bookings yet with {{ $account['contact'] }}.</p></div>
    @endforelse
    @if (config('hotel.booking.enabled', true))<p><a href="{{ route('book.index') }}" class="btn"><i class="ri-calendar-check-line"></i> Book a stay</a></p>@endif
</div>
@endsection

@push('head')
<style>
    .narrow { max-width: 620px; padding-top: 36px; }
    .narrow h1 { font-size: 34px; margin-bottom: 4px; }
    .narrow h2 { font-size: 24px; margin: 26px 0 10px; }
    .acct-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
    .panel.stay { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; color: inherit; text-decoration: none; }
    .panel.stay:hover { border-color: var(--ink-2); }
    .panel.member { display: flex; gap: 12px; align-items: center; margin: 14px 0; }
    .panel.member i { font-size: 26px; color: #b8893b; }
    .tag { font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 20px; white-space: nowrap; background: #eef2f3; }
    .tag.ok { background: #e3f6ec; color: #11734b; }
    .tag.warn { background: #fff4dc; color: #8a5a00; }
    .tag.bad { background: #fde8e6; color: #a8322b; }
</style>
@endpush
