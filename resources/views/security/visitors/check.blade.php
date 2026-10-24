@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $tone = ['valid' => ['success', 'ri-shield-check-line', 'Valid pass'], 'expired' => ['warning', 'ri-time-line', 'Pass expired'],
             'used' => ['danger', 'ri-close-circle-line', 'Already signed out'], 'invalid' => ['danger', 'ri-close-circle-line', 'Not recognised']];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-qr-scan-2-line" subtitle="Scan the QR on a visitor's pass, or type the code under it."
               :back="route('visitors.index')" back-label="Visitors" />

    @include('rooms.partials.flash')

    @if ($result)
        @php [$cls, $icon, $label] = $tone[$result['state']]; $v = $result['visitor']; @endphp
        <div class="cb-banner {{ $cls }}" style="font-size: 1.05rem">
            <i class="{{ $icon }}"></i>
            <div><strong>{{ $label }}.</strong> {{ $result['message'] }}
                @if ($v)<div class="mt-1">{{ $v->full_name }}{{ $v->party_size > 1 ? ' (+' . ($v->party_size - 1) . ')' : '' }} · {{ $v->purpose_label }} · visiting {{ $v->visiting }}{{ $v->vehicle_plate ? ' · car ' . $v->vehicle_plate : '' }}</div>@endif
            </div>
        </div>
        @if ($v && $v->isOnSite())
            @can('Manage visitors')
                <form method="POST" action="{{ route('visitors.sign-out', $v) }}" class="mb-3" data-once>@csrf<button class="action-btn btn-open"><i class="ri-logout-box-r-line"></i> Sign {{ $v->full_name }} out now</button></form>
            @endcan
        @endif
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <x-cb.card title="Scan pass" icon="ri-camera-line">
                <div id="reader" class="rounded-3 overflow-hidden" style="max-width:420px"></div>
                <button type="button" class="action-btn btn-primary-cb mt-3" id="startScan"><i class="ri-camera-line"></i> Start camera</button>
                <div class="small text-muted mt-2" id="scanHint">The camera needs HTTPS (or localhost) and your permission. A handheld QR scanner can type into the box instead.</div>
            </x-cb.card>
        </div>
        <div class="col-lg-6">
            <x-cb.card title="Pass code" icon="ri-keyboard-line">
                <form method="GET" action="{{ route('visitors.check') }}" id="checkForm" class="rm-form">
                    <label for="code">Code printed under the QR</label>
                    <input type="text" name="code" id="code" class="form-control form-control-lg text-uppercase" required autofocus maxlength="200" placeholder="V7K3M9QX2A" autocomplete="off">
                    <button class="action-btn btn-primary-cb mt-3" type="submit"><i class="ri-search-line"></i> Check</button>
                </form>
            </x-cb.card>
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
});
</script>
@endsection
