@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $c = $company; $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$c->exists ? $c->name : 'New company account'" icon="ri-building-2-line"
               :back="$c->exists ? route('companies.show', $c) : route('companies.index')" :back-label="$c->exists ? $c->name : 'Company Accounts'"
               subtitle="Who to bill, how much credit they get and how long they have to pay." />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ $c->exists ? route('companies.update', $c) : route('companies.store') }}" class="rm-form">
        @csrf @if ($c->exists) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Company" icon="ri-building-2-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label for="name">Company name <span class="text-danger">*</span></label><input name="name" id="name" class="form-control" maxlength="150" required value="{{ old('name', $c->name) }}"></div>
                        <div class="col-md-4"><label for="tax_number">TIN / tax number</label><input name="tax_number" id="tax_number" class="form-control" maxlength="40" value="{{ old('tax_number', $c->tax_number) }}"></div>
                        <div class="col-md-4"><label for="contact_name">Contact person</label><input name="contact_name" id="contact_name" class="form-control" maxlength="120" value="{{ old('contact_name', $c->contact_name) }}" placeholder="Who receives the statements"></div>
                        <div class="col-md-4"><label for="email">Email</label><input type="email" name="email" id="email" class="form-control" maxlength="150" value="{{ old('email', $c->email) }}"></div>
                        <div class="col-md-4"><label for="phone">Phone</label><input name="phone" id="phone" class="form-control" maxlength="40" value="{{ old('phone', $c->phone) }}"></div>
                        <div class="col-12"><label for="address">Billing address</label><textarea name="address" id="address" class="form-control" rows="2" maxlength="500">{{ old('address', $c->address) }}</textarea></div>
                        <div class="col-12"><label for="notes">Notes for staff</label><textarea name="notes" id="notes" class="form-control" rows="2" maxlength="1000" placeholder="e.g. Only rooms and breakfast are billed to the company; guests pay their own bar.">{{ old('notes', $c->notes) }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Credit" icon="ri-bank-card-line">
                    <label for="credit_limit">Credit limit</label>
                    <div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="credit_limit" id="credit_limit" class="form-control" value="{{ old('credit_limit', (float) $c->credit_limit) }}"></div>
                    <div class="small text-muted mb-3">The most it may owe at once. 0 = no limit. Bills that would go over it can't be moved to the company.</div>
                    <label for="payment_terms_days">Days to pay</label>
                    <input type="number" min="0" max="365" name="payment_terms_days" id="payment_terms_days" class="form-control" required value="{{ old('payment_terms_days', $c->payment_terms_days ?? 30) }}">
                    <div class="small text-muted">Each invoice is due this many days after it is raised.</div>
                    @if ($c->exists)
                        <label class="form-check form-switch mt-3 mb-0"><input type="checkbox" class="form-check-input" role="switch" name="is_active" value="1" @checked(old('is_active', $c->is_active))> <span class="form-check-label">Account open (bills can be moved to it)</span></label>
                    @endif
                </x-cb.card>
                <button class="btn btn-primary w-100 py-2 fw-bold"><i class="ri-save-3-line me-1"></i> {{ $c->exists ? 'Save' : 'Open account' }}</button>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
