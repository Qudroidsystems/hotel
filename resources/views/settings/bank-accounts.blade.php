@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Bank Accounts" icon="ri-bank-line" subtitle="Where guests pay by transfer — printed on invoices and picked when a transfer is recorded">
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-account="new"><i class="ri-add-line"></i> Add account</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="cb-card">
        @if ($accounts->isEmpty())
            <div class="empty-state py-5"><i class="ri-bank-line"></i><h6>No bank accounts yet</h6>
                <p class="small text-muted mb-3">Add the account(s) guests should pay into. They will appear on invoices.</p>
                <button type="button" class="action-btn btn-go" data-account="new"><i class="ri-add-line"></i> Add account</button></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Bank</th><th>Account</th><th>Used for</th><th>Shown on</th><th class="num">Received</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $a)
                            <tr style="{{ $a->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Bank"><div class="fw-bold" style="color: var(--cb-heading)">{{ $a->bank_name }}</div>
                                    @if ($a->branch)<div class="small text-muted">{{ $a->branch }}</div>@endif
                                    @unless ($a->is_active)<span class="status-pill st-muted">Switched off</span>@endunless</td>
                                <td data-label="Account"><span class="rs-ref">{{ $a->account_number }}</span> <span class="small text-muted">{{ $a->currency }}</span>
                                    <div class="small">{{ $a->account_name }}</div>
                                    @if ($a->sort_code)<div class="small text-muted">Sort/SWIFT {{ $a->sort_code }}</div>@endif</td>
                                <td data-label="Used for" class="small">{{ $a->purpose ?: '—' }}</td>
                                <td data-label="Shown on">
                                    @if ($a->show_on_invoice)<span class="status-pill st-info">Invoices</span>@endif
                                    @if ($a->accepts_transfers)<span class="status-pill st-success">Transfer form</span>@endif
                                </td>
                                <td data-label="Received" class="num">@money($a->received ?? 0)<div class="small text-muted">{{ $a->payments_count }} payment{{ $a->payments_count === 1 ? '' : 's' }}</div></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-account="{{ json_encode($a->only(['id', 'bank_name', 'account_name', 'account_number', 'branch', 'sort_code', 'currency', 'purpose', 'show_on_invoice', 'accepts_transfers', 'is_active', 'sort_order'])) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('settings.bank-accounts.destroy', $a) }}" class="d-inline" onsubmit="return confirm('Remove {{ $a->bank_name }} {{ $a->account_number }}?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="action-btn btn-danger-soft" title="Remove"><i class="ri-delete-bin-line"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>

<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('settings.bank-accounts.store') }}" id="accountForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="accMethod">
            <input type="hidden" name="account_id" value="{{ old('account_id') }}" id="accId">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-bank-line me-1"></i> <span id="accTitle">Add bank account</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label for="f_bank_name">Bank <span class="text-danger">*</span></label><input name="bank_name" id="f_bank_name" class="form-control" maxlength="100" required value="{{ old('bank_name') }}" placeholder="e.g. GTBank" list="nigerianBanks"></div>
                <div class="col-sm-6"><label for="f_account_number">Account number <span class="text-danger">*</span></label><input name="account_number" id="f_account_number" class="form-control" maxlength="30" required inputmode="numeric" value="{{ old('account_number') }}" placeholder="10-digit NUBAN"></div>
                <div class="col-12"><label for="f_account_name">Account name <span class="text-danger">*</span></label><input name="account_name" id="f_account_name" class="form-control" maxlength="150" required value="{{ old('account_name', config('hotel.name')) }}"></div>
                <div class="col-sm-6"><label for="f_branch">Branch</label><input name="branch" id="f_branch" class="form-control" maxlength="100" value="{{ old('branch') }}"></div>
                <div class="col-sm-6"><label for="f_sort_code">Sort code / SWIFT</label><input name="sort_code" id="f_sort_code" class="form-control" maxlength="30" value="{{ old('sort_code') }}" placeholder="Only for foreign transfers"></div>
                <div class="col-sm-4"><label for="f_currency">Currency</label><input name="currency" id="f_currency" class="form-control text-uppercase" maxlength="3" minlength="3" required value="{{ old('currency', 'NGN') }}"></div>
                <div class="col-sm-8"><label for="f_purpose">Used for</label><input name="purpose" id="f_purpose" class="form-control" maxlength="100" value="{{ old('purpose') }}" placeholder="e.g. Room payments, Bar & restaurant"></div>
                <div class="col-sm-4"><label for="f_sort_order">Order</label><input type="number" name="sort_order" id="f_sort_order" class="form-control" min="0" max="999" value="{{ old('sort_order', 0) }}"></div>
                <div class="col-sm-8 d-flex flex-column gap-1 justify-content-end">
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="show_on_invoice" value="1" id="f_show_on_invoice" checked> <span class="form-check-label">Print on invoices</span></label>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="accepts_transfers" value="1" id="f_accepts_transfers" checked> <span class="form-check-label">Offer when recording a transfer</span></label>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="f_is_active" checked> <span class="form-check-label">Active</span></label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<datalist id="nigerianBanks">
    @foreach (['Access Bank', 'Citibank', 'Ecobank', 'Fidelity Bank', 'First Bank', 'FCMB', 'Globus Bank', 'GTBank', 'Jaiz Bank', 'Keystone Bank', 'Kuda', 'Moniepoint', 'OPay', 'PalmPay', 'Polaris Bank', 'Providus Bank', 'Stanbic IBTC', 'Standard Chartered', 'Sterling Bank', 'SunTrust Bank', 'Titan Trust Bank', 'Union Bank', 'UBA', 'Unity Bank', 'Wema Bank', 'Zenith Bank'] as $bank)
        <option value="{{ $bank }}">
    @endforeach
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('accountForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('accountModal'));
    const storeUrl = @json(route('settings.bank-accounts.store')), updateUrl = @json(route('settings.bank-accounts.update', '__ID__'));
    document.querySelectorAll('[data-account]').forEach(btn => btn.addEventListener('click', () => {
        const a = btn.dataset.account === 'new' ? null : JSON.parse(btn.dataset.account);
        form.reset();
        form.action = a ? updateUrl.replace('__ID__', a.id) : storeUrl;
        document.getElementById('accMethod').value = a ? 'PUT' : 'POST';
        document.getElementById('accId').value = a ? a.id : '';
        document.getElementById('accTitle').textContent = a ? 'Edit bank account' : 'Add bank account';
        if (a) {
            ['bank_name', 'account_name', 'account_number', 'branch', 'sort_code', 'currency', 'purpose', 'sort_order'].forEach(k => document.getElementById('f_' + k).value = a[k] ?? '');
            ['show_on_invoice', 'accepts_transfers', 'is_active'].forEach(k => document.getElementById('f_' + k).checked = !!a[k]);
        }
        modal.show();
    }));
    @if ($errors->any())
        const oldId = document.getElementById('accId').value;
        if (oldId) { form.action = updateUrl.replace('__ID__', oldId); document.getElementById('accMethod').value = 'PUT'; document.getElementById('accTitle').textContent = 'Edit bank account'; }
        modal.show();
    @endif
});
</script>
@endsection
