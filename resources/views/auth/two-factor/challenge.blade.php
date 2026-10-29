<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>Sign-in code | {{ config('hotel.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/app.min.css') }}" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', 'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f2744 0%, #1e4d7b 55%, #1c9a8c 100%); min-height: 100vh; }
        .tf-card { max-width: 420px; border: 0; border-radius: 18px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .tf-code { letter-spacing: 8px; font-size: 1.6rem; text-align: center; border-radius: 12px; }
        .tf-icon { width: 64px; height: 64px; border-radius: 50%; background: #e8f4f2; color: #1c9a8c; font-size: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
    <div class="card tf-card w-100">
        <div class="card-body p-4 p-sm-5">
            <div class="tf-icon"><i class="ri-shield-keyhole-line"></i></div>
            <h5 class="text-center fw-semibold mb-1">{{ config('hotel.name') }}</h5>
            <p class="text-center text-muted mb-4" id="tfHint">Open your authenticator app and type the six-digit code for this account.</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('two-factor.verify') }}" id="tfForm">
                @csrf
                <input type="text" name="code" id="tfInput" class="form-control tf-code mb-3" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="7" placeholder="000000" required autofocus aria-label="Sign-in code">
                <button type="submit" class="btn btn-primary w-100 py-2">Continue</button>
            </form>

            <div class="text-center mt-3 small">
                <a href="#" id="tfSwitch" class="text-muted">Lost your phone? Use a recovery code</a>
            </div>
            <form method="GET" action="{{ route('login') }}" class="text-center mt-2">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">Back to sign in</button>
            </form>
        </div>
    </div>
<script>
    (function () {
        var input = document.getElementById('tfInput'), link = document.getElementById('tfSwitch'), hint = document.getElementById('tfHint'), recovery = false;
        link.addEventListener('click', function (e) {
            e.preventDefault();
            recovery = !recovery;
            input.value = '';
            input.maxLength = recovery ? 11 : 7;
            input.placeholder = recovery ? 'ABCDE-12345' : '000000';
            input.inputMode = recovery ? 'text' : 'numeric';
            input.style.letterSpacing = recovery ? '3px' : '8px';
            hint.textContent = recovery ? 'Type one of the recovery codes you saved when you set up two-step sign-in. Each works once.' : 'Open your authenticator app and type the six-digit code for this account.';
            link.textContent = recovery ? 'Use the code from my app instead' : 'Lost your phone? Use a recovery code';
            input.focus();
        });
        // Six digits typed: send straight away.
        input.addEventListener('input', function () {
            if (!recovery && input.value.replace(/\D/g, '').length === 6) document.getElementById('tfForm').submit();
        });
    })();
</script>
</body>
</html>
