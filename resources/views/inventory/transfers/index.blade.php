@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stock Transfers" icon="ri-arrow-left-right-line" subtitle="Stock moved between stores">
        <x-slot:actions>
            @can('Transfer stock')<a class="cb-hero-btn" href="{{ route('inventory.transfers.create') }}"><i class="ri-arrow-left-right-line"></i> New transfer</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="location" class="cb-select" aria-label="Store" onchange="this.form.submit()">
                <option value="">All stores</option>
                @foreach ($locations as $l)<option value="{{ $l->id }}" @selected(request('location') == $l->id)>{{ $l->name }}</option>@endforeach
            </select>
        </form>
        @if ($transfers->isEmpty())
            <div class="empty-state py-5"><i class="ri-arrow-left-right-line"></i><h6>No transfers yet</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Transfer</th><th>From → to</th><th>Items</th><th>By</th></tr></thead>
                    <tbody>
                        @foreach ($transfers as $t)
                            <tr>
                                <td data-label="Transfer"><a href="{{ route('inventory.transfers.show', $t) }}" class="rs-ref">{{ $t->number }}</a><div class="small text-muted">{{ $t->created_at->format('j M Y, g:i A') }}</div></td>
                                <td data-label="From → to" class="fw-semibold">{{ $t->from->name }} <i class="ri-arrow-right-line text-muted"></i> {{ $t->to->name }}@if ($t->notes)<div class="small text-muted fw-normal">{{ $t->notes }}</div>@endif</td>
                                <td data-label="Items">{{ $t->lines_count }}</td>
                                <td data-label="By" class="small">{{ $t->createdBy?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $transfers->links() }}</div>
        @endif
    </div>
</div>
</div>
</div>
@endsection
