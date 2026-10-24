@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stocktakes" icon="ri-list-check-2" subtitle="Count what is really on the shelves; the differences are booked to stock when approved" />
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="row g-3">
        @can('Count stock')
            <div class="col-xl-4">
                <x-cb.card title="Start a stocktake" icon="ri-play-circle-line">
                    <form method="POST" action="{{ route('inventory.counts.store') }}" class="rm-form">
                        @csrf
                        <div class="mb-3"><label for="c_loc">Store</label>
                            <select name="location_id" id="c_loc" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id') == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
                        <div class="mb-3"><label for="c_cat">Only this category</label>
                            <select name="category_id" id="c_cat" class="form-select"><option value="">Everything in the store</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                        <div class="mb-3"><label for="c_notes">Note</label><input name="notes" id="c_notes" class="form-control" maxlength="500" value="{{ old('notes') }}" placeholder="e.g. Month-end count"></div>
                        <button class="btn btn-primary w-100" type="submit"><i class="ri-play-line"></i> Start counting</button>
                        <div class="small text-muted mt-2">The system quantities are noted at the start. Count when the bar or kitchen is quiet, so sales during the count don't blur the result.</div>
                    </form>
                </x-cb.card>
            </div>
        @endcan
        <div class="{{ auth()->user()->can('Count stock') ? 'col-xl-8' : 'col-12' }}">
            <div class="cb-card">
                @if ($counts->isEmpty())
                    <div class="empty-state py-5"><i class="ri-list-check-2"></i><h6>No stocktakes yet</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Stocktake</th><th>Store</th><th>Progress</th><th>Status</th><th class="num">Difference</th></tr></thead>
                            <tbody>
                                @foreach ($counts as $c)
                                    <tr>
                                        <td data-label="Stocktake"><a href="{{ route('inventory.counts.show', $c) }}" class="rs-ref">{{ $c->number }}</a><div class="small text-muted">{{ $c->created_at->format('j M Y') }} · {{ $c->startedBy?->name }}</div></td>
                                        <td data-label="Store" class="fw-semibold">{{ $c->location->name }}@if ($c->category)<div class="small text-muted fw-normal">{{ $c->category->name }}</div>@endif</td>
                                        <td data-label="Progress" class="small">{{ $c->counted_count }} / {{ $c->lines_count }} counted</td>
                                        <td data-label="Status"><span class="status-pill {{ ['counting' => 'st-warning', 'posted' => 'st-success', 'cancelled' => 'st-muted'][$c->status] ?? 'st-muted' }}">{{ ['counting' => 'Counting', 'posted' => 'Approved', 'cancelled' => 'Cancelled'][$c->status] ?? $c->status }}</span></td>
                                        <td data-label="Difference" class="num fw-bold {{ $c->variance_value < 0 ? 'text-danger' : '' }}">{{ $c->variance_value !== null ? ($c->variance_value < 0 ? '−' : '') . config('hotel.currency_symbol') . number_format(abs((float) $c->variance_value), 2) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $counts->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
</div>
</div>
@endsection
