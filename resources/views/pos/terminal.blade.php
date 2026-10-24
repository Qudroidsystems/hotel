<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f2342">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Till | {{ config('hotel.name') }}</title>
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link rel="stylesheet" href="{{ asset('theme/layouts/assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos-terminal.css') }}?v={{ @filemtime(public_path('css/pos-terminal.css')) }}">
</head>
<body>
<div id="app" class="pos" data-loading>

    {{-- ── Top bar ──────────────────────────────────────────── --}}
    <header class="pos-top">
        <button type="button" class="pos-outlet" id="outletBtn" aria-label="Change outlet">
            <span class="dot" id="outletDot"></span>
            <span class="txt"><span class="lbl">Selling at</span><strong id="outletName">—</strong></span>
            <i class="ri-arrow-down-s-line"></i>
        </button>

        <div class="pos-net" id="netPill" role="status" aria-live="polite">
            <span class="dot"></span><span id="netText">Checking…</span>
        </div>
        <button type="button" class="pos-queue" id="queueBtn" hidden>
            <i class="ri-upload-cloud-2-line"></i> <span id="queueText">0 to send</span>
        </button>

        <div class="pos-top-right">
            <span class="pos-user"><i class="ri-user-3-line"></i> <span id="userName">{{ $boot['user']['name'] }}</span></span>
            <a href="{{ route('pos.orders') }}" class="pos-link" data-leave><i class="ri-file-list-3-line"></i><span> Sales</span></a>
            <a href="{{ route('dashboard') }}" class="pos-link" data-leave><i class="ri-home-4-line"></i><span> Exit</span></a>
        </div>
    </header>

    <div class="pos-banner" id="banner" hidden></div>

    <main class="pos-main">
        {{-- ── Menu ─────────────────────────────────────────── --}}
        <section class="pos-menu">
            <div class="pos-search">
                <i class="ri-search-line"></i>
                <input type="search" id="search" placeholder="Search or scan a barcode…" autocomplete="off" aria-label="Search items">
            </div>
            <div class="pos-cats" id="cats" role="tablist"></div>
            <div class="pos-grid" id="grid"></div>
            <div class="pos-menu-foot" id="menuFoot"></div>
        </section>

        {{-- ── Current order ────────────────────────────────── --}}
        <aside class="pos-cart" id="cart">
            <div class="pos-cart-head">
                <div class="pos-tabs">
                    <button type="button" class="on" data-pane="order">Order</button>
                    <button type="button" data-pane="held">Held <span class="n" id="heldN">0</span></button>
                    <button type="button" data-pane="recent">Recent</button>
                </div>
                <button type="button" class="pos-x" id="cartClose" aria-label="Close order"><i class="ri-close-line"></i></button>
            </div>

            <div class="pos-pane" data-pane="order">
                <div class="pos-table">
                    <i class="ri-table-line"></i>
                    <input type="text" id="tableLabel" maxlength="40" placeholder="Table / guest name (optional)" aria-label="Table or guest name">
                </div>
                <div class="pos-lines" id="lines"></div>
                <div class="pos-sum">
                    <div class="row"><span>Subtotal</span><span id="sumSub">0.00</span></div>
                    <div class="row disc" id="sumDiscRow" hidden><span id="sumDiscLbl">Discount</span><span id="sumDisc">0.00</span></div>
                    <div class="row tax" id="sumTaxRow" hidden><span id="sumTaxLbl">Tax</span><span id="sumTax">0.00</span></div>
                    <div class="row total"><span>Total</span><span id="sumTotal">0.00</span></div>
                    <div class="tiny" id="sumTaxNote"></div>
                </div>
                <div class="pos-tools">
                    <button type="button" id="btnHold"><i class="ri-pause-circle-line"></i> Hold</button>
                    <button type="button" id="btnKitchen"><i class="ri-restaurant-line"></i> Kitchen</button>
                    <button type="button" id="btnDiscount" hidden><i class="ri-price-tag-3-line"></i> Discount</button>
                    <button type="button" id="btnClear"><i class="ri-delete-bin-6-line"></i> Clear</button>
                </div>
                <div class="pos-pay" id="payBtns"></div>
            </div>

            <div class="pos-pane" data-pane="held" hidden><div class="pos-list" id="heldList"></div></div>
            <div class="pos-pane" data-pane="recent" hidden><div class="pos-list" id="recentList"></div></div>
        </aside>
    </main>

    {{-- Phone: the order slides up from this bar --}}
    <button type="button" class="pos-cartbar" id="cartBar"><span><i class="ri-shopping-basket-2-line"></i> <span id="cartBarN">0 items</span></span><strong id="cartBarTotal">0.00</strong></button>

    {{-- One dialog element, filled by script --}}
    <div class="pos-modal" id="modal" hidden>
        <div class="pos-dialog" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <div class="pos-dialog-head"><h3 id="modalTitle"></h3><button type="button" class="pos-x" data-close aria-label="Close"><i class="ri-close-line"></i></button></div>
            <div class="pos-dialog-body" id="modalBody"></div>
            <div class="pos-dialog-foot" id="modalFoot"></div>
        </div>
    </div>

    <div class="pos-toast" id="toast" role="status" aria-live="polite" hidden></div>
</div>

{{-- What gets printed (receipt or kitchen ticket) --}}
<div id="printArea" aria-hidden="true"></div>

<noscript><p style="padding:20px">The till needs JavaScript.</p></noscript>

<script>
    window.POS_BOOT = @json($boot);
    window.POS_URLS = {
        ping: @json(route('pos.ping')),
        data: @json(route('pos.data')),
        sync: @json(route('pos.sync')),
        csrf: @json(route('refresh.csrf')),
        login: @json(route('login')),
        terminal: @json(route('pos.terminal')),
        sw: @json(asset('pos-sw.js')),
    };
</script>
<script src="{{ asset('js/pos-terminal.js') }}?v={{ @filemtime(public_path('js/pos-terminal.js')) }}"></script>
</body>
</html>
