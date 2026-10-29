@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $tone = ['valid' => ['info', 'ri-coupon-3-line', 'Valid ticket'], 'admitted' => ['success', 'ri-shield-check-line', 'Let in'],
             'used' => ['danger', 'ri-close-circle-line', 'Already used'], 'void' => ['danger', 'ri-close-circle-line', 'Not valid'],
             'early' => ['warning', 'ri-time-line', 'Too early'], 'over' => ['warning', 'ri-time-line', 'Event over'], 'invalid' => ['danger', 'ri-close-circle-line', 'Not recognised']];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Check Tickets" icon="ri-qr-scan-2-line" subtitle="Scan the QR on a ticket (printed or on a phone), or type the code under it. Each ticket lets one holder in once."
               :back="route('events.index')" back-label="Events & Halls" />

    @include('rooms.partials.flash')

    @if ($result)
        @php [$cls, $icon, $label] = $tone[$result['state']]; $t = $result['ticket']; @endphp
        <div class="cb-banner {{ $cls }}" style="font-size: 1.15rem">
            <i class="{{ $icon }}"></i>
            <div><strong>{{ $label }}.</strong> {{ $result['message'] }}
                @if ($t)<div class="mt-1 small">{{ $t->code }} · {{ $t->event->title }} · {{ $t->event->hall?->name }} · bought by {{ $t->order->buyer_name }} ({{ $t->order->number }})</div>@endif
            </div>
        </div>
        @if ($result['state'] === 'valid')
            <form method="POST" action="{{ route('events.tickets.admit') }}" class="mb-3" data-once>@csrf<input type="hidden" name="code" value="{{ $t->code }}"><button class="action-btn btn-go" style="font-size:1.1rem"><i class="ri-login-box-line"></i> Let in</button></form>
        @endif
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <x-cb.card title="Scan ticket" icon="ri-camera-line">
                <div id="reader" class="rounded-3 overflow-hidden" style="max-width:420px"></div>
                <button type="button" class="action-btn btn-primary-cb mt-3" id="startScan"><i class="ri-camera-line"></i> Start camera</button>
                <div class="small text-muted mt-2" id="scanHint">The camera needs HTTPS (or localhost) and your permission. A handheld QR scanner can type into the box instead.</div>
            </x-cb.card>
        </div>
        <div class="col-lg-6">
            <x-cb.card title="Ticket code" icon="ri-keyboard-line">
                <form method="{{ $auto ? 'POST' : 'GET' }}" action="{{ $auto ? route('events.tickets.admit') : route('events.tickets.door') }}" id="checkForm" class="rm-form">
                    @if ($auto) @csrf <input type="hidden" name="auto" value="1"> @endif
                    <label for="code">Code printed under the QR</label>
                    <input type="text" name="code" id="code" class="form-control form-control-lg text-uppercase" required autofocus maxlength="200" placeholder="T7K3M9QX2AB4" autocomplete="off">
                    <button class="action-btn btn-primary-cb mt-3" type="submit"><i class="ri-search-line"></i> {{ $auto ? 'Check and let in' : 'Check' }}</button>
                </form>
                <div class="small mt-3">
                    @if ($auto)
                        <i class="ri-flashlight-line"></i> <strong>Fast lane:</strong> each valid ticket is let in as soon as it's scanned. <a href="{{ route('events.tickets.door') }}">Check first instead</a>
                    @else
                        Busy door? <a href="{{ route('events.tickets.door', ['auto' => 1]) }}">Let valid tickets in as soon as they're scanned</a>
                    @endif
                </div>
            </x-cb.card>
            @if ($today->isNotEmpty())
                <x-cb.card title="Doors open" icon="ri-door-open-line">
                    @foreach ($today as $row)
                        <div class="d-flex justify-content-between small py-1"><a href="{{ route('events.show', $row['event']) }}">{{ $row['event']->title }}</a><span><strong>{{ $row['in'] }}</strong> in of {{ $row['sold'] }} sold</span></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('checkForm'), code = document.getElementById('code');
    document.getElementById('startScan').addEventListener('click', async function () {
        if (!window.Html5Qrcode) { document.getElementById('scanHint').textContent = 'The scanner library could not load. Type the code instead.'; return; }
        this.disabled = true;
        try { sessionStorage.setItem('tkCam', '1'); } catch (e) {}
        const scanner = new Html5Qrcode('reader');
        try {
            await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, text => {
                scanner.stop().catch(() => {});
                code.value = text; form.submit();
            });
        } catch (e) {
            this.disabled = false;
            document.getElementById('scanHint').textContent = 'Could not start the camera: ' + (e?.message || e) + '. Type the code instead.';
        }
    });
    // At a busy door, keep the camera going from one ticket to the next.
    try { if (sessionStorage.getItem('tkCam') === '1') document.getElementById('startScan').click(); } catch (e) {}
});
</script>
@endsection
