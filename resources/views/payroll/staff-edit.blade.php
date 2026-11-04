@extends('layouts.master')

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $allow = old('allowances', $pay->allowances ?? []); $ded = old('deductions', $pay->deductions ?? []);
    $type = old('pay_type', $pay->pay_type ?? 'monthly');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid" style="max-width:980px">
    <x-cb.hero :title="$pagetitle" icon="ri-user-settings-line" :subtitle="collect([$profile?->staff_no, $profile?->job_title])->filter()->join(' · ') ?: 'Pay, deductions and bank details'">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('payroll.staff') }}"><i class="ri-arrow-left-line"></i> Staff pay</a></x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    <form method="POST" action="{{ route('payroll.staff.save', $user) }}">@csrf
        <x-cb.card title="Pay" icon="ri-money-dollar-box-line">
            <div class="row g-3">
                <div class="col-12 d-flex gap-4">
                    <label class="form-check"><input type="radio" class="form-check-input" name="pay_type" value="monthly" @checked($type === 'monthly')> Monthly salary</label>
                    <label class="form-check"><input type="radio" class="form-check-input" name="pay_type" value="daily" @checked($type === 'daily')> Paid by the day worked</label>
                </div>
                <div class="col-md-3"><label class="form-label small">Basic (a month)</label><input type="number" step="0.01" min="0" name="basic" class="form-control" value="{{ old('basic', (float) $pay->basic ?: '') }}"></div>
                <div class="col-md-3"><label class="form-label small">Housing</label><input type="number" step="0.01" min="0" name="housing" class="form-control" value="{{ old('housing', (float) $pay->housing ?: '') }}"></div>
                <div class="col-md-3"><label class="form-label small">Transport</label><input type="number" step="0.01" min="0" name="transport" class="form-control" value="{{ old('transport', (float) $pay->transport ?: '') }}"></div>
                <div class="col-md-3"><label class="form-label small">Daily rate</label><input type="number" step="0.01" min="0" name="daily_rate" class="form-control" value="{{ old('daily_rate', $pay->daily_rate) }}" placeholder="Daily-paid staff"></div>
                <div class="col-md-6">
                    <label class="form-label small">Other monthly allowances</label>
                    @for ($i = 0; $i < max(3, count($allow) + 1); $i++)
                        <div class="d-flex gap-2 mb-2"><input name="allowances[{{ $i }}][name]" class="form-control" maxlength="60" placeholder="e.g. Meal, Utility" value="{{ $allow[$i]['name'] ?? '' }}"><input type="number" step="0.01" min="0" name="allowances[{{ $i }}][amount]" class="form-control" style="max-width:150px" placeholder="{{ $sym }}" value="{{ $allow[$i]['amount'] ?? '' }}"></div>
                    @endfor
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Taken off every month</label>
                    @for ($i = 0; $i < max(3, count($ded) + 1); $i++)
                        <div class="d-flex gap-2 mb-2"><input name="deductions[{{ $i }}][name]" class="form-control" maxlength="60" placeholder="e.g. Cooperative, Loan" value="{{ $ded[$i]['name'] ?? '' }}"><input type="number" step="0.01" min="0" name="deductions[{{ $i }}][amount]" class="form-control" style="max-width:150px" placeholder="{{ $sym }}" value="{{ $ded[$i]['amount'] ?? '' }}"></div>
                    @endfor
                </div>
                <div class="col-md-4"><label class="form-label small">Overtime rate (an hour)</label><input type="number" step="0.01" min="0" name="overtime_rate" class="form-control" value="{{ old('overtime_rate', $pay->overtime_rate) }}" placeholder="Empty = worked out from pay"></div>
                <div class="col-md-8 d-flex gap-4 align-items-end flex-wrap">
                    @foreach (['paye' => 'PAYE tax', 'pension' => 'Pension (contributory)', 'nhf' => 'NHF'] as $k => $l)
                        <input type="hidden" name="{{ $k }}" value="0"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="{{ $k }}" value="1" @checked(old($k, $pay->{$k}))> {{ $l }}</label>
                    @endforeach
                </div>
            </div>
        </x-cb.card>

        <x-cb.card title="Bank, tax and pension" icon="ri-bank-line" class="mt-3">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label small">Bank</label><input name="bank_name" class="form-control" maxlength="80" value="{{ old('bank_name', $pay->bank_name) }}" placeholder="e.g. GTBank"></div>
                <div class="col-md-4"><label class="form-label small">Account number</label><input name="account_number" class="form-control" inputmode="numeric" maxlength="10" pattern="\d{10}" value="{{ old('account_number') }}" placeholder="{{ $pay->account_masked ? $pay->account_masked . ' — type to change' : '10 digits' }}" autocomplete="off"></div>
                <div class="col-md-4"><label class="form-label small">Account name</label><input name="account_name" class="form-control" maxlength="120" value="{{ old('account_name', $pay->account_name) }}"></div>
                <div class="col-md-3"><label class="form-label small">Tax ID (TIN)</label><input name="tin" class="form-control" maxlength="30" value="{{ old('tin', $pay->tin) }}"></div>
                <div class="col-md-3"><label class="form-label small">Pension PIN</label><input name="pension_pin" class="form-control" maxlength="30" value="{{ old('pension_pin', $pay->pension_pin) }}"></div>
                <div class="col-md-3"><label class="form-label small">Pension fund (PFA)</label><input name="pfa" class="form-control" maxlength="80" value="{{ old('pfa', $pay->pfa) }}"></div>
                <div class="col-md-3"><label class="form-label small">Rent paid a year (rent relief)</label><input type="number" step="0.01" min="0" name="annual_rent" class="form-control" value="{{ old('annual_rent', $pay->annual_rent) }}"></div>
                <div class="col-12"><input type="hidden" name="is_active" value="0"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked(old('is_active', $pay->is_active ?? true))> On the payroll</label></div>
            </div>
        </x-cb.card>
        <div class="d-flex justify-content-end mt-3"><button class="btn btn-primary">Save pay</button></div>
    </form>
</div>
</div>
</div>
@endsection
