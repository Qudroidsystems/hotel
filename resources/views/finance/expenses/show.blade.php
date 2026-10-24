@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $me = auth()->user();
    $files = $expense->attachments ?? [];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$expense->description" icon="ri-wallet-3-line" :back="route('expenses.index')" back-label="Expenses"
               :subtitle="$expense->number . ' · ' . $expense->category?->name . ' · ' . $expense->expense_date->format('j F Y') . ($expense->department ? ' · ' . $expense->department : '')">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $expense->status_label }}</span>
            <span class="cb-meta-pill">{{ $fmt($expense->amount) }}</span>
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($expense->status === 'pending')
        <div class="cb-banner warning"><i class="ri-time-line"></i><div>Over the {{ $fmt(config('hotel.expenses.approval_limit')) }} limit — waiting for approval by someone other than {{ $expense->recorder?->name ?? 'the person who recorded it' }}.</div></div>
    @elseif ($expense->status === 'rejected')
        <div class="cb-banner danger"><i class="ri-close-circle-line"></i><div><strong>Rejected</strong> by {{ $expense->approver?->name }}: {{ $expense->rejection_reason }}</div></div>
    @elseif ($expense->status === 'void')
        <div class="cb-banner danger"><i class="ri-forbid-line"></i><div><strong>Void</strong> — {{ $expense->voider?->name }}, {{ $expense->voided_at?->format('j M Y, g:i A') }}: {{ $expense->void_reason }}</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Details" icon="ri-information-line">
                <div class="row g-3 small">
                    <div class="col-sm-4"><span class="text-muted">Amount paid</span><div class="fs-5 fw-bold">{{ $fmt($expense->amount) }}</div>@if ((float) $expense->tax_amount)<div class="text-muted">incl. {{ $fmt($expense->tax_amount) }} VAT · cost {{ $fmt($expense->net_amount) }}</div>@endif</div>
                    <div class="col-sm-4"><span class="text-muted">Paid to</span><div>{{ $expense->payee_name ?: '—' }}</div>@if ($expense->invoice_ref)<div class="text-muted">Invoice {{ $expense->invoice_ref }}</div>@endif</div>
                    <div class="col-sm-4"><span class="text-muted">Category</span><div>{{ $expense->category?->name }}</div><div class="text-muted">{{ $expense->category?->group_label }}</div></div>
                    @if ($expense->notes)<div class="col-12"><span class="text-muted">Notes</span><div>{{ $expense->notes }}</div></div>@endif
                </div>
            </x-cb.card>

            <x-cb.card title="Receipts" icon="ri-attachment-2" :count="count($files)">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @forelse ($files as $i => $path)
                        @php $isPdf = str_ends_with(strtolower($path), '.pdf'); $url = route('expenses.file', [$expense, $i]); @endphp
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="d-block border rounded overflow-hidden text-center" style="width:120px;height:100px;background:#f8fafc">
                            @if ($isPdf)<i class="ri-file-pdf-2-line text-danger" style="font-size:40px;line-height:70px"></i><div class="small">PDF {{ $i + 1 }}</div>
                            @else<img src="{{ $url }}" alt="Receipt {{ $i + 1 }}" style="width:100%;height:100%;object-fit:cover">@endif
                        </a>
                    @empty
                        <div class="small text-muted">No receipt attached.@if ((float) $expense->amount >= (float) config('hotel.audit.flag_amount')) <span class="text-danger">An expense this size should have one.</span>@endif</div>
                    @endforelse
                </div>
                @if (count($files) < 8 && ($expense->recorded_by === $me->id || $me->canAny(['Approve expenses', 'Pay expenses'])) && $expense->status !== 'void')
                    <form method="POST" action="{{ route('expenses.attach', $expense) }}" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap" data-once>@csrf
                        <input type="file" name="attachments[]" class="form-control" style="max-width:320px" multiple accept="image/*,application/pdf" required>
                        <button class="action-btn btn-open"><i class="ri-upload-2-line"></i> Add</button>
                    </form>
                @endif
            </x-cb.card>

            <x-cb.card title="History" icon="ri-history-line">
                <div class="small">
                    <div class="py-1"><i class="ri-edit-line text-muted"></i> Recorded by {{ $expense->recorder?->name ?? '—' }}, {{ $expense->created_at->format('D j M Y, g:i A') }}</div>
                    @if ($expense->approved_at && $expense->status !== 'rejected')<div class="py-1"><i class="ri-check-line text-success"></i> Approved {{ $expense->approver ? 'by ' . $expense->approver->name : '(within the limit)' }}, {{ $expense->approved_at->format('D j M Y, g:i A') }}</div>@endif
                    @if ($expense->paid_at)<div class="py-1"><i class="ri-hand-coin-line text-success"></i> Paid by {{ $expense->payer?->name }} — {{ $expense->method_label }}{{ $expense->bankAccount ? ' from ' . $expense->bankAccount->bank_name . ' ' . $expense->bankAccount->account_number : '' }}{{ $expense->cashShift ? ' from till (shift ' . $expense->cashShift->number . ')' : '' }}{{ $expense->payment_ref ? ', ref ' . $expense->payment_ref : '' }} — {{ $expense->paid_at->format('D j M Y, g:i A') }}</div>@endif
                </div>
                @can('View audit log')<a class="small" href="{{ route('audit.index', ['type' => $expense->getMorphClass(), 'id' => $expense->id, 'from' => $expense->created_at->toDateString()]) }}">Full audit trail</a>@endcan
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            @if ($expense->status === 'pending' && $me->can('Approve expenses'))
                <x-cb.card title="Approve" icon="ri-shield-check-line">
                    @if ($expense->recorded_by === $me->id)
                        <div class="small text-muted">You recorded this expense, so someone else must approve it.</div>
                    @else
                        <form method="POST" action="{{ route('expenses.approve', $expense) }}" class="mb-2" data-once>@csrf<button class="action-btn btn-go"><i class="ri-check-line"></i> Approve {{ $fmt($expense->amount) }}</button></form>
                        <form method="POST" action="{{ route('expenses.reject', $expense) }}" class="rm-form d-flex gap-2" data-once>@csrf
                            <input name="reason" class="form-control" maxlength="255" required placeholder="Reason for rejecting">
                            <button class="action-btn btn-danger-soft">Reject</button></form>
                    @endif
                </x-cb.card>
            @endif

            @if ($expense->status === 'approved' && $me->can('Pay expenses'))
                <x-cb.card title="Record payment" icon="ri-hand-coin-line">
                    <form method="POST" action="{{ route('expenses.pay', $expense) }}" class="rm-form row g-2" data-once>@csrf
                        @include('finance.expenses._pay')
                        <div class="col-12"><button class="action-btn btn-go"><i class="ri-check-line"></i> Mark {{ $fmt($expense->amount) }} paid</button></div>
                    </form>
                </x-cb.card>
            @endif

            @if ($canEdit)
                <x-cb.card title="Edit" icon="ri-pencil-line">
                    <form method="POST" action="{{ route('expenses.update', $expense) }}" class="rm-form row g-2" data-once>@csrf @method('PUT')
                        @include('finance.expenses._form', ['e' => $expense])
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-save-line"></i> Save changes</button>
                            @if ($expense->status === 'approved')<div class="form-text">Raising the amount above the limit sends it back for approval.</div>@endif</div>
                    </form>
                </x-cb.card>
            @endif

            @if (in_array($expense->status, ['pending', 'approved', 'paid'], true) && $me->can('Approve expenses'))
                <x-cb.card title="Void" icon="ri-forbid-line">
                    <form method="POST" action="{{ route('expenses.void', $expense) }}" class="rm-form d-flex gap-2" onsubmit="return confirm('Void {{ $expense->number }}? It will no longer count as a cost.');">@csrf
                        <input name="reason" class="form-control" maxlength="255" required placeholder="Why — e.g. recorded twice">
                        <button class="action-btn btn-danger-soft">Void</button></form>
                    <div class="form-text">For mistakes only. It stays on record, marked void.</div>
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@include('finance.expenses._scripts')
@endsection
