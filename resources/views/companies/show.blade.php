@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\PaymentMethod;
    use App\Services\Companies\CompanyLedger;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
    $c = $company;
    $limit = (float) $c->credit_limit;
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$c->name" icon="ri-building-2-line" :back="route('companies.index')" back-label="Company Accounts"
               :subtitle="collect([$c->contact_name, $c->phone, $c->email])->filter()->implode(' · ') ?: 'Company account'">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-hashtag"></i> {{ $c->account_number }}</span>
            <span class="cb-meta-pill"><i class="ri-time-line"></i> {{ $c->payment_terms_days }} days to pay</span>
            @unless ($c->is_active)<span class="cb-meta-pill"><i class="ri-lock-line"></i> Closed</span>@endunless
        </x-slot:pills>
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('companies.statement', $c) }}" target="_blank"><i class="ri-file-list-3-line"></i> Statement</a>
            @can('Receive company payments')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#payModal"><i class="ri-money-dollar-circle-line"></i> Record payment</button>@endcan
            @can('Manage company accounts')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#adjModal"><i class="ri-scales-3-line"></i> Credit / adjust</button>
                <a class="cb-hero-btn" href="{{ route('companies.edit', $c) }}"><i class="ri-pencil-line"></i> Edit</a>
            @endcan
            @can('Create reservations')<a class="cb-hero-btn" href="{{ route('reservations.create', ['company' => $c->id]) }}"><i class="ri-calendar-check-line"></i> Book a stay</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat :label="$balance < 0 ? 'In credit' : 'Owes now'" :value="$fmt(abs($balance))" icon="ri-hand-coin-line" :accent="$balance < 0 ? 'green' : 'teal'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Overdue" :value="$fmt($ageing['overdue'])" icon="ri-time-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Credit limit" :value="$limit > 0 ? $fmt($limit) : 'No limit'" icon="ri-bank-card-line" accent="sky" :hint="$limit > 0 ? 'Left: ' . $fmt(max(0, $limit - $balance)) : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Stays billed" :value="$c->reservations()->count()" icon="ri-hotel-bed-line" accent="violet" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Account" icon="ri-book-2-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Date</th><th>Entry</th><th>Details</th><th class="num">Amount</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($entries as $e)
                                <tr style="{{ $e->isVoided() ? 'opacity:.5' : '' }}">
                                    <td data-label="Date" class="small text-nowrap">{{ $e->entry_date->format('j M Y') }}@if ($e->due_date && $e->type === 'invoice')<div class="text-muted">due {{ $e->due_date->format('j M') }}</div>@endif</td>
                                    <td data-label="Entry"><span class="status-pill {{ $e->type_class }}">{{ $e->type_label }}</span><div class="small fw-semibold">{{ $e->number }}</div></td>
                                    <td data-label="Details" class="small">{{ $e->description }}
                                        @if ($e->reservation)<div><a href="{{ route('reservations.show', $e->reservation) }}">{{ $e->reservation->reference }}</a></div>@endif
                                        @if ($e->reference && $e->type === 'payment')<div class="text-muted">Ref {{ $e->reference }}</div>@endif
                                        @if ($e->isVoided())<div class="text-danger">Voided by {{ $e->voider?->name }}: {{ $e->void_reason }}</div>@endif
                                    </td>
                                    <td data-label="Amount" class="num fw-semibold {{ (float) $e->amount < 0 ? 'text-success' : '' }}" style="{{ $e->isVoided() ? 'text-decoration:line-through' : '' }}">{{ $fmt($e->amount) }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($e->type === 'invoice')<a class="action-btn btn-more" href="{{ route('companies.invoice', [$c, $e]) }}" target="_blank" title="Invoice"><i class="ri-printer-line"></i></a>@endif
                                        @if (! $e->isVoided())
                                            @can('Manage company accounts')<button type="button" class="action-btn btn-more" data-void="{{ route('companies.void', [$c, $e]) }}" data-label="{{ $e->number }}" title="Void"><i class="ri-close-circle-line"></i></button>@endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-muted">Nothing on this account yet. Bills are moved here from a guest's bill (Billing → the bill → Bill to company).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($entries->hasPages())<div class="p-3">{{ $entries->links() }}</div>@endif
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="What is owed, by age" icon="ri-hourglass-line">
                @foreach (CompanyLedger::BUCKETS as $k => $label)
                    <div class="d-flex justify-content-between small py-1 {{ $k !== 'current' && $ageing['buckets'][$k] > 0 ? 'text-danger fw-semibold' : '' }}"><span>{{ $label }}</span><span>{{ $fmt($ageing['buckets'][$k]) }}</span></div>
                @endforeach
                @if ($ageing['unapplied'] > 0)<div class="d-flex justify-content-between small py-1 text-success border-top mt-1"><span>Paid ahead / credit</span><span>{{ $fmt($ageing['unapplied']) }}</span></div>@endif
                @if ($ageing['open']->isNotEmpty())
                    <div class="small fw-bold mt-3 mb-1" style="color:var(--cb-heading)">Unpaid invoices</div>
                    @foreach ($ageing['open']->take(8) as $o)
                        <div class="d-flex justify-content-between small"><a href="{{ route('companies.invoice', [$c, $o->entry]) }}" target="_blank">{{ $o->entry->number }}</a><span class="{{ $o->late > 0 ? 'text-danger' : 'text-muted' }}">{{ $o->late > 0 ? $o->late . ' days late' : 'due ' . ($o->entry->due_date?->format('j M') ?? '') }}</span><span>{{ $fmt($o->left) }}</span></div>
                    @endforeach
                @endif
            </x-cb.card>

            @if ($plans->isNotEmpty())
                <x-cb.card title="Negotiated rates" icon="ri-price-tag-3-line">
                    @foreach ($plans as $p)<div class="small"><strong>{{ $p->name }}</strong> — {{ $p->summary }}@unless ($p->is_active) <span class="status-pill st-muted">Off</span>@endunless</div>@endforeach
                </x-cb.card>
            @endif

            <x-cb.card title="Details" icon="ri-information-line">
                <div class="small">
                    @if ($c->tax_number)<div><span class="text-muted">TIN</span> {{ $c->tax_number }}</div>@endif
                    @if ($c->address)<div class="mt-1" style="white-space:pre-line">{{ $c->address }}</div>@endif
                    @if ($c->notes)<div class="cb-banner info mt-2 mb-0"><i class="ri-sticky-note-line"></i><div>{{ $c->notes }}</div></div>@endif
                    @if (! $c->tax_number && ! $c->address && ! $c->notes)<div class="text-muted">No address or tax number yet. @can('Manage company accounts')<a href="{{ route('companies.edit', $c) }}">Add them</a> for invoices and statements.@endcan</div>@endif
                </div>
            </x-cb.card>

            <x-cb.card title="Recent stays" icon="ri-hotel-bed-line">
                @forelse ($stays as $r)
                    <div class="d-flex justify-content-between small py-1"><a href="{{ route('reservations.show', $r) }}">{{ $r->guest?->full_name }}</a><span class="text-muted">{{ $r->check_in_date->format('j M') }} · {{ $r->status->label() }}</span></div>
                @empty
                    <div class="small text-muted">No stays booked for this company yet.</div>
                @endforelse
                @if ($people->isNotEmpty())<div class="small text-muted mt-2">People: {{ $people->pluck('full_name')->implode(', ') }}</div>@endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@can('Receive company payments')
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('companies.payment', $c) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Payment from {{ $c->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label for="p_amount">Amount <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0.01" name="amount" id="p_amount" class="form-control" required value="{{ $balance > 0 ? number_format($balance, 2, '.', '') : '' }}"></div></div>
                <div class="col-sm-6"><label for="p_method">Paid by</label><select name="method" id="p_method" class="form-select">@foreach (PaymentMethod::takeable() as $m)<option value="{{ $m->value }}" @selected($m === PaymentMethod::Transfer)>{{ $m->label() }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="p_reference">Reference</label><input name="reference" id="p_reference" class="form-control" maxlength="100" placeholder="Transfer or cheque number"></div>
                <div class="col-sm-6"><label for="p_date">Date received</label><input type="date" name="date" id="p_date" class="form-control" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}"></div>
                <div class="col-12"><label for="p_note">Note</label><input name="note" id="p_note" class="form-control" maxlength="150" placeholder="e.g. For the September statement"></div>
                <div class="col-12 small text-muted">Payments are applied to the oldest invoices first.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Record payment</button></div>
        </form>
    </div>
</div>
@endcan
@can('Manage company accounts')
<div class="modal fade" id="adjModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('companies.entry', $c) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Credit note or adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label class="form-check mb-1"><input type="radio" class="form-check-input" name="type" value="credit" checked> <span class="form-check-label"><strong>Credit note</strong> — money off what they owe (e.g. a complaint settled)</span></label>
                    <label class="form-check"><input type="radio" class="form-check-input" name="type" value="adjustment"> <span class="form-check-label"><strong>Adjustment</strong> — correct the balance either way (negative to reduce)</span></label></div>
                <div class="col-sm-6"><label for="a_amount">Amount <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" name="amount" id="a_amount" class="form-control" required></div></div>
                <div class="col-12"><label for="a_reason">Reason <span class="text-danger">*</span></label><input name="reason" id="a_reason" class="form-control" maxlength="200" required></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" id="voidForm">@csrf
            <div class="modal-header"><h5 class="modal-title">Void <span id="voidLabel"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="small text-muted">The entry stays on the account, struck through. Voiding an invoice for a moved bill puts the amount back on the guest's bill.</p>
                <label for="v_reason">Reason <span class="text-danger">*</span></label><input name="reason" id="v_reason" class="form-control" maxlength="200" required></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('voidModal'));
    document.querySelectorAll('[data-void]').forEach(b => b.addEventListener('click', () => {
        document.getElementById('voidForm').action = b.dataset.void; document.getElementById('voidLabel').textContent = b.dataset.label; m.show();
    }));
});
</script>
@endcan
@endsection
