@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Two-step sign-in" icon="ri-shield-keyhole-line"
               :subtitle="$enabled ? 'On: signing in needs your password and a code from your phone' : 'Off: signing in needs only your password'" />

    @include('rooms.partials.flash')
    @if (session('warning'))
        <div class="cb-banner warning"><i class="ri-alarm-warning-line"></i><div>{{ session('warning') }}</div></div>
    @endif

    @if ($codes)
        <x-cb.card title="Your recovery codes" icon="ri-key-2-line">
            <div class="cb-banner warning mb-3"><i class="ri-alarm-warning-line"></i>
                <div>Write these down or print this page and keep it somewhere safe, away from your phone. Each code works once, if your phone is lost or broken. <strong>They will not be shown again.</strong></div>
            </div>
            <div class="row g-2 mb-3" style="max-width:520px">
                @foreach ($codes as $c)
                    <div class="col-6"><code class="d-block text-center fs-5 p-2 border rounded">{{ $c }}</code></div>
                @endforeach
            </div>
            <button type="button" class="action-btn btn-more" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
        </x-cb.card>
    @endif

    <div class="row g-3">
        @if (! $enabled)
            <div class="col-xl-7">
                <x-cb.card title="Set it up" icon="ri-smartphone-line">
                    @if ($required)
                        <div class="cb-banner info mb-3"><i class="ri-information-line"></i><div>Your role needs two-step sign-in. You can use the system again as soon as this is done.</div></div>
                    @endif
                    <ol class="mb-3 ps-3">
                        <li class="mb-2">Install an authenticator app on your phone, for example <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong> or <strong>Authy</strong> (free).</li>
                        <li class="mb-2">In the app, add an account and scan this QR code.</li>
                        <li>Type the six-digit code the app shows.</li>
                    </ol>
                    <div class="d-flex flex-wrap gap-4 align-items-start">
                        <div id="tfQr" class="p-2 bg-white border rounded" style="width:196px;height:196px" data-uri="{{ $uri }}"></div>
                        <div style="max-width:320px">
                            <div class="small text-muted mb-1">Can't scan? Type this key into the app instead:</div>
                            <code class="d-block p-2 border rounded mb-3" style="word-break:break-all;letter-spacing:1px">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
                            <form method="POST" action="{{ route('two-factor.confirm') }}" class="rm-form">
                                @csrf
                                <label class="form-label" for="tfCode">Code from the app</label>
                                <input id="tfCode" name="code" class="form-control form-control-lg mb-2 text-center" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="123456" required autofocus style="letter-spacing:6px">
                                <button class="action-btn btn-open w-100" type="submit"><i class="ri-check-line"></i> Turn on</button>
                            </form>
                        </div>
                    </div>
                </x-cb.card>
            </div>
        @else
            <div class="col-xl-7">
                <x-cb.card title="It's on" icon="ri-shield-check-line">
                    <p class="mb-2">When you sign in on the website or the staff app, you'll be asked for the code your authenticator app shows after your password.</p>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Turned on</span><strong>{{ $user->two_factor_confirmed_at->format('j M Y, H:i') }}</strong></div>
                    <div class="d-flex justify-content-between py-2"><span>Recovery codes left</span><strong class="{{ $left <= 2 ? 'text-danger' : '' }}">{{ $left }}</strong></div>
                    @if ($left <= 2)
                        <div class="cb-banner warning mt-2"><i class="ri-alarm-warning-line"></i><div>You're running out of recovery codes. Make new ones below.</div></div>
                    @endif
                </x-cb.card>
            </div>
            <div class="col-xl-5">
                <x-cb.card title="Change it" icon="ri-settings-3-line">
                    @foreach ([
                        ['two-factor.recovery', 'New recovery codes', 'The old codes stop working.', 'ri-key-2-line', 'btn-open'],
                        ['two-factor.restart', 'Move to a new phone', 'Your current phone stops working straight away; scan a new QR code.', 'ri-smartphone-line', 'btn-more'],
                    ] + ($required ? [] : [2 => ['two-factor.disable', 'Turn off', 'Signing in will need only your password.', 'ri-close-circle-line', 'btn-more']]) as [$route, $label, $hint, $icon, $btn])
                        <form method="POST" action="{{ route($route) }}" class="rm-form py-2 border-bottom">
                            @csrf
                            <div class="fw-semibold">{{ $label }}</div>
                            <div class="small text-muted mb-2">{{ $hint }}</div>
                            <div class="d-flex gap-2">
                                <input type="password" name="password" class="form-control" placeholder="Your password" autocomplete="current-password" required aria-label="Your password">
                                <button class="action-btn {{ $btn }} text-nowrap" type="submit"><i class="{{ $icon }}"></i> {{ $label }}</button>
                            </div>
                        </form>
                    @endforeach
                    @if ($required)
                        <div class="small text-muted mt-2">Your role must use two-step sign-in, so it can't be turned off.</div>
                    @endif
                </x-cb.card>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@if (! $enabled)
<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
    (function () {
        var el = document.getElementById('tfQr');
        if (el && window.QRCode) new QRCode(el, { text: el.dataset.uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
    })();
</script>
@endif
@endsection
