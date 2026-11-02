<!doctype html>
<html lang="en" data-layout="vertical" data-sidebar="dark" data-sidebar-size="lg" data-preloader="disable" data-theme="default" data-topbar="light" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>{{ $pagetitle ?? 'Dashboard' }} | {{ config('hotel.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="{{ config('hotel.tagline') }}" name="description">
    <meta content="{{ config('hotel.vendor') }}" name="author">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link rel="shortcut icon" href="{{ asset(config('hotel.favicon')) }}">

    <!-- Fonts -->
    <link id="fontsLink" href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/choices/choices.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/phosphor-bold/style.css') }}">
    <link href="{{ asset('theme/layouts/assets/fonts/materialdesignicons-webfont.woff2') }}?v=6.5.95" rel="stylesheet" type="font/woff2">

    <!-- Layout CSS — load BEFORE layout.js -->
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/app.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/custom.min.css') }}" rel="stylesheet">

    <!-- layout.js must run AFTER the CSS above -->
    <script src="{{ asset('theme/layouts/assets/js/layout.js') }}"></script>

    <!-- NProgress -->
    <link rel="stylesheet" href="{{ asset('vendor/nprogress/nprogress.css') }}"/>
    <script src="{{ asset('vendor/nprogress/nprogress.js') }}"></script>

    <!-- Select2 CSS -->
    <link href="{{ asset('vendor/select2/select2.min.css') }}" rel="stylesheet" />

    <!-- jQuery (needed by Select2 and some page scripts) -->
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>

    <style>
        /* =====================================================
           NPROGRESS
           ===================================================== */
        #nprogress .bar  { background: #4f8ef7 !important; height: 3px !important; box-shadow: 0 0 8px rgba(79,142,247,.6) !important; }
        #nprogress .peg  { box-shadow: none !important; }
        #nprogress .spinner { display: none !important; }

        /* =====================================================
           PAGINATION
           ===================================================== */
        .pagination-wrap .page-item { margin: 0 5px; }
        .pagination-wrap .page-link { padding: 5px 10px; }
        .pagination-wrap .active .page-link { background-color: #007bff; color: white; }
        .pagination-wrap .disabled .page-link { pointer-events: none; opacity: 0.5; }

        /* =====================================================
           SPINNER
           ===================================================== */
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }

        /* =====================================================
           MISC
           ===================================================== */
        .form-check-input:checked { background-color: #405189; border-color: #405189; }
        .swal2-toast { font-size: 14px !important; }
        .swal2-container.swal2-top-end { top: 70px !important; }
        .table tbody tr { transition: background-color .15s ease; }
        .table tbody tr:hover { background-color: rgba(67,97,238,.05); }
        .modal.fade  .modal-dialog { transform: translate(0,-50px); transition: transform .3s ease-out; }
        .modal.show  .modal-dialog { transform: translate(0,0); }

        /* =====================================================
           SIDEBAR LAYOUT — flex column so footer pins to bottom
           ===================================================== */
        .app-menu {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: 250px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }
        #scrollbar {
            flex: 1;
            overflow-y: auto;
            scrollbar-width: thin;
            padding-bottom: 8px;
        }
        #scrollbar::-webkit-scrollbar { width: 4px; }
        #scrollbar::-webkit-scrollbar-track  { background: rgba(255,255,255,.05); border-radius: 4px; }
        #scrollbar::-webkit-scrollbar-thumb  { background: rgba(255,255,255,.2);  border-radius: 4px; }
        #scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.3); }
        .bg-light::-webkit-scrollbar { width: 6px; }
        .bg-light::-webkit-scrollbar-track  { background: #f1f1f1; border-radius: 10px; }
        .bg-light::-webkit-scrollbar-thumb  { background: #888; border-radius: 10px; }
        .bg-light::-webkit-scrollbar-thumb:hover { background: #555; }
        .navbar-menu .container-fluid { padding: 0; }
        #navbar-nav { padding-bottom: 8px; }

        /* =====================================================
           SIDEBAR LOGOUT FOOTER — pushed down with margin-top auto
           ===================================================== */
        .hotel-name-logo { font-family: 'Playfair Display', Georgia, serif; font-weight: 700; font-size: 22px; line-height: 70px; color: #fff !important; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .hotel-name-logo .logo-sm { font-size: 26px; }
        #page-topbar .hotel-name-logo { color: inherit !important; }
        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,.1);
            padding: 20px 16px 24px;
            margin-top: auto;
            background: inherit;
        }
        .sidebar-footer-user {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .sidebar-footer-user img {
            width: 36px; height: 36px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid rgba(255,255,255,.18);
            flex-shrink: 0;
        }
        .sidebar-footer-avatar-initials {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: #405189;
            color: #fff;
            font-size: 13px; font-weight: 600;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,.18);
        }
        .sidebar-footer-user-info { min-width: 0; flex: 1; }
        .sidebar-footer-user-name {
            font-size: 13px; font-weight: 600; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-footer-user-role {
            font-size: 11px; color: rgba(255,255,255,.45);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-logout-btn {
            display: flex; align-items: center; gap: 9px;
            width: 100%; padding: 9px 14px;
            border-radius: 8px;
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.22);
            color: #f87171; font-size: 13px; font-weight: 500;
            cursor: pointer; text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
        }
        .sidebar-logout-btn:hover {
            background: rgba(239,68,68,.24);
            border-color: rgba(239,68,68,.45);
            color: #fca5a5;
        }
        .sidebar-logout-btn i { font-size: 17px; flex-shrink: 0; }

        /* =====================================================
           SIDEBAR NAV ACTIVE STATES
           ===================================================== */
        #navbar-nav .menu-dropdown { overflow: hidden; }
        #navbar-nav .nav-link.menu-link .ri-arrow-down-s-line { transition: transform .25s ease; display: inline-block; }
        #navbar-nav .nav-link.menu-link[aria-expanded="true"] .ri-arrow-down-s-line { transform: rotate(180deg); }

        #navbar-nav .nav-link.menu-link.nav-active-parent {
            color: #fff !important;
            background: rgba(79,142,247,.18) !important;
            border-left: 3px solid #4f8ef7;
            padding-left: calc(1.3rem - 3px);
        }
        #navbar-nav .nav-link.menu-link.nav-active-parent i { color: #4f8ef7 !important; }

        #navbar-nav .nav-sm .nav-link.nav-active-child { color: #7eb8fb !important; font-weight: 500; }
        #navbar-nav .nav-sm .nav-link.nav-active-child::before {
            content: ''; display: inline-block;
            width: 5px; height: 5px; border-radius: 50%;
            background: #4f8ef7; margin-right: 8px;
            box-shadow: 0 0 0 3px rgba(79,142,247,.25);
            vertical-align: middle; flex-shrink: 0;
            animation: dotPop .25s ease;
        }
        @keyframes dotPop { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }

        /* =====================================================
           SIDEBAR HOVER TRANSITIONS + RIPPLE
           ===================================================== */
        #navbar-nav .nav-link { position: relative; overflow: hidden; transition: color .18s, background-color .18s, padding-left .18s; }
        .nav-ripple {
            position: absolute; border-radius: 50%;
            background: rgba(255,255,255,.18);
            transform: scale(0); animation: ripple-anim .55s linear;
            pointer-events: none; z-index: 0;
        }
        @keyframes ripple-anim { to{transform:scale(5);opacity:0} }

        /* =====================================================
           BACK TO TOP
           ===================================================== */
        #back-to-top { opacity:0; visibility:hidden; transform:translateY(12px); transition:opacity .3s,transform .3s,visibility .3s; }
        #back-to-top.show { opacity:1; visibility:visible; transform:translateY(0); }
        #back-to-top:hover { transform:translateY(-3px) !important; }

        /* =====================================================
           PAGE FADE-IN
           ===================================================== */
        .page-content { animation: pageFadeIn .35s ease; }
        @keyframes pageFadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

        /* =====================================================
           TOPBAR
           ===================================================== */
        #page-topbar .header-item { transition: color .2s ease, background-color .2s ease; }
        .header-profile-user-enhanced { transition: transform .25s ease, box-shadow .25s ease; }
        .header-profile-user-enhanced:hover { transform: scale(1.07); box-shadow: 0 0 0 3px rgba(79,142,247,.35) !important; }

        .topbar-user .dropdown-menu {
            min-width: 220px;
            z-index: 9999 !important;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 8px !important;
            box-shadow: 0 8px 32px rgba(0,0,0,.18);
        }

        /* =====================================================
           MOBILE SIDEBAR
           ===================================================== */
        .vertical-overlay {
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(0,0,0,.45);
            display: none;
        }
        body.vertical-sidebar-enable .vertical-overlay { display: block; }

        @media (max-width: 1024.98px) {
            .app-menu {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                box-shadow: none;
            }
            body.vertical-sidebar-enable .app-menu {
                transform: translateX(0);
                box-shadow: 4px 0 24px rgba(0,0,0,.35);
            }
        }
        @media (min-width: 1025px) {
            body.vertical-sidebar-enable .app-menu { transform: none; }
        }

        /* =====================================================
           FINANCE MODULE
           ===================================================== */
        .finance-stat-card { background: linear-gradient(135deg,#667eea 0%,#764ba2 100%); border-radius: 12px; padding: 20px; color: white; transition: transform .3s,box-shadow .3s; }
        .finance-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(102,126,234,.35); }
        .payment-progress { height: 8px; border-radius: 4px; background: #e2e8f0; }
        .payment-progress-bar { height: 100%; border-radius: 4px; transition: width .4s ease; }
        .scholarship-card { border-left: 4px solid #10b981; transition: transform .2s,box-shadow .2s; }
        .scholarship-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .payroll-table th { background: #1e293b; color: white; }

        /* =====================================================
           CARD / BUTTON MICRO-INTERACTIONS
           ===================================================== */
        .card { transition: box-shadow .25s, transform .25s; }
        .card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); }
        .btn  { transition: transform .15s, box-shadow .15s; }
        .btn:active { transform: scale(.97); }

        /* =====================================================
           SIDEBAR STAGGER
           ===================================================== */
        #navbar-nav > li { animation: navItemFadeIn .4s ease both; }
        #navbar-nav > li:nth-child(1)  { animation-delay:.02s }
        #navbar-nav > li:nth-child(2)  { animation-delay:.04s }
        #navbar-nav > li:nth-child(3)  { animation-delay:.06s }
        #navbar-nav > li:nth-child(4)  { animation-delay:.08s }
        #navbar-nav > li:nth-child(5)  { animation-delay:.10s }
        #navbar-nav > li:nth-child(6)  { animation-delay:.12s }
        #navbar-nav > li:nth-child(7)  { animation-delay:.14s }
        #navbar-nav > li:nth-child(8)  { animation-delay:.16s }
        #navbar-nav > li:nth-child(9)  { animation-delay:.18s }
        #navbar-nav > li:nth-child(10) { animation-delay:.20s }
        #navbar-nav > li:nth-child(11) { animation-delay:.22s }
        #navbar-nav > li:nth-child(12) { animation-delay:.24s }
        #navbar-nav > li:nth-child(n+13) { animation-delay:.26s }
        @keyframes navItemFadeIn { from{opacity:0;transform:translateX(-8px)} to{opacity:1;transform:translateX(0)} }

        /* =====================================================
           DROPDOWN ANIMATIONS
           ===================================================== */
        .dropdown-menu { animation: dropdownFadeIn .25s cubic-bezier(.4,0,.2,1); transform-origin: top right; }
        @keyframes dropdownFadeIn { from{opacity:0;transform:translateY(-10px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }

        /* =====================================================
           PRINT
           ===================================================== */
        @media print { .no-print{display:none!important} body{padding:0;margin:0} }

        /* =====================================================
           SPOTLIGHT SEARCH — APPLE STYLE DARK
           ===================================================== */
        @keyframes spotlightOverlayFadeIn  { from{background:rgba(0,0,0,.2);backdrop-filter:blur(0)} to{background:rgba(0,0,0,.85);backdrop-filter:blur(20px)} }
        @keyframes spotlightOverlayFadeOut { from{background:rgba(0,0,0,.85);backdrop-filter:blur(20px)} to{background:rgba(0,0,0,.2);backdrop-filter:blur(0)} }
        @keyframes spotlightModalBounceIn  { 0%{opacity:0;transform:translateY(-40px) scale(.96)} 40%{opacity:.9;transform:translateY(8px) scale(1.01)} 70%{opacity:.95;transform:translateY(-2px) scale(.99)} 100%{opacity:1;transform:translateY(0) scale(1)} }
        @keyframes spotlightModalFadeOut   { 0%{opacity:1;transform:translateY(0) scale(1)} 100%{opacity:0;transform:translateY(-20px) scale(.95)} }
        @keyframes resultBounceIn { 0%{opacity:0;transform:translateX(-16px) scale(.96)} 60%{opacity:.8;transform:translateX(4px) scale(1.01)} 100%{opacity:1;transform:translateX(0) scale(1)} }
        @keyframes resultGlowPulse { 0%{box-shadow:0 0 0 0 rgba(79,142,247,.5)} 70%{box-shadow:0 0 0 8px rgba(79,142,247,0)} 100%{box-shadow:0 0 0 0 rgba(79,142,247,0)} }
        @keyframes loadingSpin    { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }
        @keyframes historySlideIn { from{opacity:0;transform:translateX(-12px)} to{opacity:1;transform:translateX(0)} }
        @keyframes typingDot      { 0%,60%,100%{transform:translateY(0);opacity:.4} 30%{transform:translateY(-5px);opacity:1} }
        @keyframes suggestFadeIn  { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }

        .spotlight-result-item { animation:resultBounceIn .35s cubic-bezier(.34,1.3,.64,1) forwards; opacity:0; }
        .spotlight-result-item:nth-child(1){animation-delay:.00s}
        .spotlight-result-item:nth-child(2){animation-delay:.03s}
        .spotlight-result-item:nth-child(3){animation-delay:.06s}
        .spotlight-result-item.top-match { animation:resultBounceIn .4s cubic-bezier(.34,1.3,.64,1) forwards,resultGlowPulse .6s ease .3s; border-left:3px solid #4f8ef7; background:linear-gradient(90deg,rgba(79,142,247,.12) 0%,transparent 100%); }
        .spotlight-history-item { animation:historySlideIn .25s ease forwards; opacity:0; animation-fill-mode:forwards; }
        .spotlight-history-item:nth-child(1){animation-delay:.00s}
        .spotlight-history-item:nth-child(2){animation-delay:.04s}
        .spotlight-history-item:nth-child(3){animation-delay:.08s}
        .spotlight-suggest-chip { animation:suggestFadeIn .2s ease forwards; opacity:0; }
        .spotlight-suggest-chip:nth-child(1){animation-delay:.00s}
        .spotlight-suggest-chip:nth-child(2){animation-delay:.04s}
        .spotlight-suggest-chip:nth-child(3){animation-delay:.08s}
        .spotlight-suggest-chip:nth-child(4){animation-delay:.12s}
        .spotlight-suggest-chip:nth-child(5){animation-delay:.16s}
        .typing-dot { display:inline-block; animation:typingDot 1.4s infinite ease-in-out; }
        .typing-dot:nth-child(2){animation-delay:.2s}
        .typing-dot:nth-child(3){animation-delay:.4s}

        .search-tooltip { position:absolute; bottom:-38px; left:0; background:#1c1c1e; color:#fff; font-size:12px; padding:6px 12px; border-radius:10px; white-space:nowrap; opacity:0; transition:opacity .2s; pointer-events:none; z-index:100; backdrop-filter:blur(8px); border:0.5px solid rgba(255,255,255,0.1); font-weight:500; }
        .search-tooltip kbd { background:rgba(255,255,255,0.15); color:#fff; padding:2px 8px; border-radius:6px; font-size:11px; margin:0 2px; }
        kbd { background:rgba(0,0,0,0.08); border-radius:6px; padding:2px 8px; font-size:11px; font-family:monospace; }

        /* Spotlight trigger */
        #spotlight-trigger { background:rgba(0,0,0,0.6) !important; border:1px solid rgba(255,255,255,0.15) !important; border-radius:12px !important; padding:8px 16px !important; min-width:260px !important; backdrop-filter:blur(8px) !important; }
        #spotlight-trigger span { color:#ffffff !important; opacity:0.9 !important; font-weight:500 !important; }
        #spotlight-trigger kbd { background:rgba(255,255,255,0.2) !important; color:#ffffff !important; border:none !important; }

        /* Ensure modals don't conflict */
        .modal { z-index: 1055 !important; }
        .modal-backdrop { z-index: 1050 !important; }
    </style>

    <!-- Route-specific CSS includes -->
    @if (Route::is('dashboard'))              @include('layouts.pages-assets.css.users-list-css') @endif
    @if (Route::is('users.*'))                @include('layouts.pages-assets.css.users-list-css') @endif
    @if (Route::is('profile.*'))              @include('layouts.pages-assets.css.users-list-css') @endif
    @if (Route::is('roles.*'))                @include('layouts.pages-assets.css.roles-list-css') @endif
    @if (Route::is('permissions.*'))          @include('layouts.pages-assets.css.permission-list-css') @endif
    {{-- New modules: add a line per module here, e.g.
         @if (Route::is('rooms.*')) @include('layouts.pages-assets.css.rooms-list-css') @endif --}}


    {{-- CB UI design system (hero / stat / card look used across the portal).
         Loaded LAST so page-specific CSS partials above can't override it.
         Remove class="cb-ui" from <body> to switch the global skin off. --}}
    <link href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('css/cb-ui.css') }}?v={{ @filemtime(public_path('css/cb-ui.css')) }}" rel="stylesheet">
    @include('partials.qlouds-head', ['key' => 'web'])
</head>

<body class="cb-ui">
@include('partials.qlouds-splash', ['key' => 'web'])
<div id="layout-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <div class="app-menu navbar-menu">

        <!-- LOGO -->
        <div class="navbar-brand-box">
            @php
                $hotelName = config('hotel.name');
                $logoDark  = asset(config('hotel.logo'));
                $logoLight = asset(config('hotel.logo_light'));
            @endphp

            @if (config('hotel.has_logo'))
            <a href="{{ url('/') }}" class="logo logo-dark">
                <span class="logo-sm">
                    <img src="{{ $logoDark }}" alt="{{ $hotelName }}"
                         style="height:80px;width:auto;border-radius:10px;object-fit:contain;padding:3px;background:rgb(39,38,38);">
                </span>
                <span class="logo-lg">
                    <img src="{{ $logoDark }}" alt="{{ $hotelName }}"
                         style="height:80px;width:auto;border-radius:12px;object-fit:contain;padding:2px;background:rgb(37,36,36);">
                </span>
            </a>
            <a href="{{ url('/') }}" class="logo logo-light">
                <span class="logo-sm">
                    <img src="{{ $logoLight }}" alt="{{ $hotelName }}"
                         style="height:45px;width:auto;border-radius:10px;object-fit:contain;padding:3px;background:rgb(40,39,39);">
                </span>
                <span class="logo-lg">
                    <img src="{{ $logoLight }}" alt="{{ $hotelName }}"
                         style="height:80px;width:auto;border-radius:12px;object-fit:contain;padding:2px;background:rgb(37,36,36);">
                </span>
            </a>
            @else
            <a href="{{ url('/') }}" class="logo logo-dark hotel-name-logo"><span class="logo-sm">{{ mb_substr($hotelName, 0, 1) }}</span><span class="logo-lg">{{ $hotelName }}</span></a>
            <a href="{{ url('/') }}" class="logo logo-light hotel-name-logo"><span class="logo-sm">{{ mb_substr($hotelName, 0, 1) }}</span><span class="logo-lg">{{ $hotelName }}</span></a>
            @endif

            <button type="button" class="btn btn-sm p-0 fs-3xl header-item float-end btn-vertical-sm-hover" id="vertical-hover">
                <i class="ri-record-circle-line"></i>
            </button>
        </div>

        <!-- NAV (scrollable) -->
        <div id="scrollbar">
            <div class="container-fluid">
                <div id="two-column-menu"></div>
                <ul class="navbar-nav" id="navbar-nav">

                    <li class="menu-title"><span data-key="t-menu">Menu</span></li>

                    {{-- MOVED_ADMIN_TOP: Dashboards + Users first --}}
                    {{-- Dashboard --}}
                    @can('dashboard')
                    <li class="nav-item">
                        <a class="nav-link menu-link collapsed" href="#sidebarDashboards" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarDashboards">
                            <i class="ph-gauge"></i> <span data-key="t-dashboards">Dashboards</span>
                        </a>
                        <div class="collapse menu-dropdown" id="sidebarDashboards">
                            <ul class="nav nav-sm flex-column">
                                <li class="nav-item">
                                    <a href="{{ route('dashboard') }}" class="nav-link" data-key="t-analytics">Hotel Overview</a>
                                </li>
                                @can('View management reports')
                                <li class="nav-item">
                                    <a href="{{ route('reports.index') }}" class="nav-link">Management Reports</a>
                                </li>
                                @endcan
                                @canany(['View management reports', 'View finance reports', 'View stock reports'])
                                <li class="nav-item">
                                    <a href="{{ route('reports.advanced') }}" class="nav-link">Advanced Reports</a>
                                </li>
                                @endcanany
                            </ul>
                        </div>
                    </li>
                    @endcan

                    {{-- USERS & PRIVILEGES --}}
                    @if(auth()->user()->can('View user') || auth()->user()->can('View role') || auth()->user()->can('View user-account'))
                        <li class="menu-title"><i class="ri-more-fill"></i> <span data-key="t-pages">USERS & PRIVILEGES</span></li>
                    @endif

                    @can('View user')
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarusers" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarusers">
                                <i class="ph-user-circle"></i> <span data-key="t-authentication">User Management</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarusers">
                                <ul class="nav nav-sm flex-column">
                                    <li class="nav-item">
                                        <a href="{{ route('users.index') }}" class="nav-link" data-key="t-signin">Users</a>
                                    </li>
                                    @can('Update user')
                                    <li class="nav-item">
                                        <a href="{{ route('security.two-factor') }}" class="nav-link">Two-step Sign-in</a>
                                    </li>
                                    @endcan
                                </ul>
                            </div>
                        </li>
                    @endcan

                    {{-- My Account --}}
                    <li class="nav-item">
                        <a class="nav-link menu-link collapsed" href="#sidebaraccount" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebaraccount">
                            <i class="ph-address-book"></i> <span data-key="t-pages">My Account</span>
                        </a>
                        <div class="collapse menu-dropdown" id="sidebaraccount">
                            <ul class="nav nav-sm flex-column">
                                <li class="nav-item">
                                    <a href="{{ route('users.overview', ['id' => Auth::id()]) }}" class="nav-link">
                                        <i class="ri-profile-line me-2"></i> My Profile
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('profile.settings', ['id' => Auth::id()]) }}" class="nav-link">
                                        <i class="ri-settings-3-line me-2"></i> Account Settings
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('profile.settings', ['id' => Auth::id()]) }}#security" class="nav-link ps-4">
                                        <i class="ri-lock-password-line me-2"></i> Change Password
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('two-factor.show') }}" class="nav-link ps-4">
                                        <i class="ri-shield-keyhole-line me-2"></i> Two-step Sign-in
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('attendance.my') }}" class="nav-link">
                                        <i class="ri-user-follow-line me-2"></i> My Attendance
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    @can('View role')
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarroles" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarroles">
                                <i class="ph-address-book"></i> <span data-key="t-pages">Roles And Permissions</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarroles">
                                <ul class="nav nav-sm flex-column">
                                    @can('View role')
                                        <li class="nav-item"><a href="{{ route('roles.index') }}" class="nav-link">Roles</a></li>
                                    @endcan
                                    @can('View permission')
                                        <li class="nav-item"><a href="{{ route('permissions.index') }}" class="nav-link">Permissions</a></li>
                                    @endcan
                                </ul>
                            </div>
                        </li>
                    @endcan

                    {{-- ============================================================
                         HOTEL MODULES
                         Add each new module's menu here (Rooms, Reservations, Front Desk,
                         POS, Inventory, HR, Security, IT Control Room…), wrapped in the
                         @can() permission that guards it.
                         ============================================================ --}}

                    @canany(['View rooms', 'Manage room types', 'Manage buildings', 'View reservations', 'View guests', 'View folios', 'Receive payments', 'View finance reports', 'Use POS', 'View POS reports', 'Manage POS menu', 'Manage hotel settings', 'View stock', 'View attendance', 'Manage staff & shifts', 'Run attendance kiosk', 'View audit log', 'View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Manage maintenance', 'View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found', 'Request purchases', 'Approve purchases', 'Manage supplier invoices', 'Pay suppliers'])
                        <li class="menu-title"><i class="ri-more-fill"></i> <span>HOTEL OPERATIONS</span></li>
                    @endcanany
                    {{-- Department tasks: everyone has their own list --}}
                    <li class="nav-item">
                        <a class="nav-link menu-link" href="{{ route('tasks.index') }}">
                            <i class="ph-list-checks"></i> <span>Tasks</span>
                        </a>
                    </li>
                    @canany(['View reservations', 'View guests', 'View company accounts', 'View events', 'Manage events', 'Run night audit', 'View night audit reports', 'Manage rates', 'View room calendars', 'Manage room calendars'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarFrontDesk" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarFrontDesk">
                                <i class="ph-calendar-check"></i> <span>Front Desk</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarFrontDesk">
                                <ul class="nav nav-sm flex-column">
                                    @can('View reservations')
                                        <li class="nav-item"><a href="{{ route('reservations.index') }}" class="nav-link">Arrivals &amp; Departures</a></li>
                                    @endcan
                                    @can('Create reservations')
                                        <li class="nav-item"><a href="{{ route('reservations.create') }}" class="nav-link">New Reservation</a></li>
                                    @endcan
                                    @can('View reservations')
                                        <li class="nav-item"><a href="{{ route('reservations.calendar') }}" class="nav-link">Booking Calendar</a></li>
                                        <li class="nav-item"><a href="{{ route('reservations.index', ['tab' => 'all']) }}" class="nav-link">All Reservations</a></li>
                                        <li class="nav-item"><a href="{{ route('reservations.scan') }}" class="nav-link">Scan / Find Booking</a></li>
                                    @endcan
                                    @can('View guests')
                                        <li class="nav-item"><a href="{{ route('guests.index') }}" class="nav-link">Guests</a></li>
                                    @endcan
                                    @can('View company accounts')
                                        <li class="nav-item"><a href="{{ route('companies.index') }}" class="nav-link">Company Accounts</a></li>
                                    @endcan
                                    @canany(['View events', 'Manage events'])
                                        <li class="nav-item"><a href="{{ route('events.index') }}" class="nav-link">Events &amp; Halls</a></li>
                                    @endcanany
                                    @canany(['Sell event tickets', 'View events'])
                                        <li class="nav-item"><a href="{{ route('events.tickets.index') }}" class="nav-link">Event Tickets</a></li>
                                    @endcanany
                                    @canany(['Check event tickets', 'Sell event tickets'])
                                        <li class="nav-item"><a href="{{ route('events.tickets.door') }}" class="nav-link">Check Tickets</a></li>
                                    @endcanany
                                    @canany(['Run night audit', 'View night audit reports'])
                                        <li class="nav-item"><a href="{{ route('night-audit.index') }}" class="nav-link">Night Audit</a></li>
                                    @endcanany
                                    @can('Manage rates')
                                        <li class="nav-item"><a href="{{ route('rates.index') }}" class="nav-link">Rates &amp; Seasons</a></li>
                                        <li class="nav-item"><a href="{{ route('rates.revenue') }}" class="nav-link">Revenue &amp; Pricing</a></li>
                                    @endcan
                                    @can('Manage transport')
                                        <li class="nav-item"><a href="{{ route('transport.index') }}" class="nav-link">Car Service</a></li>
                                    @endcan
                                    @canany(['View room calendars', 'Manage room calendars'])
                                        <li class="nav-item"><a href="{{ route('channels.index') }}" class="nav-link">Room Calendars</a></li>
                                    @endcanany
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['View folios', 'Receive payments', 'View finance reports', 'Use POS', 'Review cash shifts'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarBilling" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarBilling">
                                <i class="ph-receipt"></i> <span>Billing</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarBilling">
                                <ul class="nav nav-sm flex-column">
                                    @can('View folios')
                                        <li class="nav-item"><a href="{{ route('billing.outstanding') }}" class="nav-link">Outstanding Bills</a></li>
                                    @endcan
                                    @can('View finance reports')
                                        <li class="nav-item"><a href="{{ route('billing.payments') }}" class="nav-link">Collections</a></li>
                                    @elsecan('Receive payments')
                                        <li class="nav-item"><a href="{{ route('billing.payments') }}" class="nav-link">My Collections</a></li>
                                    @endcan
                                    @canany(['Use POS', 'Receive payments', 'Review cash shifts', 'View finance reports'])
                                        <li class="nav-item"><a href="{{ route('cash-shifts.index') }}" class="nav-link">@canany(['Review cash shifts', 'View finance reports'])Cashier Shifts @else My Cash Shift @endcanany</a></li>
                                    @endcanany
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses', 'Manage expense categories'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarExpenses" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarExpenses">
                                <i class="ph-wallet"></i> <span>Expenses</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarExpenses">
                                <ul class="nav nav-sm flex-column">
                                    @canany(['Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses'])
                                        <li class="nav-item"><a href="{{ route('expenses.index') }}" class="nav-link">@canany(['View expenses', 'Approve expenses', 'Pay expenses']) All Expenses @else My Expenses @endcanany</a></li>
                                    @endcanany
                                    @can('Record expenses')<li class="nav-item"><a href="{{ route('expenses.index') }}#new" class="nav-link">Record an Expense</a></li>@endcan
                                    @canany(['Approve expenses', 'Pay expenses'])<li class="nav-item"><a href="{{ route('expenses.index', ['status' => 'open']) }}" class="nav-link">To Approve / Pay</a></li>@endcanany
                                    @can('Manage expense categories')<li class="nav-item"><a href="{{ route('expenses.categories.index') }}" class="nav-link">Categories &amp; Budgets</a></li>@endcan
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['Use POS', 'View POS reports', 'Manage POS menu'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarPos" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarPos">
                                <i class="ph-beer-bottle"></i> <span>Bar &amp; Restaurant</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarPos">
                                <ul class="nav nav-sm flex-column">
                                    @can('Use POS')
                                        <li class="nav-item"><a href="{{ route('pos.terminal') }}" class="nav-link">Open Till (POS)</a></li>
                                    @endcan
                                    @if (auth()->user()->can('Use POS') && auth()->user()->can('Charge to room'))
                                        <li class="nav-item"><a href="{{ route('pos.room-orders') }}" class="nav-link">Room Orders</a></li>
                                    @endif
                                    @canany(['View POS reports', 'Use POS'])
                                        <li class="nav-item"><a href="{{ route('pos.orders') }}" class="nav-link">@can('View POS reports')Sales @else My Sales @endcan</a></li>
                                    @endcanany
                                    @can('Manage POS menu')
                                        <li class="nav-item"><a href="{{ route('pos.menu') }}" class="nav-link">Menu &amp; Prices</a></li>
                                    @endcan
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @cannot('View stock')
                        @canany(['Request purchases', 'Manage supplier invoices', 'Pay suppliers'])
                            <li class="nav-item">
                                <a class="nav-link menu-link collapsed" href="#sidebarBuying" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarBuying">
                                    <i class="ph-package"></i> <span>Purchasing</span>
                                </a>
                                <div class="collapse menu-dropdown" id="sidebarBuying">
                                    <ul class="nav nav-sm flex-column">
                                        @can('Request purchases')<li class="nav-item"><a href="{{ route('inventory.requests.index') }}" class="nav-link">Purchase Requests</a></li>@endcan
                                        @canany(['Manage supplier invoices', 'Pay suppliers'])<li class="nav-item"><a href="{{ route('inventory.invoices.index') }}" class="nav-link">Supplier Bills</a></li>@endcanany
                                    </ul>
                                </div>
                            </li>
                        @endcanany
                    @endcannot
                    @can('View stock')
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarStock" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarStock">
                                <i class="ph-package"></i> <span>Inventory</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarStock">
                                <ul class="nav nav-sm flex-column">
                                    <li class="nav-item"><a href="{{ route('inventory.index') }}" class="nav-link">Stock on Hand</a></li>
                                    @can('Receive stock')<li class="nav-item"><a href="{{ route('inventory.receipts.create') }}" class="nav-link">Receive Stock</a></li>@endcan
                                    @canany(['Request purchases', 'Approve purchases', 'Manage purchase orders'])<li class="nav-item"><a href="{{ route('inventory.requests.index') }}" class="nav-link">Purchase Requests</a></li>@endcanany
                                    <li class="nav-item"><a href="{{ route('inventory.po.index') }}" class="nav-link">Purchase Orders</a></li>
                                    @canany(['Manage supplier invoices', 'Pay suppliers'])<li class="nav-item"><a href="{{ route('inventory.invoices.index') }}" class="nav-link">Supplier Bills</a></li>@endcanany
                                    @can('Transfer stock')<li class="nav-item"><a href="{{ route('inventory.transfers.create') }}" class="nav-link">Transfer Stock</a></li>@endcan
                                    <li class="nav-item"><a href="{{ route('inventory.counts.index') }}" class="nav-link">Stocktakes</a></li>
                                    <li class="nav-item"><a href="{{ route('inventory.suppliers.index') }}" class="nav-link">Suppliers</a></li>
                                    @can('View stock reports')<li class="nav-item"><a href="{{ route('inventory.reports') }}" class="nav-link">Stock Reports</a></li>@endcan
                                    @can('View stock reports')<li class="nav-item"><a href="{{ route('reports.advanced', ['report' => 'stock']) }}" class="nav-link">Stock Health &amp; Reorder</a></li>@endcan
                                </ul>
                            </div>
                        </li>
                    @endcan
                    @canany(['View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Manage maintenance'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarOps" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarOps">
                                <i class="ph-paint-brush-household"></i> <span>Housekeeping &amp; Repairs</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarOps">
                                <ul class="nav nav-sm flex-column">
                                    @canany(['View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping'])
                                        <li class="nav-item"><a href="{{ route('housekeeping.index') }}" class="nav-link">@canany(['View housekeeping', 'Manage housekeeping'])Housekeeping Board @else My Rooms to Clean @endcanany</a></li>
                                        <li class="nav-item"><a href="{{ route('housekeeping.board') }}" class="nav-link">Tablet Board</a></li>
                                    @endcanany
                                    @canany(['View maintenance', 'Report maintenance issues', 'Manage maintenance'])
                                        <li class="nav-item"><a href="{{ route('maintenance.index') }}" class="nav-link">Maintenance Jobs</a></li>
                                        @canany(['View maintenance', 'Manage maintenance'])<li class="nav-item"><a href="{{ route('maintenance.plans.index') }}" class="nav-link">Servicing Plans</a></li>@endcanany
                                    @endcanany
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarSecurity" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarSecurity">
                                <i class="ri-shield-user-line"></i> <span>Security</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarSecurity">
                                <ul class="nav nav-sm flex-column">
                                    @can('View visitors')
                                        <li class="nav-item"><a href="{{ route('visitors.index') }}" class="nav-link">Visitors</a></li>
                                        <li class="nav-item"><a href="{{ route('visitors.check') }}" class="nav-link">Check a Visitor Pass</a></li>
                                    @endcan
                                    @canany(['View incidents', 'Report incidents', 'Manage incidents'])
                                        <li class="nav-item"><a href="{{ route('incidents.index') }}" class="nav-link">Incidents</a></li>
                                    @endcanany
                                    @can('Manage lost & found')
                                        <li class="nav-item"><a href="{{ route('lost-found.index') }}" class="nav-link">Lost &amp; Found</a></li>
                                    @endcan
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['View rooms', 'Manage room types', 'Manage buildings'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarRooms" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarRooms">
                                <i class="ph-bed"></i> <span>Rooms &amp; Property</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarRooms">
                                <ul class="nav nav-sm flex-column">
                                    @can('View rooms')
                                        <li class="nav-item"><a href="{{ route('rooms.index') }}" class="nav-link">Room Board</a></li>
                                        <li class="nav-item"><a href="{{ route('rooms.index', ['view' => 'list']) }}" class="nav-link">Room List</a></li>
                                    @endcan
                                    @can('Create rooms')
                                        <li class="nav-item"><a href="{{ route('rooms.bulk.create') }}" class="nav-link">Add Rooms</a></li>
                                    @endcan
                                    @canany(['View rooms', 'Manage room types'])
                                        <li class="nav-item"><a href="{{ route('room-types.index') }}" class="nav-link">Room Types &amp; Rates</a></li>
                                    @endcanany
                                    @canany(['View rooms', 'Manage buildings'])
                                        <li class="nav-item"><a href="{{ route('buildings.index') }}" class="nav-link">Buildings &amp; Floors</a></li>
                                    @endcanany
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @canany(['View attendance', 'Manage staff & shifts', 'Run attendance kiosk'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarAttendance" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarAttendance">
                                <i class="ph-identification-badge"></i> <span>Staff Attendance</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarAttendance">
                                <ul class="nav nav-sm flex-column">
                                    @can('View attendance')
                                        <li class="nav-item"><a href="{{ route('attendance.board') }}" class="nav-link">Who's In Today</a></li>
                                        <li class="nav-item"><a href="{{ route('attendance.timesheet') }}" class="nav-link">Timesheet</a></li>
                                        <li class="nav-item"><a href="{{ route('attendance.leave') }}" class="nav-link">Leave</a></li>
                                    @endcan
                                    @can('Manage staff & shifts')
                                        <li class="nav-item"><a href="{{ route('attendance.setup') }}" class="nav-link">Staff, Shifts &amp; Badges</a></li>
                                    @endcan
                                    @can('Run attendance kiosk')
                                        <li class="nav-item"><a href="{{ route('attendance.kiosk') }}" class="nav-link">Open Clock-in Kiosk</a></li>
                                    @endcan
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @can('Handle guest requests')
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('guest-requests.index') }}">
                                <i class="ph-bell-ringing"></i> <span>Guest Requests</span>
                                @php $openGuestRequests = \App\Models\Booking\GuestRequest::where('status', 'open')->count(); @endphp
                                @if ($openGuestRequests)<span class="badge bg-danger ms-auto">{{ $openGuestRequests }}</span>@endif
                            </a>
                        </li>
                    @endcan
                    @canany(['Chat with guests', 'Manage guest chat'])
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('chat.index') }}">
                                <i class="ph-chats-circle"></i> <span>Guest Chat</span>
                                @php $chatUnread = \Illuminate\Support\Facades\Schema::hasTable('chat_conversations') ? app(\App\Services\Chat\ChatService::class)->unreadFor(auth()->user()) : 0; @endphp
                                @if ($chatUnread)<span class="badge bg-success ms-auto" id="chat-menu-badge">{{ $chatUnread }}</span>@endif
                            </a>
                        </li>
                    @endcanany
                    @can('Message guests')
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('messages.index') }}">
                                <i class="ph-chat-circle-text"></i> <span>Guest Messages</span>
                                @php $guestReplies = \Illuminate\Support\Facades\Schema::hasTable('guest_messages') ? \App\Models\Messaging\GuestMessage::where('direction', 'in')->whereNull('read_at')->count() : 0; @endphp
                                @if ($guestReplies)<span class="badge bg-warning text-dark ms-auto">{{ $guestReplies }}</span>@endif
                            </a>
                        </li>
                    @endcan
                    @canany(['View guest feedback', 'Handle guest feedback'])
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('feedback.index') }}">
                                <i class="ri-star-smile-line"></i> <span>Guest Feedback</span>
                                @php $feedbackOpen = \Illuminate\Support\Facades\Schema::hasTable('guest_feedback') ? \App\Models\Booking\GuestFeedback::where('status', 'follow_up')->count() : 0; @endphp
                                @if ($feedbackOpen)<span class="badge bg-danger ms-auto">{{ $feedbackOpen }}</span>@endif
                            </a>
                        </li>
                    @endcanany
                    {{-- Staff App: the phone version for everyone --}}
                    <li class="nav-item">
                        <a class="nav-link menu-link" href="{{ route('staff.home') }}">
                            <i class="ph-device-mobile"></i> <span>Staff App (phone)</span>
                        </a>
                    </li>
                    {{-- Energy Saver: the control room's list of what to switch off and which generator to run --}}
                    @canany(['View energy saver', 'Manage energy saver'])
                        @php $energyOpen = config('hotel.energy.enabled', true) ? \App\Models\Energy\EnergyAction::where('status', 'open')->count() : 0; @endphp
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('energy.index') }}">
                                <i class="ph-lightning"></i> <span>Energy Saver</span>
                                @if ($energyOpen)<span class="badge bg-success ms-auto">{{ $energyOpen }}</span>@endif
                            </a>
                        </li>
                    @endcanany
                    {{-- IT control room & service desk: everyone can raise IT tickets --}}
                    <li class="nav-item">
                        <a class="nav-link menu-link collapsed" href="#sidebarIt" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarIt">
                            <i class="ph-desktop-tower"></i> <span>IT Support</span>
                        </a>
                        <div class="collapse menu-dropdown" id="sidebarIt">
                            <ul class="nav nav-sm flex-column">
                                @can('Use call console')
                                    <li class="nav-item"><a href="{{ route('it.console.index') }}" class="nav-link">Call Console</a></li>
                                @endcan
                                @canany(['Use call console', 'Manage room equipment'])
                                    <li class="nav-item"><a href="{{ route('it.facilities.index') }}" class="nav-link">Room &amp; Area Equipment</a></li>
                                @endcanany
                                @canany(['View IT control room', 'Manage IT monitoring', 'Manage IT tickets'])
                                    <li class="nav-item"><a href="{{ route('it.index') }}" class="nav-link">Control Room</a></li>
                                    <li class="nav-item"><a href="{{ route('it.alerts.index') }}" class="nav-link">Alerts</a></li>
                                @endcanany
                                <li class="nav-item"><a href="{{ route('it.tickets.index') }}" class="nav-link">@canany(['View IT control room', 'Manage IT tickets']) Service Desk @else My IT Requests @endcanany</a></li>
                                @canany(['View IT control room', 'Manage IT monitoring'])
                                    <li class="nav-item"><a href="{{ route('it.monitors.index') }}" class="nav-link">Monitors</a></li>
                                    <li class="nav-item"><a href="{{ route('it.assets.index') }}" class="nav-link">Equipment</a></li>
                                    <li class="nav-item"><a href="{{ route('it.maintenance.index') }}" class="nav-link">Planned Maintenance</a></li>
                                @endcanany
                                @canany(['View network', 'Manage network', 'Give Wi-Fi codes'])
                                    <li class="nav-item"><a href="{{ route('it.network.index') }}" class="nav-link">Internet &amp; Wi-Fi</a></li>
                                @endcanany
                                @canany(['View IT control room', 'Manage IT monitoring', 'Manage IT tickets'])
                                    <li class="nav-item"><a href="{{ route('it.report') }}" class="nav-link">Monthly Service Report</a></li>
                                @endcanany
                            </ul>
                        </div>
                    </li>
                    {{-- Website: the public site's content, enquiries and newsletter --}}
                    @canany(['Manage website', 'View website enquiries'])
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarWebsite" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarWebsite">
                                <i class="ph-globe-hemisphere-west"></i> <span>Website</span>
                                @php $siteEnquiries = \Illuminate\Support\Facades\Schema::hasTable('website_messages') ? \App\Models\Website\WebsiteMessage::where('status', 'new')->count() : 0; @endphp
                                @if ($siteEnquiries)<span class="badge bg-danger ms-auto">{{ $siteEnquiries }}</span>@endif
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarWebsite">
                                <ul class="nav nav-sm flex-column">
                                    <li class="nav-item"><a href="{{ route('website.index') }}" class="nav-link">Overview</a></li>
                                    @can('Manage website')
                                        <li class="nav-item"><a href="{{ route('website.content.index', 'slide') }}" class="nav-link">Home Page Slides</a></li>
                                        <li class="nav-item"><a href="{{ route('website.content.index', 'offer') }}" class="nav-link">Offers</a></li>
                                        <li class="nav-item"><a href="{{ route('website.content.index', 'dining') }}" class="nav-link">Dining</a></li>
                                        <li class="nav-item"><a href="{{ route('website.content.index', 'gallery') }}" class="nav-link">Gallery</a></li>
                                        <li class="nav-item"><a href="{{ route('website.content.index', 'post') }}" class="nav-link">Journal</a></li>
                                        <li class="nav-item"><a href="{{ route('website.settings') }}" class="nav-link">Settings &amp; Texts</a></li>
                                    @endcan
                                    <li class="nav-item"><a href="{{ route('website.enquiries.index') }}" class="nav-link">Enquiries</a></li>
                                    <li class="nav-item"><a href="{{ route('home') }}" target="_blank" rel="noopener" class="nav-link">Open the Website <i class="ri-external-link-line"></i></a></li>
                                </ul>
                            </div>
                        </li>
                    @endcanany
                    @can('Manage hotel settings')
                        <li class="nav-item">
                            <a class="nav-link menu-link collapsed" href="#sidebarSettings" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarSettings">
                                <i class="ph-gear-six"></i> <span>Settings</span>
                            </a>
                            <div class="collapse menu-dropdown" id="sidebarSettings">
                                <ul class="nav nav-sm flex-column">
                                    <li class="nav-item"><a href="{{ route('settings.hotel.index') }}" class="nav-link">Hotel Settings</a></li>
                                    @can('Manage hotels')<li class="nav-item"><a href="{{ route('settings.hotels.index') }}" class="nav-link">Hotels</a></li>@endcan
                                    <li class="nav-item"><a href="{{ route('settings.bank-accounts.index') }}" class="nav-link">Bank Accounts</a></li>
                                </ul>
                            </div>
                        </li>
                    @endcan
                    @can('View audit log')
                        <li class="nav-item">
                            <a class="nav-link menu-link" href="{{ route('audit.index') }}">
                                <i class="ph-shield-check"></i> <span>Audit Trail</span>
                            </a>
                        </li>
                    @endcan


                </ul>
            </div>
        </div><!-- /scrollbar -->

        <!-- ===== SIDEBAR LOGOUT FOOTER ===== -->
        @auth
        <div class="sidebar-footer">
            @php
                $sidebarUser     = Auth::user();
                $sidebarSrc      = $sidebarUser->avatar ? $sidebarUser->avatar_url : null;
                $sidebarInitials = $sidebarUser->initials;
            @endphp

            <div class="sidebar-footer-user">
                @if($sidebarSrc)
                    <img src="{{ $sidebarSrc }}" alt="{{ $sidebarUser->name }}">
                @else
                    <span class="sidebar-footer-avatar-initials">{{ $sidebarInitials }}</span>
                @endif
                <div class="sidebar-footer-user-info">
                    <div class="sidebar-footer-user-name">{{ $sidebarUser->name }}</div>
                    <div class="sidebar-footer-user-role">{{ $sidebarUser->roles->first()->name ?? 'User' }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" id="sidebar-logout-form">
                @csrf
                <button type="submit" class="sidebar-logout-btn">
                    <i class="mdi mdi-logout"></i>
                    <span>Sign Out</span>
                </button>
            </form>
        </div>
        @endauth

        <div class="sidebar-background"></div>
    </div><!-- /app-menu -->

    <div class="vertical-overlay" id="vertical-overlay"></div>

    <!-- ========== TOPBAR ========== -->
    <header id="page-topbar">
        <div class="layout-width">
            <div class="navbar-header">
                <div class="d-flex">
                    <div class="navbar-brand-box horizontal-logo">
                        <a href="{{ url('/') }}" class="logo hotel-name-logo"><span class="logo-lg">{{ config('hotel.name') }}</span></a>
                    </div>
                    <button type="button" class="btn btn-sm px-3 fs-16 header-item vertical-menu-btn topnav-hamburger shadow-none" id="topnav-hamburger-icon">
                        <span class="hamburger-icon"><span></span><span></span><span></span></span>
                    </button>
                    <div class="d-none d-md-inline-flex align-items-center" style="position:relative;">
                        <button type="button" id="spotlight-trigger" style="display:flex;align-items:center;gap:8px;border-radius:10px;padding:7px 14px;cursor:pointer;transition:all .2s;min-width:220px;">
                            <i class="mdi mdi-magnify" style="font-size:16px;opacity:.8;"></i>
                            <span style="font-size:13px;flex:1;text-align:left;">Search everything…</span>
                            <div style="display:flex;gap:4px;"><kbd style="font-size:10px;padding:2px 6px;border-radius:4px;">⌘</kbd><kbd style="font-size:10px;padding:2px 6px;border-radius:4px;">K</kbd></div>
                        </button>
                        <div class="search-tooltip">Press <kbd>⌘K</kbd> or <kbd>Ctrl+K</kbd> to search</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-1">
                    <!-- Hotel switcher (multi-hotel) -->
                    @if (\App\Support\Tenancy\CurrentHotel::multi() && auth()->check())
                        @php $myHotels = auth()->user()->accessibleHotels(); $hereHotel = \App\Support\Tenancy\CurrentHotel::get()->hotel(); @endphp
                        <div class="dropdown header-item">
                            <button type="button" class="btn btn-sm btn-light d-flex align-items-center gap-2 px-3" data-bs-toggle="dropdown" aria-expanded="false" title="The hotel you are working in" @disabled($myHotels->count() < 2)>
                                <i class="ri-hotel-line"></i><span class="d-none d-sm-inline fw-semibold text-truncate" style="max-width:180px">{{ $hereHotel?->name }}</span>@if ($myHotels->count() > 1)<i class="ri-arrow-down-s-line"></i>@endif
                            </button>
                            @if ($myHotels->count() > 1)
                                <div class="dropdown-menu dropdown-menu-end p-1" style="min-width:240px">
                                    @if (auth()->user()->seesAllHotels())<a class="dropdown-item rounded-2" href="{{ route('group.index') }}"><i class="ri-community-line me-1"></i> Group overview</a><a class="dropdown-item rounded-2" href="{{ route('group.commission') }}"><i class="ri-percent-line me-1"></i> Commission</a><a class="dropdown-item rounded-2" href="{{ route('group.reports') }}"><i class="ri-bar-chart-grouped-line me-1"></i> Group reports</a><div class="dropdown-divider"></div>@endif
                                    <div class="px-3 py-2 small text-muted">Work in</div>
                                    @foreach ($myHotels as $h)
                                        <form method="POST" action="{{ route('hotels.switch') }}">@csrf<input type="hidden" name="hotel_id" value="{{ $h->id }}">
                                            <button class="dropdown-item d-flex justify-content-between align-items-center rounded-2 {{ $h->id === $hereHotel?->id ? 'active' : '' }}"><span>{{ $h->name }}@if ($h->location)<small class="d-block text-muted">{{ $h->location }}</small>@endif</span>@if ($h->id === $hereHotel?->id)<i class="ri-check-line"></i>@endif</button>
                                        </form>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Notifications bell -->

                    <!-- Theme Toggle -->
                    <div class="position-relative" id="theme-toggle-wrapper">
                        <button type="button" id="theme-toggle-btn" class="btn btn-icon btn-topbar btn-ghost-dark rounded-circle" style="width:38px;height:38px;">
                            <i id="theme-icon" class="bi bi-sun align-middle fs-3xl"></i>
                        </button>
                        <div id="theme-dropdown" style="display:none;position:absolute;top:calc(100% + 8px);right:0;min-width:170px;background:var(--vz-dropdown-bg,#fff);border:1px solid var(--vz-border-color,#e9ebec);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;overflow:hidden;padding:6px;">
                            <a href="javascript:void(0)" class="theme-mode-item d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-decoration-none" data-mode="light"><i class="bi bi-sun"></i> Light</a>
                            <a href="javascript:void(0)" class="theme-mode-item d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-decoration-none" data-mode="dark"><i class="bi bi-moon"></i> Dark</a>
                            <a href="javascript:void(0)" class="theme-mode-item d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-decoration-none" data-mode="auto"><i class="bi bi-moon-stars"></i> Auto</a>
                        </div>
                    </div>

                    <!-- ===== USER DROPDOWN ===== -->
                    @php
                        $userdata  = Auth::user();
                        $fullName  = $userdata->name ?? 'User';
                        $initials  = $userdata->initials;
                        $srcPath   = $userdata->avatar ? $userdata->avatar_url : null;
                        $userRoles = $userdata->roles->pluck('name');
                    @endphp

                    <div class="dropdown position-relative ms-sm-3 header-item topbar-user" id="user-dropdown-wrapper">
                        <button type="button" id="user-menu-btn" class="btn shadow-none p-0" style="background:transparent;border:none;">
                            <span class="d-flex align-items-center gap-2">
                                <span style="display:inline-block;width:42px;height:42px;flex-shrink:0;position:relative;">
                                    @if($srcPath)
                                        <img id="topbar-avatar-img" src="{{ $srcPath }}" alt="{{ $fullName }}" style="width:42px;height:42px;border-radius:10px;object-fit:cover;" onerror="this.style.display='none';document.getElementById('topbar-avatar-fallback').style.display='flex';">
                                        <span id="topbar-avatar-fallback" style="display:none;width:42px;height:42px;border-radius:10px;background:#405189;color:#fff;align-items:center;justify-content:center;">{{ $initials }}</span>
                                    @else
                                        <span style="display:flex;width:42px;height:42px;border-radius:10px;background:#405189;color:#fff;align-items:center;justify-content:center;">{{ $initials }}</span>
                                    @endif
                                </span>
                                <span class="d-none d-xl-flex flex-column align-items-start ms-1"><span class="fw-medium" style="font-size:13px;">{{ $userdata->name }}</span></span>
                            </span>
                        </button>

                        <div id="user-dropdown" class="dropdown-menu dropdown-menu-end" style="display:none;position:absolute;top:calc(100% + 8px);right:0;min-width:220px;background:var(--vz-dropdown-bg,#fff);border-radius:12px;z-index:9999;">
                            <div class="dropdown-header"><h6 class="mb-0">Welcome back!</h6><small class="text-muted">{{ $userdata->name }}</small></div>
                            <div class="dropdown-divider"></div>
                            <div class="px-3 py-2">
                                <div class="small text-muted mb-2 text-uppercase" style="font-size:10px;">Your Roles</div>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($userRoles as $roleName)
                                        @php
                                            $roleColors = [
                                                'super admin'     => ['bg'=>'#405189','light'=>'#eef2ff'],
                                                'hotel owner'     => ['bg'=>'#6a0572','light'=>'#f3e8ff'],
                                                'general manager' => ['bg'=>'#0a9396','light'=>'#e0f2fe'],
                                                'front desk'      => ['bg'=>'#e76f51','light'=>'#fff0ed'],
                                                'accountant'      => ['bg'=>'#2a9d8f','light'=>'#e6f7f5'],
                                                'cashier'         => ['bg'=>'#e9c46a','light'=>'#fefce8'],
                                                'it operations'   => ['bg'=>'#457b9d','light'=>'#e8f0fe'],
                                            ];
                                            $rk    = strtolower($roleName);
                                            $color = $roleColors[$rk]['bg']    ?? '#6c757d';
                                            $bg    = $roleColors[$rk]['light'] ?? '#f8fafc';
                                        @endphp
                                        <span style="display:inline-block;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;background:{{ $bg }};color:{{ $color }};">{{ $roleName }}</span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ route('users.overview', $userdata->id) }}"><i class="mdi mdi-account-circle me-2"></i>My Profile</a>
                            <a class="dropdown-item" href="{{ route('profile.settings', ['id' => $userdata->id]) }}"><i class="mdi mdi-cog me-2"></i>Account Settings</a>
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('logout') }}" id="topbar-logout-form">@csrf<a class="dropdown-item text-danger" href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('topbar-logout-form').submit();"><i class="mdi mdi-logout me-2"></i>Logout</a></form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Image View Modal -->
    <div class="modal fade" id="imageViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title">Profile Photo</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <img id="enlargedImage" src="" alt="Profile" class="img-fluid rounded-3" style="max-height:400px;">
                </div>
            </div>
        </div>
    </div>

    @yield('content')

    <footer class="footer">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    {{ now()->year }} © {{ config('hotel.name') }}
                </div>
                <div class="col-sm-6">
                    <div class="text-sm-end">
                        @include('partials.qlouds-credit')
                    </div>
                </div>
            </div>
        </div>
    </footer>
</div>

<button class="btn btn-dark btn-icon" id="back-to-top"><i class="bi bi-caret-up fs-3xl"></i></button>
<div id="preloader"><div id="status"><div class="spinner-border text-primary avatar-sm" role="status"><span class="visually-hidden">Loading...</span></div></div></div>

<!-- Customizer trigger -->
<div class="customizer-setting d-none d-md-block">
    <div class="btn btn-info p-2 text-uppercase rounded-end-0 shadow-lg"
         data-bs-toggle="offcanvas" data-bs-target="#theme-settings-offcanvas"
         aria-controls="theme-settings-offcanvas">
        <i class="bi bi-gear mb-1"></i> Customizer
    </div>
</div>

<!-- Theme Settings Offcanvas -->
<div class="offcanvas offcanvas-end border-0" tabindex="-1" id="theme-settings-offcanvas">
    {{-- The theme's script shows or hides these customizer sections; they were trimmed from this customizer,
         so empty stand-ins stop it failing (and stopping the rest of the page's set-up) on every page. --}}
    <div class="d-none" aria-hidden="true"><div id="sidebar-size"></div><div id="sidebar-view"></div><div id="layout-width"></div><div id="layout-position"></div></div>
    <div class="d-flex align-items-center bg-primary bg-gradient p-3 offcanvas-header">
        <div class="me-2">
            <h5 class="mb-1 text-white">Theme Customizer</h5>
            <p class="text-white text-opacity-75 mb-0">Customize your experience</p>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto" id="customizerclose-btn"
                data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div data-simplebar class="h-100">
            <div class="p-4">
                <h6 class="fs-md mb-1">Color Scheme</h6>
                <p class="text-muted fs-sm">Choose Light or Dark Scheme.</p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="form-check card-radio">
                            <input class="form-check-input" type="radio" name="data-bs-theme" id="layout-mode-light" value="light">
                            <label class="form-check-label p-0 bg-transparent" for="layout-mode-light">
                                <img src="{{ asset('theme/layouts/assets/images/custom-theme/light-mode.png') }}" alt="" class="img-fluid">
                            </label>
                        </div>
                        <h5 class="fs-sm text-center fw-medium mt-2">Light</h5>
                    </div>
                    <div class="col-6">
                        <div class="form-check card-radio dark">
                            <input class="form-check-input" type="radio" name="data-bs-theme" id="layout-mode-dark" value="dark">
                            <label class="form-check-label p-0 bg-transparent" for="layout-mode-dark">
                                <img src="{{ asset('theme/layouts/assets/images/custom-theme/dark-mode.png') }}" alt="" class="img-fluid">
                            </label>
                        </div>
                        <h5 class="fs-sm text-center fw-medium mt-2">Dark</h5>
                    </div>
                </div>
                <div id="sidebar-color">
                    <h6 class="mt-4 fs-md mb-1">Sidebar Color</h6>
                    <p class="text-muted fs-sm">Choose a color of Sidebar.</p>
                    <div class="row">
                        <div class="col-4">
                            <div class="form-check sidebar-setting card-radio">
                                <input class="form-check-input" type="radio" name="data-sidebar" id="sidebar-color-light" value="light">
                                <label class="form-check-label p-0 avatar-md w-100" for="sidebar-color-light">
                                    <span class="d-flex gap-1 h-100"><span class="flex-shrink-0"><span class="bg-white border-end d-flex h-100 flex-column gap-1 p-1"><span class="d-block p-1 px-2 bg-primary-subtle rounded mb-2"></span><span class="d-block p-1 px-2 pb-0 bg-primary-subtle"></span></span></span><span class="flex-grow-1"><span class="d-flex h-100 flex-column"><span class="bg-light d-block p-1"></span></span></span></span>
                                </label>
                            </div>
                            <h5 class="fs-sm text-center fw-medium mt-2">Light</h5>
                        </div>
                        <div class="col-4">
                            <div class="form-check sidebar-setting card-radio">
                                <input class="form-check-input" type="radio" name="data-sidebar" id="sidebar-color-dark" value="dark">
                                <label class="form-check-label p-0 avatar-md w-100" for="sidebar-color-dark">
                                    <span class="d-flex gap-1 h-100"><span class="flex-shrink-0"><span class="bg-primary d-flex h-100 flex-column gap-1 p-1"><span class="d-block p-1 px-2 bg-soft-light rounded mb-2"></span><span class="d-block p-1 px-2 pb-0 bg-soft-light"></span></span></span><span class="flex-grow-1"><span class="d-flex h-100 flex-column"><span class="bg-light d-block p-1"></span></span></span></span>
                                </label>
                            </div>
                            <h5 class="fs-sm text-center fw-medium mt-2">Dark</h5>
                        </div>
                    </div>
                </div>
                <div style="display:none;">
                    <input type="radio" id="topbar-color-light" name="data-topbar" value="light">
                    <input type="radio" id="topbar-color-dark"  name="data-topbar" value="dark">
                </div>
            </div>
        </div>
    </div>
    <div class="offcanvas-footer border-top p-3 text-center">
        <div class="row">
            <div class="col-6">
                <button type="button" class="btn btn-light w-100" id="reset-layout">Reset</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== SPOTLIGHT MODAL ========== -->
<div id="spotlight-overlay" style="display:none;position:fixed;inset:0;z-index:1060;align-items:flex-start;justify-content:center;padding-top:12vh;">
    <div id="spotlight-box" style="width:100%;max-width:720px;margin:0 24px;background:rgba(28,28,30,0.98);border:1px solid rgba(255,255,255,0.12);border-radius:32px;box-shadow:0 32px 80px rgba(0,0,0,0.6),0 0 0 0.5px rgba(255,255,255,0.08);overflow:hidden;backdrop-filter:blur(20px);">
        <div style="display:flex;align-items:center;gap:16px;padding:20px 24px;border-bottom:0.5px solid rgba(255,255,255,0.1);">
            <i class="mdi mdi-magnify" style="font-size:28px;color:#4f8ef7;flex-shrink:0;"></i>
            <input id="spotlight-input" type="text" placeholder="Search everything..." autocomplete="off"
                   style="flex:1;background:transparent;border:none;outline:none;font-size:1.5rem;font-weight:500;color:#fff;caret-color:#4f8ef7;padding:8px 0;">
            <div style="display:flex;gap:8px;">
                <button id="spotlight-clear-history" style="display:none;background:rgba(255,255,255,0.08);border:none;border-radius:10px;padding:6px 12px;color:rgba(255,255,255,0.6);font-size:12px;font-weight:500;cursor:pointer;">Clear History</button>
                <kbd id="spotlight-esc" style="font-size:13px;padding:5px 12px;border-radius:10px;background:rgba(255,255,255,0.08);border:0.5px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.6);cursor:pointer;">ESC</kbd>
            </div>
        </div>
        <div id="spotlight-results" style="max-height:540px;overflow-y:auto;padding:12px 0;">
            <!-- Suggestion chips shown while typing -->
            <div id="spotlight-suggestions" style="display:none;padding:10px 24px 0;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);margin-bottom:8px;">Suggestions</div>
                <div id="spotlight-suggestion-chips" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                <div style="height:1px;background:rgba(255,255,255,.06);margin:14px -4px 0;"></div>
            </div>
            <!-- History section -->
            <div id="spotlight-history-section" style="display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 24px 8px;">
                    <span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.4);">Recent Searches</span>
                    <button id="spotlight-clear-history-btn" style="background:transparent;border:none;color:rgba(255,255,255,0.4);font-size:12px;cursor:pointer;">Clear All</button>
                </div>
                <div id="spotlight-history-list"></div>
                <div style="height:1px;background:rgba(255,255,255,0.06);margin:12px 20px;"></div>
            </div>
            <!-- Empty / default state -->
            <div id="spotlight-empty" style="padding:48px 24px;text-align:center;color:rgba(255,255,255,0.35);">
                <i class="mdi mdi-lightning-bolt" style="font-size:48px;display:block;margin-bottom:16px;opacity:0.4;"></i>
                <span style="font-size:15px;">Start typing to search…</span>
                <div style="margin-top:16px;font-size:12px;opacity:0.4;">Popular: Users, Roles, Permissions, Profile</div>
            </div>
            <ul id="spotlight-list" style="list-style:none;margin:0;padding:0;display:none;"></ul>
            <div id="spotlight-loading" style="display:none;padding:48px;text-align:center;">
                <div style="display:inline-block;width:32px;height:32px;border:2px solid rgba(255,255,255,0.15);border-top-color:#4f8ef7;border-radius:50%;animation:loadingSpin 0.7s linear infinite;"></div>
                <div style="margin-top:16px;font-size:13px;color:rgba(255,255,255,0.45);">Searching<span class="typing-dot">.</span><span class="typing-dot">.</span><span class="typing-dot">.</span></div>
            </div>
        </div>
        <div style="padding:14px 24px;border-top:0.5px solid rgba(255,255,255,0.07);display:flex;gap:24px;font-size:12px;color:rgba(255,255,255,0.35);flex-wrap:wrap;">
            <span><kbd style="background:rgba(255,255,255,0.1);border-radius:5px;padding:2px 8px;">⌘K</kbd> / <kbd style="background:rgba(255,255,255,0.1);border-radius:5px;padding:2px 8px;">Ctrl+K</kbd> open</span>
            <span><kbd style="background:rgba(255,255,255,0.1);border-radius:5px;padding:2px 8px;">↑↓</kbd> navigate</span>
            <span><kbd style="background:rgba(255,255,255,0.1);border-radius:5px;padding:2px 8px;">↵</kbd> open</span>
            <span><kbd style="background:rgba(255,255,255,0.1);border-radius:5px;padding:2px 8px;">ESC</kbd> close</span>
        </div>
    </div>
</div>

<!-- =====================================================
     SCRIPTS
     ===================================================== -->
<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/app.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/plugins.js') }}"></script>
<script src="{{ asset('vendor/select2/select2.min.js') }}"></script>

<script>
(function () {
    'use strict';

    /* ── helpers ── */
    function qs(sel, ctx)  { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* ── manual dropdown factory ── */
    function makeDropdown(btnId, panelId) {
        var btn = document.getElementById(btnId), panel = document.getElementById(panelId);
        if (!btn || !panel) return;
        function open()  { panel.style.display = 'block'; btn.setAttribute('aria-expanded','true'); }
        function close() { panel.style.display = 'none';  btn.setAttribute('aria-expanded','false'); }
        btn.addEventListener('click', function(e){ e.stopPropagation(); panel.style.display === 'none' ? open() : close(); });
        document.addEventListener('click', function(e){ if (!btn.contains(e.target) && !panel.contains(e.target)) close(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    }

    /* ── theme ── */
    function initTheme() {
        var html   = document.documentElement;
        var iconEl = document.getElementById('theme-icon');
        var ICON   = { light:'bi bi-sun align-middle fs-3xl', dark:'bi bi-moon align-middle fs-3xl', auto:'bi bi-moon-stars align-middle fs-3xl' };

        function applyMode(mode) {
            var scheme = mode === 'auto' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : mode;
            html.setAttribute('data-bs-theme', scheme);
            html.setAttribute('data-topbar', scheme === 'dark' ? 'dark' : 'light');
            if (iconEl) iconEl.className = ICON[mode] || ICON.light;
            localStorage.setItem('app-theme', mode);
            var r = document.getElementById(scheme === 'dark' ? 'layout-mode-dark' : 'layout-mode-light');
            if (r) r.checked = true;
            qsa('.theme-mode-item').forEach(function(a){
                a.style.fontWeight = a.getAttribute('data-mode') === mode ? '600' : '';
                a.style.color      = a.getAttribute('data-mode') === mode ? 'var(--vz-primary,#405189)' : '';
            });
        }
        applyMode(localStorage.getItem('app-theme') || 'light');
        qsa('.theme-mode-item').forEach(function(a){
            a.addEventListener('click', function(e){ e.preventDefault(); applyMode(a.getAttribute('data-mode')); document.getElementById('theme-dropdown').style.display='none'; });
        });
        qsa('[name="data-bs-theme"]').forEach(function(r){ r.addEventListener('change', function(){ applyMode(r.value); }); });
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(){
            if (localStorage.getItem('app-theme') === 'auto') applyMode('auto');
        });
    }

    /* ── NProgress ── */
    function initNProgress() {
        if (typeof NProgress === 'undefined') return;
        NProgress.configure({ showSpinner: false, speed: 400, minimum: 0.1 });
        qsa('a[href]').forEach(function(a){
            var h = a.getAttribute('href') || '';
            if (h && h !== '#' && !h.startsWith('javascript') && !h.startsWith('mailto') && !h.startsWith('tel')
                && !a.hasAttribute('data-bs-toggle') && !a.hasAttribute('data-bs-dismiss') && a.getAttribute('target') !== '_blank') {
                a.addEventListener('click', function(){ NProgress.start(); });
            }
        });
        window.addEventListener('pageshow', function(){ NProgress.done(); });
        window.addEventListener('load',     function(){ NProgress.done(); });
    }

    /* ── active sidebar ── */
    function initActiveSidebar() {
        var cur = (window.location.pathname || '/').replace(/\/+$/, '') || '/';
        var best = null, bestLen = -1;
        // Consider every real sidebar link (top-level direct links AND submenu children).
        qsa('#navbar-nav a.nav-link[href]').forEach(function(link){
            var href = link.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript') === 0) return;
            var lp;
            try { lp = (new URL(link.href, window.location.origin).pathname || '/').replace(/\/+$/, '') || '/'; }
            catch(e){ return; }
            var match = (lp === cur) || (lp.length > 1 && cur.indexOf(lp + '/') === 0);
            if (match && lp.length > bestLen) { best = link; bestLen = lp.length; }
        });
        if (!best) return;
        best.classList.add('active');
        if (best.closest('.nav-sm')) best.classList.add('nav-active-child');
        else best.classList.add('nav-active-parent');
        var col = best.closest('.collapse');
        if (col) {
            col.classList.add('show');
            var tog = qs('[data-bs-target="#'+col.id+'"],[href="#'+col.id+'"]');
            if (tog) {
                tog.setAttribute('aria-expanded','true');
                tog.classList.remove('collapsed');
                tog.classList.add('nav-active-parent','active');
            }
        }
        setTimeout(function(){ try { best.scrollIntoView({behavior:'smooth',block:'nearest'}); } catch(e){} }, 350);
    }

    /* ── ripple ── */
    function initRipple() {
        qsa('#navbar-nav .nav-link').forEach(function(link){
            link.addEventListener('click', function(e){
                if (link.hasAttribute('data-bs-toggle')) return;
                var r = document.createElement('span');
                var rect = link.getBoundingClientRect();
                var s = Math.max(rect.width, rect.height);
                r.className = 'nav-ripple';
                r.style.cssText = 'width:'+s+'px;height:'+s+'px;left:'+(e.clientX-rect.left-s/2)+'px;top:'+(e.clientY-rect.top-s/2)+'px;';
                link.appendChild(r);
                setTimeout(function(){ r.parentNode && r.parentNode.removeChild(r); }, 650);
            });
        });
    }

    /* ── back to top ── */
    function initBackToTop() {
        var btn = document.getElementById('back-to-top');
        if (!btn) return;
        window.addEventListener('scroll', function(){ btn.classList.toggle('show', window.scrollY > 300); }, {passive:true});
        btn.addEventListener('click', function(){ window.scrollTo({top:0,behavior:'smooth'}); });
    }

    /* ── hamburger / mobile sidebar ── */
    function initHamburger() {
        var ham     = document.getElementById('topnav-hamburger-icon');
        var overlay = document.getElementById('vertical-overlay');
        var body    = document.body;

        function closeSidebar() { body.classList.remove('vertical-sidebar-enable'); }
        function openSidebar()  { body.classList.add('vertical-sidebar-enable'); }

        if (ham) {
            var freshHam = ham.cloneNode(true);
            ham.parentNode.replaceChild(freshHam, ham);
            document.getElementById('topnav-hamburger-icon').addEventListener('click', function(e){
                e.preventDefault(); e.stopPropagation();
                body.classList.contains('vertical-sidebar-enable') ? closeSidebar() : openSidebar();
            });
        }
        if (overlay) overlay.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && body.classList.contains('vertical-sidebar-enable')) closeSidebar();
        });
    }

    /* ── image modal ── */
    function initImageModal() {
        var modal = document.getElementById('imageViewModal');
        if (!modal) return;
        modal.addEventListener('show.bs.modal', function(e){
            var img = document.getElementById('enlargedImage');
            var src = e.relatedTarget ? e.relatedTarget.getAttribute('data-image') : null;
            if (img && src) img.src = src;
        });
    }

    /* ── search tooltip ── */
    function initSearchTooltip() {
        var btn = document.getElementById('spotlight-trigger');
        var tip = qs('.search-tooltip');
        if (!btn || !tip) return;
        btn.addEventListener('mouseenter', function(){ tip.style.opacity='1'; });
        btn.addEventListener('mouseleave', function(){ tip.style.opacity='0'; });
    }

    /* ── reset layout ── */
    function initReset() {
        var btn = document.getElementById('reset-layout');
        if (btn) btn.addEventListener('click', function(){ sessionStorage.clear(); localStorage.removeItem('app-theme'); location.reload(); });
    }

    /* ── INIT ── */
    document.addEventListener('DOMContentLoaded', function(){
        initTheme();
        makeDropdown('theme-toggle-btn', 'theme-dropdown');
        makeDropdown('user-menu-btn',    'user-dropdown');
        initActiveSidebar();
        initRipple();
        initBackToTop();
        initHamburger();
        initImageModal();
        initSearchTooltip();
        initReset();
        initNProgress();
    });
})();
</script>

<!-- =====================================================
     SPOTLIGHT SEARCH — full registry with all routes
     ===================================================== -->
<script>
(function(){
    'use strict';

    /* ─────────────────────────────────────────────────────────────────
       COMPLETE PAGE REGISTRY — every navigable route in the system
       ───────────────────────────────────────────────────────────────── */
    var STATIC_PAGES = [
        /* ── Dashboards ── */
        @can('dashboard')
        {title:'Hotel Overview',                        url:'{{ route("dashboard") }}',                                      icon:'mdi-gauge',                  category:'Dashboards',          keywords:['home','analytics','overview','dashboard']},
        @endcan

        @can('View management reports')
        {title:'Management Reports',                    url:'{{ route("reports.index") }}',                                  icon:'mdi-chart-line',              category:'Dashboards',          keywords:['report','occupancy','adr','revpar','revenue','department','kpi','analytics','performance']},
        @endcan
        @canany(['View management reports', 'View finance reports'])
        {title:'Gross Profit Report',                   url:'{{ route("reports.advanced", ["report" => "profit"]) }}',      icon:'mdi-finance',                 category:'Reports',             keywords:['profit','margin','cost of sales','cogs','gross']},
        {title:'12-Month Trend',                        url:'{{ route("reports.advanced", ["report" => "trend"]) }}',       icon:'mdi-chart-timeline-variant',  category:'Reports',             keywords:['trend','monthly','year','occupancy','adr','revpar']},
        {title:'Tax Report',                            url:'{{ route("reports.advanced", ["report" => "tax"]) }}',         icon:'mdi-percent',                 category:'Reports',             keywords:['tax','vat','service charge','consumption','firs']},
        {title:'Debtors & Creditors (Ageing)',          url:'{{ route("reports.advanced", ["report" => "ageing"]) }}',      icon:'mdi-timer-sand',              category:'Reports',             keywords:['ageing','aging','debtors','creditors','receivable','payable','owed']},
        {title:'Discounts, Voids & Cash',               url:'{{ route("reports.advanced", ["report" => "exceptions"]) }}',  icon:'mdi-alert-outline',           category:'Reports',             keywords:['void','discount','refund','cash short','exceptions','fraud']},
        @endcanany
        @canany(['View management reports', 'View stock reports'])
        {title:'Stock Health & Reorder',                url:'{{ route("reports.advanced", ["report" => "stock"]) }}',       icon:'mdi-heart-pulse',             category:'Reports',             keywords:['reorder','slow moving','dead stock','days of cover','forecast','par']},
        {title:'Stocktake Variance',                    url:'{{ route("reports.advanced", ["report" => "variance"]) }}',    icon:'mdi-clipboard-check-outline', category:'Reports',             keywords:['variance','stocktake','count','shortage']},
        {title:'Purchasing & Suppliers',                url:'{{ route("reports.advanced", ["report" => "purchasing"]) }}',  icon:'mdi-truck-outline',           category:'Reports',             keywords:['supplier','purchase','price change','fill rate','spend']},
        @endcanany

        /* ── Users & Privileges ── */
        @can('View user')
        {title:'User Management',                       url:'{{ route("users.index") }}',                                    icon:'mdi-account-group',           category:'Users & Privileges',  keywords:['staff','accounts','login','users']},
        @endcan
        @can('View role')
        {title:'Roles',                                 url:'{{ route("roles.index") }}',                                    icon:'mdi-shield-account',          category:'Users & Privileges',  keywords:['permissions','access','roles']},
        @endcan
        @can('View permission')
        {title:'Permissions',                           url:'{{ route("permissions.index") }}',                              icon:'mdi-lock',                    category:'Users & Privileges',  keywords:['access','rights','permissions']},
        @endcan

        /* ── My Account ── */
        {title:'My Profile',                            url:'{{ route("users.overview", ["id" => Auth::id()]) }}',           icon:'mdi-account-circle',          category:'My Account',          keywords:['profile','bio','account']},
        {title:'Account Settings',                      url:'{{ route("profile.settings", ["id" => Auth::id()]) }}',         icon:'mdi-cog',                     category:'My Account',          keywords:['settings','password','avatar']},
        {title:'Two-step Sign-in',                      url:'{{ route("two-factor.show") }}',                                icon:'mdi-shield-key-outline',      category:'My Account',          keywords:['2fa','mfa','two factor','authenticator','code','security','otp']},
        @can('Update user')
        {title:'Staff Two-step Sign-in',                url:'{{ route("security.two-factor") }}',                            icon:'mdi-shield-key-outline',      category:'Users & Privileges',  keywords:['2fa','mfa','reset','lost phone','authenticator']},
        @endcan

        /* ── Hotel modules: add an entry per page as modules are built ── */
        @can('View rooms')
        {title:'Room Board',                            url:'{{ route("rooms.index") }}',                                    icon:'mdi-view-grid',               category:'Rooms',               keywords:['rooms','status','board','available','occupied','housekeeping']},
        {title:'Room List',                             url:'{{ route("rooms.index", ["view" => "list"]) }}',                icon:'mdi-format-list-bulleted',    category:'Rooms',               keywords:['rooms','list','table']},
        {title:'Room Types & Rates',                    url:'{{ route("room-types.index") }}',                               icon:'mdi-tag-multiple',            category:'Rooms',               keywords:['room type','rate','price','suite','deluxe','standard']},
        {title:'Buildings & Floors',                    url:'{{ route("buildings.index") }}',                                icon:'mdi-office-building',         category:'Rooms',               keywords:['building','block','floor','property']},
        @endcan
        @can('View reservations')
        {title:'Arrivals & Departures',                 url:'{{ route("reservations.index") }}',                             icon:'mdi-account-arrow-right',     category:'Front Desk',          keywords:['front desk','arrivals','departures','in house','check in','check out','reservations']},
        {title:'Booking Calendar',                      url:'{{ route("reservations.calendar") }}',                          icon:'mdi-calendar-month',          category:'Front Desk',          keywords:['calendar','tape chart','bookings','availability']},
        {title:'All Reservations',                      url:'{{ route("reservations.index", ["tab" => "all"]) }}',           icon:'mdi-book-open-variant',       category:'Front Desk',          keywords:['reservations','bookings','history']},
        {title:'Scan / Find Booking',                   url:'{{ route("reservations.scan") }}',                              icon:'mdi-qrcode-scan',             category:'Front Desk',          keywords:['qr','scan','reference','lookup','find']},
        @endcan
        @can('Create reservations')
        {title:'New Reservation',                       url:'{{ route("reservations.create") }}',                            icon:'mdi-calendar-plus',           category:'Front Desk',          keywords:['book','booking','walk-in','reservation','new']},
        @endcan
        @can('View guests')
        {title:'Guests',                                url:'{{ route("guests.index") }}',                                   icon:'mdi-account-star',            category:'Front Desk',          keywords:['guest','customer','profile','vip']},
        @endcan
        @can('View folios')
        {title:'Outstanding Bills',                     url:'{{ route("billing.outstanding") }}',                            icon:'mdi-cash-clock',              category:'Billing',             keywords:['bill','folio','owing','balance','debt','credit','refund','invoice']},
        @endcan
        @canany(['View finance reports', 'Receive payments'])
        {title:'Collections / Cash-up',                 url:'{{ route("billing.payments") }}',                               icon:'mdi-cash-register',           category:'Billing',             keywords:['payments','collections','cash','cash-up','transfer','pos','receipts','revenue']},
        @endcanany
        @canany(['Use POS', 'Receive payments', 'Review cash shifts', 'View finance reports'])
        {title:'Cashier Shifts',                        url:'{{ route("cash-shifts.index") }}',                              icon:'mdi-safe',                    category:'Billing',             keywords:['shift','float','cash count','drawer','till','close','z report','variance','short','over']},
        @endcanany
        @can('Use POS')
        {title:'Open Till (POS)',                       url:'{{ route("pos.terminal") }}',                                   icon:'mdi-cash-register',           category:'Bar & Restaurant',    keywords:['pos','till','bar','restaurant','sell','order','drinks','food']},
        @endcan
        @canany(['View POS reports', 'Use POS'])
        {title:'Room Orders',                           url:'{{ route("pos.room-orders") }}',                                icon:'mdi-room-service',            category:'Bar & Restaurant',    keywords:['room service','orders','guest app','qr','food','drinks','kitchen']},
        {title:'Bar & Restaurant Sales',                url:'{{ route("pos.orders") }}',                                     icon:'mdi-receipt-text',            category:'Bar & Restaurant',    keywords:['pos','sales','orders','bar','restaurant','void','report']},
        @endcanany
        @can('Manage POS menu')
        {title:'Menu & Prices',                         url:'{{ route("pos.menu") }}',                                       icon:'mdi-food',                    category:'Bar & Restaurant',    keywords:['menu','items','prices','drinks','food','outlet','category']},
        @endcan
        @can('View stock')
        {title:'Stock on Hand',                         url:'{{ route("inventory.index") }}',                                icon:'mdi-package-variant',         category:'Inventory',           keywords:['stock','inventory','store','quantity','low stock','reorder']},
        @endcan
        @canany(['Request purchases', 'Approve purchases', 'Manage purchase orders'])
        {title:'Purchase Requests',                     url:'{{ route("inventory.requests.index") }}',                       icon:'mdi-cart-plus',               category:'Inventory',           keywords:['request','requisition','approve','department','buy']},
        @endcanany
        @canany(['Manage supplier invoices', 'Pay suppliers'])
        {title:'Supplier Bills',                        url:'{{ route("inventory.invoices.index") }}',                       icon:'mdi-file-document-outline',   category:'Inventory',           keywords:['invoice','bill','supplier','owe','payable','pay','creditor']},
        @endcanany
        @can('View stock')
        {title:'Purchase Orders',                       url:'{{ route("inventory.po.index") }}',                             icon:'mdi-cart-arrow-down',         category:'Inventory',           keywords:['purchase','order','supplier','buy','po']},
        {title:'Stocktakes',                            url:'{{ route("inventory.counts.index") }}',                         icon:'mdi-clipboard-check-outline', category:'Inventory',           keywords:['count','stocktake','variance','audit']},
        {title:'Suppliers',                             url:'{{ route("inventory.suppliers.index") }}',                      icon:'mdi-truck-delivery',          category:'Inventory',           keywords:['supplier','vendor']},
        @endcan
        @can('Receive stock')
        {title:'Receive Stock',                         url:'{{ route("inventory.receipts.create") }}',                      icon:'mdi-truck-check',             category:'Inventory',           keywords:['receive','delivery','grn','goods received']},
        @endcan
        @can('Transfer stock')
        {title:'Transfer Stock',                        url:'{{ route("inventory.transfers.create") }}',                     icon:'mdi-swap-horizontal',         category:'Inventory',           keywords:['transfer','move','issue','bar','kitchen']},
        @endcan
        @can('View stock reports')
        {title:'Stock Reports',                         url:'{{ route("inventory.reports") }}',                              icon:'mdi-chart-bar',               category:'Inventory',           keywords:['valuation','margin','waste','cost of sales','report']},
        @endcan
        @canany(['Manage stock items', 'Manage POS menu'])
        {title:'Recipes',                               url:'{{ route("inventory.recipes.index") }}',                        icon:'mdi-chef-hat',                category:'Inventory',           keywords:['recipe','ingredients','cost','menu']},
        @endcanany
        @can('View attendance')
        {title:"Who's In Today",                        url:'{{ route("attendance.board") }}',                               icon:'mdi-account-clock',           category:'Attendance',          keywords:['attendance','clock in','late','absent','staff','present']},
        {title:'Timesheet',                             url:'{{ route("attendance.timesheet") }}',                           icon:'mdi-calendar-clock',          category:'Attendance',          keywords:['timesheet','hours','overtime','payroll','attendance']},
        {title:'Staff Leave',                           url:'{{ route("attendance.leave") }}',                               icon:'mdi-bag-suitcase',            category:'Attendance',          keywords:['leave','sick','annual','holiday','off']},
        @endcan
        @can('Manage staff & shifts')
        {title:'Staff, Shifts & Badges',                url:'{{ route("attendance.setup") }}',                               icon:'mdi-badge-account',           category:'Attendance',          keywords:['shift','rota','badge','id card','department','kiosk']},
        @endcan
        @can('Run attendance kiosk')
        {title:'Clock-in Kiosk',                        url:'{{ route("attendance.kiosk") }}',                               icon:'mdi-qrcode-scan',             category:'Attendance',          keywords:['kiosk','clock in','scan','badge','qr']},
        @endcan
        {title:'My Attendance',                         url:'{{ route("attendance.my") }}',                                  icon:'mdi-account-check',           category:'My Account',          keywords:['my attendance','clock in','late','badge']},
        @canany(['Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses'])
        {title:'Expenses',                              url:'{{ route("expenses.index") }}',                             icon:'mdi-wallet-outline',          category:'Finance',             keywords:['expense','cost','diesel','spend','petty cash','budget','running cost']},
        @endcanany
        @can('Record expenses')
        {title:'Record an Expense',                     url:'{{ route("expenses.index") }}#new',                         icon:'mdi-cash-minus',              category:'Finance',             keywords:['expense','receipt','diesel','repair','pay','petty cash']},
        @endcan
        @can('Manage expense categories')
        {title:'Expense Categories & Budgets',          url:'{{ route("expenses.categories.index") }}',                  icon:'mdi-folder-cog-outline',      category:'Finance',             keywords:['budget','expense category']},
        @endcan
        @can('Handle guest requests')
        @can('Manage transport'){title:'Car Service',                         url:'{{ route("transport.index") }}',                            icon:'mdi-car',                     category:'Front Desk',          keywords:['car','taxi','driver','pickup','airport','transfer','ride','fleet','uber']},@endcan
        @can('Manage rates'){title:'Revenue & Pricing',                     url:'{{ route("rates.revenue") }}',                              icon:'mdi-chart-line',              category:'Front Desk',          keywords:['revenue','pricing','suggested','occupancy','pickup','yield','dynamic pricing']},@endcan
        @can('Manage rates'){title:'Rates & Seasons',                       url:'{{ route("rates.index") }}',                                icon:'mdi-tag-multiple-outline',    category:'Front Desk',          keywords:['rates','prices','season','promo','discount','rate plan','calendar','peak']},@endcan
        @can('View company accounts'){title:'Company Accounts',             url:'{{ route("companies.index") }}',                            icon:'mdi-domain',                  category:'Front Desk',          keywords:['company','corporate','city ledger','account','statement','invoice','credit']},@endcan
        @canany(['View energy saver', 'Manage energy saver']){title:'Energy Saver',   url:'{{ route("energy.index") }}',                               icon:'ri-flashlight-line',          category:'Control Room',        keywords:['energy','power','generator','diesel','fuel','light','switch off','electricity','nepa','grid']},@endcanany
        @canany(['View events', 'Manage events']){title:'Events & Halls',   url:'{{ route("events.index") }}',                               icon:'mdi-party-popper',            category:'Front Desk',          keywords:['event','hall','conference','wedding','banquet','meeting']},@endcanany
        @canany(['View events', 'Manage events']){title:'Hall Usage',       url:'{{ route("events.usage") }}',                               icon:'mdi-chart-bar',               category:'Front Desk',          keywords:['hall','usage','utilisation','occupancy','overtime','damage','attendance']},@endcanany
        @canany(['Sell event tickets', 'View events']){title:'Event Tickets', url:'{{ route("events.tickets.index") }}',                     icon:'mdi-ticket-confirmation-outline', category:'Front Desk',      keywords:['ticket','tickets','sell','concert','show','gala','order']},@endcanany
        @canany(['Check event tickets', 'Sell event tickets']){title:'Check Tickets', url:'{{ route("events.tickets.door") }}',               icon:'mdi-qrcode-scan',             category:'Front Desk',          keywords:['scan','ticket','door','entry','gate','qr','admit']},@endcanany
        @canany(['View room calendars', 'Manage room calendars']){title:'Room Calendars', url:'{{ route("channels.index") }}',           icon:'mdi-calendar-sync',           category:'Front Desk',          keywords:['room calendars','airbnb','vrbo','ical','calendar sync']},@endcanany
        @canany(['Run night audit', 'View night audit reports']){title:'Night Audit', url:'{{ route("night-audit.index") }}',                 icon:'mdi-weather-night',           category:'Front Desk',          keywords:['night audit','close day','end of day','daily report','business date']},@endcanany
        {title:'Guest Chat',                            url:'{{ route("chat.index") }}',                                 icon:'mdi-chat-processing-outline', category:'Front Desk',          keywords:['chat','call','guest','reception','restaurant','bar','laundry','message']},
        {title:'Guest Chat Sections',                   url:'{{ route("chat.sections") }}',                              icon:'mdi-forum-outline',           category:'Settings',            keywords:['chat','sections','departments','calls','hours']},
        {title:'Guest Messages',                        url:'{{ route("messages.index") }}',                             icon:'mdi-message-text-outline',    category:'Front Desk',          keywords:['sms','whatsapp','text','message','reply','guest']},
        {title:'Guest Requests',                        url:'{{ route("guest-requests.index") }}',                       icon:'mdi-bell-ring-outline',       category:'Front Desk',          keywords:['guest','request','towels','room service','complaint']},
        @endcan
        {title:'Public Booking Page',                   url:'{{ route("book.index") }}',                                 icon:'mdi-web',                     category:'Front Desk',          keywords:['book online','website','booking engine','public']},
        {title:'Staff App (phone)',                     url:'{{ route("staff.home") }}',                                 icon:'mdi-cellphone',               category:'Staff',               keywords:['mobile','phone','app','my work','tasks','approvals','install']},
        {title:'Raise an IT Ticket',                    url:'{{ route("it.tickets.index") }}#new',                       icon:'mdi-lifebuoy',                category:'IT',                  keywords:['it','support','help','computer','wifi','printer','internet','ticket','broken']},
        {title:'My IT Requests',                        url:'{{ route("it.tickets.index") }}',                           icon:'mdi-ticket-outline',          category:'IT',                  keywords:['it','ticket','service desk','support']},
        @can('Use call console')
        {title:'Call Console',                          url:'{{ route("it.console.index") }}',                           icon:'mdi-headset',                 category:'IT',                  keywords:['call','console','listen','monitor calls','transfer','headset','voice message','cockpit','security','switchboard']},
        {title:'Room & Area Equipment',                 url:'{{ route("it.facilities.index") }}',                        icon:'mdi-power-plug-outline',      category:'IT',                  keywords:['equipment','ac','air conditioner','lights','generator','on','off','devices','iot','bms','room status']},
        @endcan
        @canany(['View IT control room', 'Manage IT monitoring', 'Manage IT tickets'])
        @canany(['View network', 'Manage network', 'Give Wi-Fi codes']){title:'Internet & Wi-Fi',                    url:'{{ route("it.network.index") }}',                           icon:'mdi-satellite-uplink',        category:'IT',                  keywords:['starlink','internet','wifi','wi-fi','code','voucher','bandwidth','speed','data','network']},@endcanany
        {title:'IT Control Room',                       url:'{{ route("it.index") }}',                                   icon:'mdi-monitor-dashboard',       category:'IT',                  keywords:['network','uptime','monitoring','server','router','wifi','backup','noc','control room']},
        {title:'IT Alerts',                             url:'{{ route("it.alerts.index") }}',                            icon:'mdi-alert-octagon-outline',   category:'IT',                  keywords:['alert','outage','down','incident']},
        {title:'IT Service Report',                     url:'{{ route("it.report") }}',                                  icon:'mdi-file-chart-outline',      category:'IT',                  keywords:['sla','uptime','monthly','service report']},
        @endcanany
        @canany(['View IT control room', 'Manage IT monitoring'])
        {title:'IT Monitors',                           url:'{{ route("it.monitors.index") }}',                          icon:'mdi-heart-pulse',             category:'IT',                  keywords:['monitor','ping','check','ssl','backup','disk']},
        {title:'IT Equipment',                          url:'{{ route("it.assets.index") }}',                            icon:'mdi-router-wireless',         category:'IT',                  keywords:['asset','router','switch','access point','device','inventory','warranty']},
        {title:'Planned IT Maintenance',                url:'{{ route("it.maintenance.index") }}',                       icon:'mdi-wrench-clock',            category:'IT',                  keywords:['maintenance','downtime','window']},
        @endcanany
        @canany(['Manage website', 'View website enquiries'])
        {title:'Website',                               url:'{{ route("website.index") }}',                                  icon:'mdi-web',                     category:'Website',             keywords:['website','site','public','cms','home page','landing']},
        {title:'Website Enquiries',                     url:'{{ route("website.enquiries.index") }}',                        icon:'mdi-email-outline',           category:'Website',             keywords:['enquiry','contact','message','table','event enquiry','website form']},
        @endcanany
        @can('Manage website')
        {title:'Website Settings & Texts',              url:'{{ route("website.settings") }}',                               icon:'mdi-cog-outline',             category:'Website',             keywords:['website','colours','seo','social','instagram','whatsapp','about','announcement']},
        {title:'Website Slides',                        url:'{{ route("website.content.index", "slide") }}',                 icon:'mdi-image-multiple-outline',  category:'Website',             keywords:['hero','banner','slider','home page']},
        {title:'Website Offers',                        url:'{{ route("website.content.index", "offer") }}',                 icon:'mdi-tag-outline',             category:'Website',             keywords:['offer','deal','package','promo']},
        {title:'Website Gallery',                       url:'{{ route("website.content.index", "gallery") }}',               icon:'mdi-image-outline',           category:'Website',             keywords:['photos','pictures','gallery']},
        {title:'Website Journal',                       url:'{{ route("website.content.index", "post") }}',                  icon:'mdi-newspaper-variant-outline', category:'Website',           keywords:['news','blog','post','article']},
        @endcan
        @can('Manage hotel settings')
        {title:'Hotel Settings',                        url:'{{ route("settings.hotel.index") }}',                           icon:'mdi-cog-outline',             category:'Settings',            keywords:['settings','hotel name','logo','tax','vat','approval limit','tolerance','price jump','branding','currency']},
        {title:'Bank Accounts',                         url:'{{ route("settings.bank-accounts.index") }}',                   icon:'mdi-bank',                    category:'Billing',             keywords:['bank','account','transfer','invoice','settings']},
        @endcan
        {title:'Tasks',                                 url:'{{ route("tasks.index") }}',                                    icon:'mdi-clipboard-check-outline', category:'Operations',          keywords:['task','todo','to do','job','assign','department','overdue','checklist','duty']},
        @canany(['View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping'])
        {title:'Housekeeping Tablet Board',             url:'{{ route("housekeeping.board") }}',                             icon:'mdi-tablet',                  category:'Rooms',               keywords:['housekeeping','tablet','board','full screen','cleaners']},
        {title:'Housekeeping Board',                    url:'{{ route("housekeeping.index") }}',                             icon:'mdi-broom',                   category:'Rooms',               keywords:['housekeeping','clean','dirty','inspection','turnaround','stay-over','maid']},
        @endcanany
        @canany(['View maintenance', 'Report maintenance issues', 'Manage maintenance'])
        {title:'Maintenance Jobs',                      url:'{{ route("maintenance.index") }}',                              icon:'mdi-wrench',                  category:'Rooms',               keywords:['maintenance','repair','fault','work order','ac','plumbing','broken','technician']},
        {title:'Servicing Plans',                       url:'{{ route("maintenance.plans.index") }}',                        icon:'mdi-calendar-sync',           category:'Rooms',               keywords:['preventive','maintenance','servicing','schedule','generator','ac service','recurring']},
        {title:'Guest Feedback',                        url:'{{ route("feedback.index") }}',                                 icon:'mdi-star-face',               category:'Front Desk',          keywords:['feedback','survey','rating','review','stars','nps','complaint','satisfaction']},
        @endcanany
        @can('View visitors')
        {title:'Visitors',                              url:'{{ route("visitors.index") }}',                                 icon:'mdi-badge-account',           category:'Security',            keywords:['visitor','sign in','gate','pass','guest visitor','contractor','delivery','badge']},
        {title:'Check a Visitor Pass',                  url:'{{ route("visitors.check") }}',                                 icon:'mdi-qrcode-scan',             category:'Security',            keywords:['scan','pass','visitor','gate','qr','verify']},
        @endcan
        @canany(['View incidents', 'Report incidents', 'Manage incidents'])
        {title:'Incidents',                             url:'{{ route("incidents.index") }}',                                icon:'mdi-alert-octagon',           category:'Security',            keywords:['incident','report','theft','fight','injury','complaint','fire','security','emergency','contacts']},
        @endcanany
        @can('Manage lost & found')
        {title:'Lost & Found',                          url:'{{ route("lost-found.index") }}',                               icon:'mdi-briefcase-search',        category:'Security',            keywords:['lost','found','left behind','item','claim','property']},
        @endcan
        @can('View audit log')
        {title:'Audit Trail',                           url:'{{ route("audit.index") }}',                                    icon:'mdi-shield-check',            category:'Audit',               keywords:['audit','log','history','who','changes','void','refund','security','trail']},
        @endcan
        @can('Create rooms')
        {title:'Add Rooms in Bulk',                     url:'{{ route("rooms.bulk.create") }}',                              icon:'mdi-plus-box-multiple',       category:'Rooms',               keywords:['add','create','new','rooms','bulk']},
        @endcan
    ];

    /* ── category colours ── */
    var CAT_COLORS = {
        'Dashboards':'#4f8ef7','Users & Privileges':'#405189','My Account':'#2a9d8f','Rooms':'#0d9488','Front Desk':'#7c3aed','Billing':'#16a34a','Bar & Restaurant':'#ea580c','Inventory':'#ca8a04','Attendance':'#0284c7','Audit':'#dc2626'
    };

    /* ── popular / suggestion chips ── */
    var POPULAR = [
        {label:'Arrivals',    q:'arrivals'},
        {label:'New booking', q:'reservation'},
        {label:'Rooms',       q:'rooms'},
        {label:'Users',       q:'users'},
        {label:'Roles',       q:'roles'},
        {label:'Permissions', q:'permissions'},
        {label:'Profile',     q:'profile'},
        {label:'Password',    q:'password'},
    ];

    /* ── history helpers ── */
    var HISTORY_KEY = 'spotlight_search_history';
    function getHistory(){ try{ return JSON.parse(localStorage.getItem(HISTORY_KEY)||'[]'); }catch(e){ return []; } }
    function saveHistory(h){ localStorage.setItem(HISTORY_KEY, JSON.stringify(h.slice(0,10))); }
    function addHistory(query, result){
        if (!query || query.trim().length < 2) return;
        var h = getHistory();
        var item = {query:query.trim(), url:result.url, title:result.title, icon:result.icon, category:result.category, ts:Date.now()};
        var idx = h.findIndex(function(x){ return x.url===result.url; });
        if (idx !== -1) h.splice(idx,1);
        h.unshift(item);
        saveHistory(h);
    }

    /* ── fuzzy / scored search ── */
    function scoreMatch(page, q) {
        var lq   = q.toLowerCase().trim();
        var lt   = page.title.toLowerCase();
        var lc   = page.category.toLowerCase();
        var lk   = (page.keywords||[]).join(' ').toLowerCase();
        var score = 0;
        if (lt === lq)                   score += 100;
        if (lt.startsWith(lq))           score += 60;
        if (lt.includes(lq))             score += 40;
        if (lc.includes(lq))             score += 20;
        if (lk.includes(lq))             score += 30;
        /* word-level partial: each word of query must match something */
        var words = lq.split(/\s+/).filter(Boolean);
        if (words.length > 1) {
            var allMatch = words.every(function(w){ return lt.includes(w)||lc.includes(w)||lk.includes(w); });
            if (allMatch) score += 35;
        }
        /* fuzzy: check if query chars appear in order in title */
        if (score === 0 && lq.length >= 2) {
            var ti = 0;
            for (var ci = 0; ci < lq.length; ci++) {
                var found = lt.indexOf(lq[ci], ti);
                if (found === -1) { ti = -1; break; }
                ti = found + 1;
            }
            if (ti !== -1) score += 10;
        }
        return score;
    }

    function searchStatic(q) {
        var scored = [];
        STATIC_PAGES.forEach(function(p){
            var s = scoreMatch(p, q);
            if (s > 0) scored.push({page:p, score:s});
        });
        scored.sort(function(a,b){ return b.score - a.score; });
        return scored.map(function(x){ return x.page; }).slice(0,18);
    }

    /* ── suggestion chips based on partial input ── */
    function getSuggestions(q) {
        if (!q || q.length < 1) return POPULAR;
        var lq = q.toLowerCase();
        var matchedCats = {};
        STATIC_PAGES.forEach(function(p){
            var lt = p.title.toLowerCase(), lk = (p.keywords||[]).join(' ').toLowerCase();
            if (lt.includes(lq) || lk.includes(lq) || p.category.toLowerCase().includes(lq)) {
                matchedCats[p.category] = (matchedCats[p.category]||0) + 1;
            }
        });
        var cats = Object.keys(matchedCats).sort(function(a,b){ return matchedCats[b]-matchedCats[a]; }).slice(0,6);
        if (cats.length === 0) return POPULAR.slice(0,5);
        return cats.map(function(c){ return {label:c, q:c}; });
    }

    /* ── DOM refs ── */
    var overlay   = document.getElementById('spotlight-overlay');
    var box       = document.getElementById('spotlight-box');
    var input     = document.getElementById('spotlight-input');
    var emptyEl   = document.getElementById('spotlight-empty');
    var loadEl    = document.getElementById('spotlight-loading');
    var list      = document.getElementById('spotlight-list');
    var trigger   = document.getElementById('spotlight-trigger');
    var escBtn    = document.getElementById('spotlight-esc');
    var histSec   = document.getElementById('spotlight-history-section');
    var histList  = document.getElementById('spotlight-history-list');
    var clearBtn  = document.getElementById('spotlight-clear-history-btn');
    var clearMain = document.getElementById('spotlight-clear-history');
    var suggestEl = document.getElementById('spotlight-suggestions');
    var chipWrap  = document.getElementById('spotlight-suggestion-chips');

    var ajaxTimer = null, activeIndex = -1, currentResults = [];

    /* ── open / close ── */
    function open(){
        if(!overlay) return;
        overlay.style.display='flex';
        overlay.style.animation='spotlightOverlayFadeIn 0.25s ease forwards';
        if(box) box.style.animation='spotlightModalBounceIn 0.35s cubic-bezier(0.34,1.3,0.64,1) forwards';
        setTimeout(function(){ if(input) input.focus(); },100);
        renderHistory();
        renderChips(getSuggestions(''));
        if(clearMain) clearMain.style.display = getHistory().length>0?'block':'none';
    }
    function close(){
        if(box) box.style.animation='spotlightModalFadeOut 0.2s ease forwards';
        if(overlay) overlay.style.animation='spotlightOverlayFadeOut 0.2s ease forwards';
        setTimeout(function(){
            if(overlay) overlay.style.display='none';
            if(input) input.value='';
            showEmpty(true);
        },200);
    }

    function showEmpty(withChips){
        if(emptyEl){ emptyEl.style.display='block'; }
        if(loadEl) loadEl.style.display='none';
        if(list){ list.style.display='none'; list.innerHTML=''; }
        if(clearMain) clearMain.style.display = getHistory().length>0?'block':'none';
        renderHistory();
        if(withChips) renderChips(getSuggestions(''));
        currentResults=[]; activeIndex=-1;
    }

    function showLoading(){
        if(emptyEl) emptyEl.style.display='none';
        if(loadEl) loadEl.style.display='block';
        if(list) list.style.display='none';
        if(histSec) histSec.style.display='none';
        if(suggestEl) suggestEl.style.display='none';
    }

    /* ── suggestion chips ── */
    function renderChips(suggestions) {
        if (!chipWrap || !suggestEl) return;
        chipWrap.innerHTML = '';
        if (!suggestions || !suggestions.length) { suggestEl.style.display='none'; return; }
        suggestEl.style.display = 'block';
        suggestions.forEach(function(s, i){
            var btn = document.createElement('button');
            btn.className = 'spotlight-suggest-chip';
            btn.style.cssText = 'background:rgba(79,142,247,.12);border:1px solid rgba(79,142,247,.3);border-radius:20px;padding:5px 14px;color:rgba(255,255,255,.8);font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;white-space:nowrap;animation-delay:'+(i*0.04)+'s;opacity:0;';
            btn.textContent = s.label;
            btn.addEventListener('mouseenter', function(){ btn.style.background='rgba(79,142,247,.25)'; btn.style.borderColor='rgba(79,142,247,.6)'; });
            btn.addEventListener('mouseleave', function(){ btn.style.background='rgba(79,142,247,.12)'; btn.style.borderColor='rgba(79,142,247,.3)'; });
            btn.addEventListener('click', function(){
                if (input) { input.value = s.q; input.focus(); }
                performSearch(s.q);
            });
            chipWrap.appendChild(btn);
        });
    }

    /* ── history ── */
    function renderHistory(){
        var h = getHistory();
        if (h.length > 0 && (!input || !input.value.trim())) {
            if(histSec) histSec.style.display='block';
            if(histList) histList.innerHTML='';
            if(clearMain) clearMain.style.display='block';
            h.forEach(function(item, idx){
                var div = document.createElement('div');
                div.className = 'spotlight-history-item';
                div.style.cssText = 'display:flex;align-items:center;gap:14px;padding:10px 24px;cursor:pointer;transition:background .15s;border-radius:10px;margin:0 16px;';
                var c = CAT_COLORS[item.category]||'#4f8ef7';
                div.innerHTML = '<span style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:'+c+'22;"><i class="'+(item.icon||'mdi-history')+' mdi" style="font-size:16px;color:'+c+';"></i></span>'
                    + '<span style="flex:1;min-width:0;"><span style="display:block;font-size:14px;font-weight:500;color:rgba(255,255,255,.9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+escHtml(item.title)+'</span>'
                    + '<span style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-top:2px;">'+escHtml(item.category)+'</span></span>'
                    + '<button class="hist-remove" style="background:transparent;border:none;color:rgba(255,255,255,.35);cursor:pointer;font-size:13px;padding:6px 10px;border-radius:6px;">✕</button>';
                div.querySelector('.hist-remove').addEventListener('click', function(e){
                    e.stopPropagation();
                    var his = getHistory(); his.splice(idx,1); saveHistory(his); renderHistory();
                    if (!input || !input.value.trim()) showEmpty(true);
                });
                div.addEventListener('mouseenter', function(){ div.style.background='rgba(255,255,255,.04)'; });
                div.addEventListener('mouseleave', function(){ div.style.background=''; });
                div.addEventListener('click', function(){ if(input){ input.value=item.query; performSearch(item.query); } });
                if(histList) histList.appendChild(div);
            });
        } else {
            if(histSec) histSec.style.display='none';
            if(clearMain) clearMain.style.display='none';
        }
    }

    /* ── HTML escaping ── */
    function escHtml(str){
        if (!str) return '';
        return str.replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }

    /* ── highlight matched chars in title ── */
    function highlightText(title, q){
        if (!q) return escHtml(title);
        var lq = q.toLowerCase(), lt = title.toLowerCase();
        var idx = lt.indexOf(lq);
        if (idx === -1) return escHtml(title);
        return escHtml(title.substring(0,idx))
            + '<mark style="background:rgba(79,142,247,.35);color:#a5c8ff;border-radius:3px;padding:0 2px;">' + escHtml(title.substring(idx,idx+lq.length)) + '</mark>'
            + escHtml(title.substring(idx+lq.length));
    }

    /* ── perform search ── */
    function performSearch(query){
        if (!query || !query.trim()) { showEmpty(true); return; }
        var sr = searchStatic(query);
        renderResults(sr, query);
        if (sr.length > 0) renderChips(getSuggestions(query));
    }

    /* ── render results ── */
    function renderResults(results, query){
        if(loadEl) loadEl.style.display='none';
        if(emptyEl) emptyEl.style.display='none';
        if(list){ list.innerHTML=''; list.style.display='block'; }
        if(histSec) histSec.style.display='none';
        activeIndex = -1; currentResults = results;

        if (!results.length) {
            if(emptyEl){
                emptyEl.innerHTML = '<i class="mdi mdi-magnify-close" style="font-size:42px;display:block;margin-bottom:16px;opacity:.4;"></i>'
                    + '<span style="font-size:15px;">No results for "'+escHtml(query)+'"</span>'
                    + '<div style="margin-top:12px;font-size:12px;opacity:.4;">Try a different term or browse from the sidebar</div>';
                emptyEl.style.display='block';
            }
            if(list) list.style.display='none';
            renderChips(POPULAR);
            return;
        }

        /* Group by category */
        var groups = {}, order = [];
        results.forEach(function(r){
            if (!groups[r.category]) { groups[r.category]=[]; order.push(r.category); }
            groups[r.category].push(r);
        });

        var globalIdx = 0;
        order.forEach(function(cat){
            /* category header */
            var hdr = document.createElement('li');
            hdr.style.cssText = 'padding:12px 24px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);';
            hdr.textContent = cat;
            list.appendChild(hdr);

            groups[cat].forEach(function(r){
                var li = document.createElement('li');
                var isTop = (globalIdx === 0);
                li.className = 'spotlight-result-item' + (isTop ? ' top-match' : '');
                li.setAttribute('data-idx', globalIdx);
                li.style.cssText = 'display:flex;align-items:center;gap:14px;padding:12px 24px;cursor:pointer;transition:all .2s;border-radius:10px;margin:4px 12px;';
                var c = CAT_COLORS[r.category] || '#4f8ef7';
                li.innerHTML = '<span style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:'+c+'22;">'
                    + '<i class="'+(r.icon||'mdi-chevron-right')+' mdi" style="font-size:18px;color:'+c+';"></i></span>'
                    + '<span style="flex:1;min-width:0;">'
                    + '<span class="result-title" style="display:block;font-size:15px;font-weight:500;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
                    + highlightText(r.title, query)
                    + '</span>'
                    + '<span style="display:block;font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;">'+escHtml(r.category)+'</span></span>'
                    + '<i class="mdi mdi-arrow-right" style="font-size:16px;color:rgba(255,255,255,.25);flex-shrink:0;transition:transform .2s;"></i>';

                var idx = globalIdx;
                li.addEventListener('mouseenter', function(){ li.style.background='rgba(79,142,247,.12)'; activeIndex=idx; });
                li.addEventListener('mouseleave', function(){ li.style.background=(activeIndex===idx)?'rgba(79,142,247,.18)':''; });
                li.addEventListener('click', function(){ addHistory(input?input.value:'', r); saveHistory(getHistory()); window.location.href=r.url; });
                list.appendChild(li);
                globalIdx++;
            });
        });
    }

    /* ── keyboard navigation ── */
    function highlightItem(items){
        items.forEach(function(li, i){
            var active = (i === activeIndex);
            li.style.background = active ? 'rgba(79,142,247,.18)' : '';
            var t   = li.querySelector('.result-title');
            if (t)   t.style.color = active ? '#4f8ef7' : '#fff';
            var arr = li.querySelector('.mdi-arrow-right');
            if (arr) arr.style.transform = active ? 'translateX(6px)' : 'translateX(0)';
            if (active) li.scrollIntoView({block:'nearest'});
        });
    }

    /* ── event listeners ── */
    if (trigger)   trigger.addEventListener('click', open);
    if (escBtn)    escBtn.addEventListener('click', close);
    if (clearBtn)  clearBtn.addEventListener('click', function(){ localStorage.removeItem(HISTORY_KEY); renderHistory(); showEmpty(true); });
    if (clearMain) clearMain.addEventListener('click', function(){ localStorage.removeItem(HISTORY_KEY); renderHistory(); showEmpty(true); });
    if (overlay)   overlay.addEventListener('click', function(e){ if (e.target===overlay) close(); });

    document.addEventListener('keydown', function(e){
        if ((e.metaKey||e.ctrlKey) && e.key==='k'){ e.preventDefault(); overlay&&overlay.style.display==='flex' ? close() : open(); }
        if (e.key==='Escape' && overlay && overlay.style.display==='flex') close();
    });

    if (input) {
        input.addEventListener('keydown', function(e){
            var items = list ? Array.prototype.slice.call(list.querySelectorAll('li[data-idx]')) : [];
            if (e.key==='ArrowDown') { e.preventDefault(); activeIndex=Math.min(activeIndex+1,items.length-1); highlightItem(items); }
            else if (e.key==='ArrowUp') { e.preventDefault(); activeIndex=Math.max(activeIndex-1,0); highlightItem(items); }
            else if (e.key==='Enter' && activeIndex>=0 && currentResults[activeIndex]) {
                addHistory(input.value, currentResults[activeIndex]); saveHistory(getHistory());
                window.location.href = currentResults[activeIndex].url;
            }
        });
        input.addEventListener('input', function(){
            var q = this.value.trim();
            if (!q) {
                showEmpty(true);
                renderHistory();
                if(clearMain) clearMain.style.display = getHistory().length>0 ? 'block' : 'none';
                return;
            }
            if(clearMain) clearMain.style.display = 'none';
            renderChips(getSuggestions(q));
            performSearch(q);
        });
    }

    renderHistory();
})();
</script>

<!-- =====================================================
     ROUTE-SPECIFIC JS INCLUDES
     ===================================================== -->
@if (Route::is('users.*'))                 @include('layouts.pages-assets.js.users-list-js') @endif
@if (Route::is('profile.*'))               @include('layouts.pages-assets.js.users-list-js') @endif
@if (Route::is('roles.*'))                 @include('layouts.pages-assets.js.role-list-js') @endif
@if (Route::is('permissions.*'))           @include('layouts.pages-assets.js.permissions-list-js') @endif
{{-- New modules: add a line per module's page JS here. --}}


<script>
/* Expired session on an AJAX / fetch call (HTTP 419) → go to the login page
   instead of leaving the page half-working. Full-page posts are redirected
   by the server already. */
(function () {
    var loginUrl = @json(route('login'));
    var sent = false;
    function toLogin() {
        if (sent) return; sent = true;
        window.location.href = loginUrl + (loginUrl.indexOf('?') === -1 ? '?' : '&') + 'expired=1';
    }
    if (window.jQuery) {
        jQuery(document).ajaxError(function (_e, xhr) { if (xhr && xhr.status === 419) toLogin(); });
    }
    if (window.fetch) {
        var _fetch = window.fetch;
        window.fetch = function () {
            return _fetch.apply(this, arguments).then(function (res) {
                if (res && res.status === 419) toLogin();
                return res;
            });
        };
    }
    if (window.axios && window.axios.interceptors) {
        window.axios.interceptors.response.use(null, function (err) {
            if (err && err.response && err.response.status === 419) toLogin();
            return Promise.reject(err);
        });
    }
})();
</script>

{{-- Weak or dropped network: say so, and don't let a form be sent into the void or twice. --}}
<div id="netBanner" role="status" aria-live="polite" hidden
     style="position:fixed;left:50%;top:10px;transform:translateX(-50%);z-index:2000;max-width:min(640px,94vw);background:#b91c1c;color:#fff;border-radius:12px;padding:10px 16px;font-weight:600;font-size:13.5px;box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;gap:10px;align-items:center">
    <i class="ri-wifi-off-line" style="font-size:18px"></i>
    <span id="netBannerText">You're offline. Nothing can be saved until the connection is back — what you've typed stays on the page.</span>
</div>
<script>
(function () {
    var pingUrl = @json(route('ping'));
    var banner = document.getElementById('netBanner'), text = document.getElementById('netBannerText');
    var online = navigator.onLine !== false, timer = null;

    function show(msg) { if (msg) text.textContent = msg; banner.hidden = false; banner.style.display = 'flex'; }
    function hide() { banner.hidden = true; banner.style.display = 'none'; }
    function set(on) {
        var was = online; online = on;
        if (!on) show("You're offline. Nothing can be saved until the connection is back — what you've typed stays on the page.");
        else { hide(); if (!was && window.Swal) Swal.fire({ toast: true, position: 'top', icon: 'success', title: 'Back online', timer: 1800, showConfirmButton: false }); }
    }
    function check() {
        clearTimeout(timer);
        var ctl = window.AbortController ? new AbortController() : null;
        var t = ctl ? setTimeout(function () { ctl.abort(); }, 5000) : null;
        return fetch(pingUrl, { cache: 'no-store', credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
            .then(function (r) { set(r.ok || r.status < 500); })
            .catch(function () { set(false); })
            .finally(function () { if (t) clearTimeout(t); timer = setTimeout(check, online ? 60000 : 5000); });
    }
    window.addEventListener('offline', function () { set(false); check(); });
    window.addEventListener('online', check);
    check();

    // Forms that change data: block while offline, and stop double sends on a slow line.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!(f instanceof HTMLFormElement) || f.hasAttribute('data-no-guard')) return;
        var method = (f.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get') return;
        if (!online || navigator.onLine === false) {
            e.preventDefault();
            show("You're offline — this wasn't sent. Nothing is lost: press the button again once the connection is back.");
            check();
            return;
        }
        if (f.dataset.sending === '1') { e.preventDefault(); return; }
        if (e.defaultPrevented) return;
        f.dataset.sending = '1';
        // A disabled button isn't sent with the form, so keep the clicked button's name/value.
        var sub = e.submitter;
        if (sub && sub.name) {
            var keep = document.createElement('input');
            keep.type = 'hidden'; keep.name = sub.name; keep.value = sub.value; keep.setAttribute('data-submitter', '1');
            f.appendChild(keep);
        }
        var btns = f.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]');
        btns.forEach(function (b) { b.dataset.label = b.innerHTML; b.disabled = true; if (b.tagName === 'BUTTON') b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + b.innerHTML; });
        // If the page is still here after a while, the request probably failed — let them try again.
        setTimeout(function () {
            f.dataset.sending = '';
            f.querySelectorAll('input[data-submitter]').forEach(function (x) { x.remove(); });
            btns.forEach(function (b) { b.disabled = false; if (b.dataset.label) b.innerHTML = b.dataset.label; });
            check().then(function () { if (online) show('This is taking longer than usual — the connection may be weak. If nothing happens, reload and check whether it saved before trying again (payments are never recorded twice).'); });
        }, 20000);
    }, false);
    // Coming back with the browser's Back button: re-enable everything.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-sending="1"]').forEach(function (f) {
            f.dataset.sending = '';
            f.querySelectorAll('input[data-submitter]').forEach(function (x) { x.remove(); });
            f.querySelectorAll('[disabled][data-label]').forEach(function (b) { b.disabled = false; b.innerHTML = b.dataset.label; });
        });
    });
})();
</script>
@if (auth()->check() && auth()->user()->canAny(['Chat with guests', 'Manage guest chat']) && ! request()->routeIs('chat.index', 'it.console.index'))
    {{-- Guest calls ring on every page: a small banner that opens Guest Chat to answer. --}}
    <div id="hc-ring" role="alertdialog" aria-live="assertive" style="display:none;position:fixed;right:20px;bottom:20px;z-index:2000;background:#0f172a;color:#fff;border-radius:18px;padding:14px 16px;box-shadow:0 18px 45px rgba(0,0,0,.35);max-width:320px">
        <div style="font-size:12px;opacity:.7" id="hc-ring-s"></div><div style="font-weight:700" id="hc-ring-t"></div>
        <div style="display:flex;gap:8px;margin-top:10px"><button type="button" id="hc-ring-x" style="flex:1;border:0;border-radius:10px;padding:8px;background:#334155;color:#fff;font-weight:700">Later</button>
            <a id="hc-ring-a" style="flex:1;border-radius:10px;padding:8px;background:#16a34a;color:#fff;font-weight:700;text-align:center;text-decoration:none">Answer</a></div>
    </div>
    <script src="{{ asset('js/hotel-live.js') }}?v={{ @filemtime(public_path('js/hotel-live.js')) }}"></script>
    <script>
    (() => {
        const url = @json(route('chat.api.ringing')), chat = @json(route('chat.index'));
        let seen = {}, stop = null;
        const box = document.getElementById('hc-ring');
        async function check() {
            try {
                const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const list = (await r.json()).data || [];
                const c = list.find(x => !seen[x.uuid]);
                if (c) {
                    document.getElementById('hc-ring-s').textContent = 'Incoming call · ' + c.section;
                    document.getElementById('hc-ring-t').textContent = c.guest + (c.room ? ' · room ' + c.room : '');
                    document.getElementById('hc-ring-a').href = chat + '?answer=' + c.uuid;
                    document.getElementById('hc-ring-x').onclick = () => { seen[c.uuid] = 1; box.style.display = 'none'; if (stop) { stop(); stop = null; } };
                    box.style.display = 'block'; if (!stop) stop = window.hotelRingTone ? hotelRingTone() : null;
                } else if (!list.length) { box.style.display = 'none'; if (stop) { stop(); stop = null; } }
            } catch (e) {}
        }
        setInterval(check, 6000); setTimeout(check, 1500);
    })();
    </script>
@endif
</body>
</html>