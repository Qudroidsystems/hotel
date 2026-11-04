@extends('layouts.master')

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $m = fn ($v) => $sym . number_format((float) $v, 2);
    $canManage = $me->can('Manage payroll'); $canApprove = $me->can('Approve payroll');
@endphp
<style>
    .pr-sum { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
    .pr-sum div { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-radius: 12px; padding: 10px 14px; }
    .pr-sum b { display: block; font-size: 18px; font-variant-numeric: tabular-nums; }
    .pr-acts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
    .pr-acts form { display: flex; gap: 6px; align-items: center; }
    .pr-row td { vertical-align: top; }
    .pr-lines { font-size: 12.5px; color: #475569; margin: 0; padding: 0; list-style: none; }
    .pr-adj { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
    .pr-adj input, .pr-adj select { max-width: 140px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-money-dollar-circle-line" :subtitle="$run->number . ' · ' . $run->from_date->format('j M') . ' – ' . $run->to_date->format('j M Y') . ' · ' . $run->status_label">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('payroll.index') }}"><i class="ri-arrow-left-line"></i> Payroll</a>
            <a class="cb-hero-btn" href="{{ route('payroll.slips', $run) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> All payslips</a>
            @if (in_array($run->status, ['approved', 'paid'], true))<a class="cb-hero-btn" href="{{ route('payroll.bank', $run) }}"><i class="ri-bank-line"></i> Bank schedule (CSV)</a>@endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($run->notes && $run->isDraft())<div class="cb-banner warning"><i class="ri-information-line"></i><div>{{ $run->notes }}</div></div>@endif
    @if ($run->isDraft() && $run->to_date->isFuture())<div class="cb-banner info"><i class="ri-information-line"></i><div>The month isn't over: days still to come aren't counted yet. Recalculate at the end of the month.</div></div>@endif

    <div class="pr-sum">
        <div><span class="small text-muted">Staff</span><b>{{ $run->staff_count }}</b></div>
        <div><span class="small text-muted">Gross pay</span><b>{{ $m($run->gross) }}</b></div>
        <div><span class="small text-muted">Net pay (to banks)</span><b>{{ $m($run->net) }}</b></div>
        <div><span class="small text-muted">PAYE (to the tax office)</span><b>{{ $m($run->paye) }}</b></div>
        <div><span class="small text-muted">Pension (staff + hotel)</span><b>{{ $m((float) $run->pension_employee + (float) $run->pension_employer) }}</b></div>
        <div><span class="small text-muted">NHF</span><b>{{ $m($run->nhf) }}</b></div>
        <div><span class="small text-muted">Cost to the hotel</span><b>{{ $m((float) $run->gross + (float) $run->pension_employer) }}</b></div>
    </div>

    <div class="pr-acts">
        @if ($run->isDraft() && $canManage)
            <form method="POST" action="{{ route('payroll.act', [$run, 'recalculate']) }}" data-once>@csrf<button class="btn btn-outline-secondary"><i class="ri-refresh-line"></i> Recalculate from attendance</button></form>
            <form method="POST" action="{{ route('payroll.act', [$run, 'submit']) }}" data-once>@csrf<button class="btn btn-primary"><i class="ri-send-plane-line"></i> Send for approval</button></form>
        @endif
        @if ($run->status === 'submitted' && $canApprove)
            <form method="POST" action="{{ route('payroll.act', [$run, 'approve']) }}" data-once>@csrf<button class="btn btn-success" @disabled($run->submitted_by === $me->id && ! $me->hasRole('Super Admin'))><i class="ri-check-double-line"></i> Approve {{ $m($run->net) }}</button></form>
        @endif
        @if (in_array($run->status, ['submitted', 'approved'], true) && $canApprove)
            <form method="POST" action="{{ route('payroll.act', [$run, 'reject']) }}" data-once>@csrf<input name="reason" class="form-control" required maxlength="300" placeholder="Why send it back?"><button class="btn btn-outline-danger text-nowrap">Send back</button></form>
        @endif
        @if ($run->status === 'approved' && $canManage)
            <form method="POST" action="{{ route('payroll.act', [$run, 'paid']) }}" data-once>@csrf
                <select name="bank_account_id" class="form-select" aria-label="Paid from"><option value="">Paid from…</option>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->bank_name }} {{ $b->account_number }}</option>@endforeach</select>
                <input type="hidden" name="expense" value="0"><label class="form-check mb-0 text-nowrap"><input type="checkbox" class="form-check-input" name="expense" value="1" checked> Record under Expenses</label>
                <button class="btn btn-primary text-nowrap"><i class="ri-bank-card-line"></i> Mark paid</button></form>
        @endif
        @if (in_array($run->status, ['approved', 'paid'], true) && $canManage)
            <form method="POST" action="{{ route('payroll.act', [$run, 'email']) }}" data-once>@csrf<button class="btn btn-outline-secondary"><i class="ri-mail-send-line"></i> E-mail payslips</button></form>
        @endif
        <span class="small text-muted ms-auto">
            Started by {{ $run->creator?->name ?? '—' }}@if ($run->submitter) · sent by {{ $run->submitter->name }}@endif @if ($run->approver) · approved by {{ $run->approver->name }}@endif @if ($run->payer) · paid {{ $run->paid_at->format('j M') }}@endif
            @if ($run->expense) · <a href="{{ route('expenses.show', $run->expense) }}">{{ $run->expense->number }}</a>@endif
        </span>
    </div>

    <x-cb.card title="Payslips" icon="ri-file-user-line" :flush="true">
        <div class="table-responsive">
        <table class="cb-table">
            <thead><tr><th>Staff</th><th>Attendance</th><th>Earnings</th><th>Deductions</th><th class="num">Gross</th><th class="num">Net</th><th></th></tr></thead>
            <tbody>
                @forelse ($slips as $s)
                    @php $a = $s->attendance; @endphp
                    <tr class="pr-row">
                        <td><strong>{{ $s->name }}</strong><div class="small text-muted">{{ collect([$s->staff_no, $s->department, $s->job_title])->filter()->join(' · ') }}</div>
                            @if (! $s->account_number)<div class="small text-danger">No bank account</div>@endif</td>
                        <td class="small">@if ($a){{ $a['present'] }} worked of {{ $a['scheduled'] + $a['leave'] }}@if ($a['absent']) · <span class="text-danger">{{ $a['absent'] }} absent</span>@endif @if ($a['leave']) · {{ $a['leave'] }} leave{{ $a['unpaid_leave'] ? ' (' . $a['unpaid_leave'] . ' unpaid)' : '' }}@endif @if ($a['late']) · {{ $a['late'] }} late @endif @if ($a['overtime_minutes']) · {{ round($a['overtime_minutes'] / 60, 1) }} h over @endif
                            @else<span class="text-muted">Doesn't clock in — full pay</span>@endif</td>
                        <td><ul class="pr-lines">@foreach ($s->earnings as $l)<li>{{ $l['name'] }}: {{ $l['amount'] < 0 ? '−' : '' }}{{ $m(abs($l['amount'])) }}</li>@endforeach</ul></td>
                        <td><ul class="pr-lines">@foreach ($s->deductions as $l)<li>{{ $l['name'] }}: {{ $m($l['amount']) }}</li>@endforeach</ul>
                            @if ($run->isDraft() && $canManage)
                                @foreach ($s->adjustments ?? [] as $i => $adj)
                                    <form method="POST" action="{{ route('payroll.unadjust', [$run, $s, $i]) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 small text-danger" title="Remove">✕ {{ $adj['name'] }}</button></form>
                                @endforeach
                                <form method="POST" action="{{ route('payroll.adjust', [$run, $s]) }}" class="pr-adj">@csrf
                                    <select name="kind" class="form-select form-select-sm" aria-label="Kind"><option value="earning">+ Add (bonus)</option><option value="deduction">− Take off</option></select>
                                    <input name="name" class="form-control form-control-sm" maxlength="80" required placeholder="What">
                                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control form-control-sm" required placeholder="{{ $sym }}">
                                    <button class="btn btn-sm btn-outline-primary">Add</button>
                                </form>
                            @endif
                        </td>
                        <td class="num">{{ $m($s->gross) }}</td>
                        <td class="num fw-semibold {{ (float) $s->net < 0 ? 'text-danger' : '' }}">{{ $m($s->net) }}</td>
                        <td><a href="{{ route('payroll.slips', [$run, $s]) }}" target="_blank" rel="noopener" class="small">Payslip</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="small text-muted">No one is on this payroll. Set up staff pay, then recalculate.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-cb.card>
</div>
</div>
</div>
@endsection
