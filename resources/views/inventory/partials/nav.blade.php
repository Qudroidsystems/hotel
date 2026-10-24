{{-- Inventory section tabs --}}
@php
    $invTabs = [
        ['inventory.index', 'Stock', 'ri-archive-line', 'View stock'],
        ['inventory.receipts.index', 'Received', 'ri-truck-line', 'View stock'],
        ['inventory.requests.index', 'Requests', 'ri-shopping-cart-2-line', ['Request purchases', 'Approve purchases', 'Manage purchase orders']],
        ['inventory.po.index', 'Purchase orders', 'ri-file-list-3-line', 'View stock'],
        ['inventory.invoices.index', 'Supplier bills', 'ri-bill-line', ['Manage supplier invoices', 'Pay suppliers']],
        ['inventory.transfers.index', 'Transfers', 'ri-arrow-left-right-line', 'View stock'],
        ['inventory.counts.index', 'Stocktakes', 'ri-list-check-2', 'View stock'],
        ['inventory.suppliers.index', 'Suppliers', 'ri-store-3-line', 'View stock'],
        ['inventory.recipes.index', 'Recipes', 'ri-restaurant-line', ['Manage stock items', 'Manage POS menu']],
        ['inventory.reports', 'Reports', 'ri-bar-chart-2-line', 'View stock reports'],
        ['inventory.settings', 'Stores', 'ri-settings-3-line', 'Manage stock items'],
    ];
    $current = request()->route()->getName();
@endphp
<div class="rs-tabs mb-3" role="navigation" aria-label="Inventory">
    @foreach ($invTabs as [$route, $label, $icon, $perm])
        @canany((array) $perm)
            @php $base = \Illuminate\Support\Str::beforeLast($route, '.'); $on = $current === $route || ($base !== 'inventory' && str_starts_with($current, $base . '.')) || ($route === 'inventory.index' && $current === 'inventory.items.show'); @endphp
            <a href="{{ route($route) }}" class="rs-tab {{ $on ? 'active' : '' }}"><i class="{{ $icon }}"></i> {{ $label }}</a>
        @endcanany
    @endforeach
</div>
