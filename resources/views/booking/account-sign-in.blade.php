@extends('layouts.guest', ['title' => 'Sign in', 'noindex' => true])

@section('content')
<div class="wrap narrow">
    <h1 class="serif">Your account</h1>
    <p class="muted">See every booking you’ve made with us in one place. No password: we send you a code, or you can use Google or Apple.</p>
    @include('rooms.partials.flash-guest')
    @if (session('dev_code'))
        <div class="notice dev" role="status"><i class="ri-code-s-slash-line"></i><div><strong>Local test install:</strong> e-mails go to the log file, so here is the code: <strong class="dev-code">{{ session('dev_code') }}</strong></div></div>
    @endif

    @if ($providers['google'] || $providers['apple'])
        <div class="panel social">
            @if ($providers['google'])
                <div id="g-btn" class="g-btn"></div>
            @endif
            @if ($providers['apple'])
                <button type="button" class="btn apple" id="apple-btn"><i class="ri-apple-fill"></i> Continue with Apple</button>
            @endif
            <div class="or"><span>or with your e-mail</span></div>
        </div>
    @endif

    @if ($step === 'code')
        <form method="POST" action="{{ route('account.verify') }}" class="panel">@csrf
            <p class="small muted" style="margin:0">Enter the 6-digit code sent to <strong>{{ $contact }}</strong>.</p>
            <div class="field"><label for="code">Code</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="10" value="{{ session('dev_code') }}" autofocus></div>
            <button class="btn" type="submit"><i class="ri-login-circle-line"></i> Sign in</button>
            <a href="{{ route('account.show') }}" class="small">Use another e-mail or phone</a>
        </form>
    @else
        <form method="POST" action="{{ route('account.code') }}" class="panel">@csrf
            <div class="field"><label for="contact">E-mail or phone</label><input id="contact" name="contact" value="{{ old('contact', $contact) }}" required maxlength="150" autocomplete="email"></div>
            <button class="btn" type="submit"><i class="ri-mail-send-line"></i> Send me a code</button>
        </form>
    @endif
    <p class="small muted">Just want one booking? <a href="{{ route('stay.find') }}">Find it by its reference</a>.</p>
</div>
@endsection

@push('head')
<style>
    .narrow { max-width: 520px; padding-top: 36px; }
    .narrow h1 { font-size: 34px; margin-bottom: 8px; }
    .narrow .panel { display: grid; gap: 14px; margin: 20px 0; }
    .social { justify-items: stretch; }
    .g-btn { display: flex; justify-content: center; min-height: 44px; }
    .btn.apple { background: #000; color: #fff; }
    .btn.apple:hover { background: #222; }
    .or { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 13px; }
    .or::before, .or::after { content: ""; flex: 1; border-top: 1px solid var(--line); }
    .notice.dev { background: #fff8e1; border: 1px solid #f3d27a; }
    .dev-code { font-size: 20px; letter-spacing: 3px; }
</style>
@endpush

@push('scripts')
@if ($providers['google'] || $providers['apple'])
<script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
        async function send(body) {
            const res = await fetch(@json(route('account.social')), {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body),
            });
            const out = await res.json().catch(() => ({}));
            if (res.ok && out.redirect) { location.href = out.redirect; return; }
            alertBox(out.message || 'Sign-in didn’t work. Please try again.');
        }
        function alertBox(msg) {
            let el = document.getElementById('social-error');
            if (!el) { el = document.createElement('div'); el.id = 'social-error'; el.className = 'notice bad'; el.setAttribute('role', 'alert'); document.querySelector('.social').prepend(el); }
            el.textContent = msg;
        }
        @if ($providers['google'])
        window.onGoogleLibraryLoad = function () {
            google.accounts.id.initialize({
                client_id: @json($providers['google']['web_client_id']), nonce: @json($nonce),
                callback: (r) => send({ provider: 'google', id_token: r.credential }),
            });
            google.accounts.id.renderButton(document.getElementById('g-btn'), { theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', width: 320 });
        };
        @endif
        @if ($providers['apple'])
        document.getElementById('apple-btn').addEventListener('click', async function () {
            if (!window.AppleID) { alertBox('Apple sign-in is still loading. Try again in a moment.'); return; }
            AppleID.auth.init({ clientId: @json($providers['apple']['service_id']), scope: 'name email', redirectURI: @json($providers['apple']['redirect_uri']), nonce: @json($nonce), usePopup: true });
            try {
                const r = await AppleID.auth.signIn();
                send({ provider: 'apple', id_token: r.authorization.id_token, first_name: r.user?.name?.firstName, last_name: r.user?.name?.lastName });
            } catch (e) {
                if (e && e.error !== 'popup_closed_by_user') alertBox('Apple sign-in didn’t work. Please try again.');
            }
        });
        @endif
    })();
</script>
@if ($providers['google'])<script src="https://accounts.google.com/gsi/client" async defer></script>@endif
@if ($providers['apple'])<script src="https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js" async></script>@endif
@endif
@endpush
