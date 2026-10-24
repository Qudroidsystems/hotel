@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\StockItem; $mine = $pr->requested_by === auth()->id(); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pr->number" icon="ri-shopping-cart-2-line" :subtitle="$pr->department . ' · requested by ' . ($pr->requester?->name ?? '—') . ' on ' . $pr->created_at->format('j M Y')"
               :back="route('inventory.requests.index')" back-label="Purchase requests">
        <x-slot:pills><span class="cb-meta-pill">{{ $pr->status_label }}</span>
            @if ($pr->needed_by)<span class="cb-meta-pill"><i class="ri-calendar-line"></i> Needed by {{ $pr->needed_by->format('D j M') }}</span>@endif</x-slot:pills>
    </x-cb.hero>
    @include('rooms.partials.flash')

    @if ($pr->status === 'rejected')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Rejected by {{ $pr->decidedBy?->name }} on {{ $pr->decided_at?->format('j M') }}: {{ $pr->decision_note }}</div></div>@endif
    @if ($pr->status === 'approved')<div class="cb-banner info"><i class="ri-check-line"></i><div>Approved by {{ $pr->decidedBy?->name }} on {{ $pr->decided_at?->format('j M, g:i A') }}{{ $pr->decision_note ? ': ' . $pr->decision_note : '' }}. Waiting for the buyer to order it.</div></div>@endif
    @if ($pr->purchaseOrder)<div class="cb-banner success"><i class="ri-file-list-3-line"></i><div>Ordered on <a href="{{ route('inventory.po.show', $pr->purchaseOrder) }}">{{ $pr->purchaseOrder->number }}</a> ({{ strtolower($pr->purchaseOrder->status_label) }}).</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Items" icon="ri-archive-line" :flush="true">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">On hand</th><th class="num">Est. price</th><th class="num">Est. total</th></tr></thead>
                    <tbody>
                        @foreach ($pr->lines as $l)
                            <tr>
                                <td data-label="Item"><span class="fw-semibold">{{ $l->item->name }}</span>@if ($l->item->supplier)<div class="small text-muted">usually from {{ $l->item->supplier->name }}</div>@endif</td>
                                <td data-label="Quantity" class="num">{{ StockItem::qty($l->quantity) }} {{ $l->item->unit }}</td>
                                <td data-label="On hand" class="num text-muted">{{ StockItem::qty($l->item->onHand()) }}</td>
                                <td data-label="Est. price" class="num">@money($l->unit_cost)</td>
                                <td data-label="Est. total" class="num fw-semibold">@money((float) $l->quantity * (float) $l->unit_cost)</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="4" class="text-end fw-bold">Estimated total</td><td class="num fw-bold">@money($pr->estimated_total)</td></tr></tfoot>
                </table>
            </x-cb.card>
            @if ($pr->reason)<x-cb.card title="Why" icon="ri-chat-1-line"><div style="white-space: pre-line">{{ $pr->reason }}</div></x-cb.card>@endif
        </div>
        <div class="col-xl-4">
            @if ($pr->status === 'submitted')
                @can('Approve purchases')
                    <x-cb.card title="Decision" icon="ri-checkbox-circle-line">
                        @if ($mine)
                            <div class="small text-muted">This is your own request, so another manager has to approve it.</div>
                        @else
                            <form method="POST" action="{{ route('inventory.requests.decide', $pr) }}" class="rm-form" data-once>@csrf
                                <label for="prNote">Note <span class="text-muted fw-normal">(needed to reject)</span></label>
                                <input name="note" id="prNote" class="form-control mb-2" maxlength="255" placeholder="e.g. Approved, but buy half now">
                                <div class="d-flex gap-2"><button name="decision" value="approve" class="btn btn-success"><i class="ri-check-line"></i> Approve</button>
                                    <button name="decision" value="reject" class="btn btn-outline-danger">Reject</button></div>
                            </form>
                        @endif
                    </x-cb.card>
                @endcan
            @endif
            @if ($pr->status === 'approved')
                @can('Manage purchase orders')
                    <x-cb.card title="Order it" icon="ri-file-add-line">
                        <form method="POST" action="{{ route('inventory.requests.order', $pr) }}" class="rm-form" data-once>@csrf
                            <div class="mb-2"><label for="prSup">Supplier</label>
                                <select name="supplier_id" id="prSup" class="form-select" required><option value="">Choose…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected($suggested == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                            <div class="mb-3"><label for="prLoc">Deliver to</label>
                                <select name="location_id" id="prLoc" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected($l->type === 'store')>{{ $l->name }}</option>@endforeach</select></div>
                            <button class="btn btn-primary"><i class="ri-file-add-line"></i> Create purchase order</button>
                            <div class="form-text">Makes a draft order you can adjust before sending.</div>
                        </form>
                    </x-cb.card>
                @endcan
            @endif
            @if (in_array($pr->status, ['submitted', 'approved'], true) && ($mine || auth()->user()->can('Approve purchases')))
                <form method="POST" action="{{ route('inventory.requests.cancel', $pr) }}" class="cb-card p-3" data-once>@csrf
                    <button class="btn btn-outline-secondary btn-sm"><i class="ri-close-line"></i> Cancel this request</button></form>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
