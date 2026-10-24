@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\PurchaseOrder; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Purchase Orders" icon="ri-file-list-3-line" subtitle="What has been ordered from suppliers, and what is still to arrive">
        <x-slot:actions>
            @can('Manage purchase orders')<a class="cb-hero-btn" href="{{ route('inventory.po.create') }}"><i class="ri-add-line"></i> New order</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="rs-tabs">
        <a href="{{ route('inventory.po.index') }}" class="rs-tab {{ ! $status ? 'active' : '' }}">Open &amp; recent</a>
        @foreach (PurchaseOrder::STATUSES as $key => [$label])
            <a href="{{ route('inventory.po.index', ['status' => $key]) }}" class="rs-tab {{ $status === $key ? 'active' : '' }}">{{ $label }} <span class="n">{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <select name="supplier" class="cb-select" aria-label="Supplier" onchange="this.form.submit()">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </form>
        @if ($orders->isEmpty())
            <div class="empty-state py-5"><i class="ri-file-list-3-line"></i><h6>No purchase orders</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Order</th><th>Supplier</th><th>Deliver to</th><th>Expected</th><th>Status</th><th class="num">Value</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $o)
                            <tr>
                                <td data-label="Order"><a href="{{ route('inventory.po.show', $o) }}" class="rs-ref">{{ $o->number }}</a><div class="small text-muted">{{ $o->created_at->format('j M Y') }} · {{ $o->createdBy?->name }}</div></td>
                                <td data-label="Supplier" class="fw-semibold">{{ $o->supplier->name }}<div class="small text-muted fw-normal">{{ $o->lines_count }} item{{ $o->lines_count === 1 ? '' : 's' }}</div></td>
                                <td data-label="Deliver to">{{ $o->location->name }}</td>
                                <td data-label="Expected" class="small {{ $o->expected_on && $o->expected_on->isPast() && $o->canReceive() ? 'text-danger fw-semibold' : '' }}">{{ $o->expected_on?->format('D j M') ?? '—' }}</td>
                                <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></td>
                                <td data-label="Value" class="num fw-bold">@money($o->total)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $orders->links() }}</div>
        @endif
    </div>
</div>
</div>
</div>
@endsection
