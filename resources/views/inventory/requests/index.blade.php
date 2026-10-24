@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\PurchaseRequest; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-shopping-cart-2-line" subtitle="Departments ask for what they need; a manager approves; the buyer turns it into a purchase order.">
        <x-slot:actions>@can('Request purchases')<a class="cb-hero-btn" href="{{ route('inventory.requests.create') }}"><i class="ri-add-line"></i> New request</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @can('View stock')
        @include('inventory.partials.nav')
    @endcan
    @include('rooms.partials.flash')

    <div class="rs-tabs mb-3">
        <a href="{{ route('inventory.requests.index') }}" class="rs-tab {{ ! $status ? 'active' : '' }}">Open <span class="n">{{ ($counts['submitted'] ?? 0) + ($counts['approved'] ?? 0) }}</span></a>
        @foreach (PurchaseRequest::STATUSES as $key => [$label])
            <a href="{{ route('inventory.requests.index', ['status' => $key]) }}" class="rs-tab {{ $status === $key ? 'active' : '' }}">{{ $label }} <span class="n">{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </div>

    <div class="cb-card">
        @if (! $seeAll)<div class="px-3 pt-3 small text-muted">Showing your own requests.</div>@endif
        @if ($requests->isEmpty())
            <div class="empty-state py-5"><i class="ri-shopping-cart-2-line"></i><h6>No requests here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Request</th><th>Department</th><th>Needed by</th><th class="num">Estimate</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr>
                                <td data-label="Request"><a href="{{ route('inventory.requests.show', $r) }}" class="rs-ref">{{ $r->number }}</a>
                                    <div class="small text-muted">{{ $r->lines_count }} {{ Str::plural('item', $r->lines_count) }} · {{ $r->requester?->name }} · {{ $r->created_at->format('j M') }}</div></td>
                                <td data-label="Department">{{ $r->department }}@if ($r->reason)<div class="small text-muted">{{ Str::limit($r->reason, 60) }}</div>@endif</td>
                                <td data-label="Needed by" class="{{ $r->needed_by && $r->needed_by->isPast() && in_array($r->status, ['submitted', 'approved'], true) ? 'text-danger fw-semibold' : '' }}">{{ $r->needed_by?->format('D j M') ?? '—' }}</td>
                                <td data-label="Estimate" class="num">@money($r->estimated_total)</td>
                                <td data-label="Status"><span class="status-pill {{ $r->status_pill }}">{{ $r->status_label }}</span>
                                    @if ($r->purchaseOrder)<div class="small"><a href="{{ route('inventory.po.show', $r->purchaseOrder) }}">{{ $r->purchaseOrder->number }}</a></div>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
</div>
</div>
@endsection
