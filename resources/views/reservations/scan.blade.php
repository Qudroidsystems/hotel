@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Scan / Find a Booking" icon="ri-qr-scan-2-line"
               subtitle="Scan the guest's QR code with this device's camera, or type the booking reference."
               :back="route('reservations.index')" back-label="Front Desk" />

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-lg-6">
            <x-cb.card title="Scan QR code" icon="ri-camera-line">
                <div id="reader" class="rounded-3 overflow-hidden" style="max-width:420px"></div>
                <button type="button" class="action-btn btn-primary-cb mt-3" id="startScan"><i class="ri-camera-line"></i> Start camera</button>
                <div class="small text-muted mt-2" id="scanHint">The camera needs HTTPS (or localhost) and your permission. A USB/handheld QR scanner can type into the box on the right instead.</div>
            </x-cb.card>
        </div>
        <div class="col-lg-6">
            <x-cb.card title="Booking reference" icon="ri-keyboard-line">
                <form method="POST" action="{{ route('reservations.lookup') }}" id="lookupForm" class="rm-form">
                    @csrf
                    <label for="code">Reference or scanned code</label>
                    <input type="text" name="code" id="code" class="form-control form-control-lg text-uppercase" required autofocus maxlength="200"
                           placeholder="SH-7K3M9Q" value="{{ old('code') }}" autocomplete="off">
                    <button class="action-btn btn-primary-cb mt-3" type="submit"><i class="ri-search-line"></i> Find booking</button>
                </form>
                <div class="small text-muted mt-3">If the guest has neither, search by name on the <a href="{{ route('reservations.index', ['tab' => 'arrivals']) }}">arrivals list</a>.</div>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('lookupForm'), code = document.getElementById('code');
    let scanner = null;
    document.getElementById('startScan').addEventListener('click', async function () {
        if (!window.Html5Qrcode) { document.getElementById('scanHint').textContent = 'The scanner library could not load (offline?). Type the reference instead.'; return; }
        this.disabled = true;
        scanner = new Html5Qrcode('reader');
        try {
            await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, text => {
                scanner.stop().catch(() => {});
                code.value = text; form.submit();
            });
        } catch (e) {
            this.disabled = false;
            document.getElementById('scanHint').textContent = 'Could not start the camera: ' + (e?.message || e) + '. Type the reference instead.';
        }
    });
});
</script>
@endsection
