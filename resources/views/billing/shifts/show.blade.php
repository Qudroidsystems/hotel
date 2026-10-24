@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Shift ' . $shift->number" icon="ri-safe-2-line" :back="route('cash-shifts.index')" back-label="Shifts"
               :subtitle="$shift->user->name . ' · ' . $shift->station . ' · ' . $shift->opened_at->format('D j M, g:i A') . ($shift->closed_at ? ' – ' . $shift->closed_at->format($shift->closed_at->isSameDay($shift->opened_at) ? 'g:i A' : 'D j M, g:i A') : ' (still open)')">
        <x-slot:pills><span class="cb-meta-pill">{{ $shift->status_label }}</span>@if ($shift->outlet)<span class="cb-meta-pill">{{ $shift->outlet->name }}</span>@endif</x-slot:pills>
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('cash-shifts.show', [$shift, 'print' => 1]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Print shift report</a>
            <a class="cb-hero-btn" href="{{ route('billing.payments', ['from' => $shift->opened_at->toDateString(), 'to' => ($shift->closed_at ?? now())->toDateString(), 'cashier' => $shift->user_id]) }}"><i class="ri-hand-coin-line"></i> Transactions</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-6">
            <x-cb.card title="Cash summary" icon="ri-calculator-line">
                @include('billing.shifts.partials.summary')
                @if ($shift->close_note)<div class="cb-banner info mt-3 mb-0"><i class="ri-chat-1-line"></i><div><strong>Cashier's note:</strong> {{ $shift->close_note }}</div></div>@endif
            </x-cb.card>
        </div>
        <div class="col-xl-6">
            @if ($shift->denominations)
                <x-cb.card title="Cash count" icon="ri-money-dollar-box-line">
                    @foreach ($shift->denominations as $note => $count)
                        <div class="d-flex justify-content-between py-1 border-bottom small"><span>{{ $sym }}{{ number_format((float) $note) }} × {{ $count }}</span><span>@money($note * $count)</span></div>
                    @endforeach
                </x-cb.card>
            @endif

            <x-cb.card title="Paid in / out" icon="ri-exchange-dollar-line" :count="$shift->movements->count()">
                @forelse ($shift->movements as $m)
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>{{ $m->label() }} · {{ $m->reason }}<div class="small text-muted">{{ $m->created_at->format('g:i A') }} · {{ $m->user?->name }}</div></span>
                        <strong>{{ $m->sign() < 0 ? '−' : '+' }}@money($m->amount)</strong>
                    </div>
                @empty
                    <div class="small text-muted">None.</div>
                @endforelse
            </x-cb.card>

            @if ($shift->isOpen() && auth()->user()->can('Review cash shifts') && $shift->user_id !== auth()->id())
                <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>{{ $shift->user->name }} hasn't closed this shift. You can count the drawer with them and close it from <a href="#" data-bs-toggle="collapse" data-bs-target="#forceClose">here</a>.</div></div>
                <form method="POST" action="{{ route('cash-shifts.close', $shift) }}" class="rm-form row g-3 collapse cb-card p-3" id="forceClose" data-once>
                    @csrf
                    <div class="col-md-5"><label for="fcCounted">Cash counted</label><input type="number" name="counted_cash" id="fcCounted" class="form-control" min="0" step="0.01" required></div>
                    <div class="col-md-7"><label for="fcNote">Note</label><input type="text" name="close_note" id="fcNote" class="form-control" maxlength="500" placeholder="Counted with the cashier present"></div>
                    <div class="col-12"><button class="action-btn btn-open"><i class="ri-lock-line"></i> Close shift</button></div>
                </form>
            @endif

            @if ($shift->status === \App\Models\CashShift::CLOSED)
                @can('Review cash shifts')
                    @if ($shift->user_id !== auth()->id() || auth()->user()->hasRole('Super Admin'))
                        <x-cb.card title="Sign off" icon="ri-checkbox-circle-line">
                            <form method="POST" action="{{ route('cash-shifts.review', $shift) }}" class="rm-form row g-3" data-once>
                                @csrf
                                <div class="col-12 small text-muted">Confirm you received {{ $sym }}{{ number_format((float) $shift->counted_cash, 2) }} in cash from {{ $shift->user->name }}.</div>
                                <div class="col-12"><label for="rvNote">Note <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="review_note" id="rvNote" class="form-control" maxlength="500"></div>
                                <div class="col-12"><button class="action-btn btn-open"><i class="ri-check-double-line"></i> Sign off shift</button></div>
                            </form>
                        </x-cb.card>
                    @endif
                @endcan
            @elseif ($shift->status === \App\Models\CashShift::REVIEWED)
                <div class="cb-banner success"><i class="ri-check-double-line"></i><div>Signed off by {{ $shift->reviewedBy?->name }} on {{ $shift->reviewed_at->format('D j M, g:i A') }}.{{ $shift->review_note ? ' “' . $shift->review_note . '”' : '' }}</div></div>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
