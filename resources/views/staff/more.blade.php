@extends('layouts.staff')

@section('content')
@php $me = auth()->user(); @endphp
<div class="sa-card d-flex align-items-center gap-3">
    <div class="sa-avatar" style="width:52px;height:52px;background:#0f766e;font-size:18px">{{ $me->initials }}</div>
    <div><div class="fw-bold" style="font-size:17px">{{ $me->name }}</div><div class="small text-muted">{{ $me->getRoleNames()->implode(', ') }}</div></div>
</div>

@php
    $links = array_filter([
        [route('attendance.my'), 'ri-time-line', 'My attendance & timesheet'],
        [route('it.tickets.index'), 'ri-customer-service-2-line', 'My IT requests'],
        $me->canAny(['Record expenses', 'View expenses']) ? [route('expenses.index'), 'ri-wallet-3-line', $me->canAny(['View expenses', 'Approve expenses']) ? 'Expenses' : 'My expenses'] : null,
        $me->canAny(['Do housekeeping tasks', 'View housekeeping', 'Manage housekeeping']) ? [route('housekeeping.index'), 'ri-brush-line', 'Housekeeping board'] : null,
        $me->canAny(['View maintenance', 'Report maintenance issues', 'Manage maintenance']) ? [route('maintenance.index'), 'ri-tools-line', 'Repairs list'] : null,
        $me->canAny(['View reservations', 'Check in guests']) ? [route('staff.front-desk'), 'ri-door-open-line', 'Front desk today'] : null,
        $me->can('View stock') ? [route('staff.stock'), 'ri-archive-line', 'Stock lookup'] : null,
        $me->canAny(['Use POS']) ? [route('pos.terminal'), 'ri-bank-card-line', 'Bar & restaurant till'] : null,
        $me->canAny(['View management reports']) ? [route('reports.index'), 'ri-line-chart-line', 'Management reports'] : null,
        [route('profile.settings', $me->id), 'ri-user-settings-line', 'My profile & password'],
        [route('dashboard'), 'ri-computer-line', 'Open the full system'],
    ]);
@endphp
<div class="sa-card p-0">
    @foreach ($links as [$url, $icon, $label])
        <a href="{{ $url }}" class="d-flex align-items-center gap-3 px-3 py-3 text-decoration-none {{ $loop->last ? '' : 'border-bottom' }}" style="color:var(--sa-text)">
            <i class="{{ $icon }}" style="font-size:21px;color:var(--sa-brand)"></i><span class="flex-grow-1 fw-semibold">{{ $label }}</span><i class="ri-arrow-right-s-line text-muted"></i></a>
    @endforeach
</div>

<details class="sa-card sa-more">
    <summary class="fw-bold"><i class="ri-smartphone-line"></i> Put the Staff App on your phone</summary>
    <ol class="small mt-2 mb-0 ps-3">
        <li><strong>Android (Chrome):</strong> open this page, tap the ⋮ menu → <em>Add to Home screen</em> / <em>Install app</em>.</li>
        <li><strong>iPhone (Safari):</strong> tap the Share button → <em>Add to Home Screen</em>.</li>
        <li>Open it from the new <strong>Staff</strong> icon — it opens full-screen like an app.</li>
    </ol>
    <div class="small text-muted mt-2">Works best over the hotel Wi-Fi. Screens you've opened before can still be viewed with no signal; actions need a connection.</div>
</details>

<form method="POST" action="{{ route('logout') }}">@csrf<button class="sa-btn bad w-100"><i class="ri-logout-box-r-line"></i> Sign out</button></form>
<div class="text-center small text-muted mt-3">{{ config('hotel.name') }} · {{ config('hotel.vendor') }}</div>
@endsection
