@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\PaymentMethod;
    use App\Models\Events\EventItem;
    use App\Models\Events\Hall;
    $e = $event;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
    $paid = $e->paid();
    $billed = $e->billedToCompany();
    $left = $e->balance();
    $open = ! in_array($e->status, ['completed', 'cancelled'], true);
    // Suggest the deposit while it is unpaid, then the rest.
    $suggest = $paid + 0.001 < (float) $e->deposit_due ? (float) $e->deposit_due - $paid : max(0, $left);
    $next = ['enquiry' => ['tentative' => 'Hold as provisional', 'confirmed' => 'Confirm'], 'tentative' => ['confirmed' => 'Confirm', 'enquiry' => 'Release the hall'], 'confirmed' => ['completed' => 'Mark as done', 'tentative' => 'Back to provisional'], 'completed' => ['confirmed' => 'Reopen'], 'cancelled' => ['enquiry' => 'Reopen as enquiry']][$e->status] ?? [];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$e->title" icon="ri-presentation-line" :back="route('events.index')" back-label="Events & Halls" :subtitle="$e->when . ' · ' . $e->hall->name">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-flag-line"></i> {{ $e->status_label }}</span>
            <span class="cb-meta-pill"><i class="ri-hashtag"></i> {{ $e->number }}</span>
            <span class="cb-meta-pill"><i class="ri-group-line"></i> {{ $e->guests_expected }} guests{{ $e->setup ? ' · ' . strtolower(strtok(Hall::SETUPS[$e->setup][0], ' ')) : '' }}</span>
            <span class="cb-meta-pill"><i class="ri-bookmark-line"></i> {{ $e->type_label }}</span>
        </x-slot:pills>
        <x-slot:actions>
            @can('Manage events')
                @foreach ($next as $to => $label)
                    <form method="POST" action="{{ route('events.status', $e) }}">@csrf<input type="hidden" name="status" value="{{ $to }}"><button class="cb-hero-btn" type="submit">{{ $label }}</button></form>
                @endforeach
                @if ($open)<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="ri-close-circle-line"></i> Cancel</button>@endif
                <a class="cb-hero-btn" href="{{ route('events.edit', $e) }}"><i class="ri-pencil-line"></i> Edit</a>
            @endcan
            <a class="cb-hero-btn" href="{{ route('events.sheet', $e) }}" target="_blank"><i class="ri-printer-line"></i> Event sheet</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($e->status === 'cancelled')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $e->cancelled_at?->format('D j M Y') }}: {{ $e->cancel_reason }}@if ($paid > 0.004) — {{ $fmt($paid) }} was paid; refund it or keep it, per your policy.@endif</div></div>@endif
    @if ($e->status === 'enquiry')<div class="cb-banner info"><i class="ri-information-line"></i><div>An enquiry doesn't hold the hall. Make it provisional or confirm it to keep the date.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Bill" icon="ri-file-list-3-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table">
                        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th><th></th></tr></thead>
                        <tbody>
                            <tr><td>Hall hire — {{ $e->hall->name }} <span class="small text-muted">({{ ['hourly' => 'by the hour', 'half_day' => 'half day', 'full_day' => 'full day', 'custom' => 'agreed price'][$e->hall_rate_type] ?? '' }})</span></td><td class="num">1</td><td class="num">{{ $fmt($e->hall_charge) }}</td><td class="num">{{ $fmt($e->hall_charge) }}</td><td></td></tr>
                            @if ($e->package_id)<tr><td>{{ $e->package?->name ?? 'Package' }}@if ($e->package?->description)<div class="small text-muted">{{ $e->package->description }}</div>@endif</td><td class="num">{{ $e->guests_expected }}</td><td class="num">{{ $fmt($e->package_price) }}</td><td class="num">{{ $fmt($e->package_total) }}</td><td></td></tr>@endif
                            @foreach ($e->items as $it)
                                <tr><td>{{ $it->description }} <span class="small text-muted">· {{ EventItem::KINDS[$it->kind] ?? '' }}</span></td><td class="num">{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }}</td><td class="num">{{ $fmt($it->unit_price) }}</td><td class="num">{{ $fmt($it->amount) }}</td>
                                    <td class="text-end">@can('Manage events')@if ($open)<form method="POST" action="{{ route('events.items.destroy', [$e, $it]) }}" onsubmit="return confirm('Remove this line?')">@csrf @method('DELETE')<button class="action-btn btn-more" title="Remove"><i class="ri-delete-bin-6-line"></i></button></form>@endif @endcan</td></tr>
                            @endforeach
                            @if ((float) $e->discount > 0)<tr><td>Discount</td><td></td><td></td><td class="num text-success">{{ $fmt(-$e->discount) }}</td><td></td></tr>@endif
                        </tbody>
                    </table>
                </div>
                <div class="p-3">
                    <div class="ms-auto" style="max-width: 320px">
                        <div class="d-flex justify-content-between small"><span>Subtotal</span><span>{{ $fmt($e->subtotal) }}</span></div>
                        <div class="d-flex justify-content-between small text-muted"><span>Tax{{ config('hotel.prices_include_tax') ? ' (included)' : '' }}</span><span>{{ $fmt($e->tax_amount) }}</span></div>
                        <div class="d-flex justify-content-between fw-bold border-top mt-1 pt-1" style="color:var(--cb-heading)"><span>Total</span><span>{{ $fmt($e->total) }}</span></div>
                    </div>
                    @can('Manage events')
                        @if ($open)
                            <form method="POST" action="{{ route('events.items.store', $e) }}" class="row g-2 align-items-end mt-3 border-top pt-3 rm-form">@csrf
                                <div class="col-md-2"><label class="small">Kind</label><select name="kind" class="form-select form-select-sm">@foreach (EventItem::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="small">Extra</label><input name="description" class="form-control form-control-sm" maxlength="150" required placeholder="e.g. Projector, bottled water, PA system"></div>
                                <div class="col-4 col-md-2"><label class="small">Qty</label><input type="number" step="0.01" min="0.01" name="quantity" class="form-control form-control-sm" value="1" required></div>
                                <div class="col-8 col-md-2"><label class="small">Price each</label><input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" required></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="ri-add-line"></i> Add</button></div>
                            </form>
                        @endif
                    @endcan
                </div>
            </x-cb.card>

            <x-cb.card title="Payments" icon="ri-wallet-3-line" :flush="true">
                <x-slot:tools>
                    @can('Receive payments')<button type="button" class="action-btn btn-go" data-bs-toggle="modal" data-bs-target="#payModal"><i class="ri-bank-card-line"></i> Receive payment</button>@endcan
                    @can('Bill to company')@if ($left > 0.004)<button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#companyModal"><i class="ri-building-2-line"></i> Bill to company</button>@endif @endcan
                </x-slot:tools>
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <tbody>
                            @forelse ($e->payments as $p)
                                <tr style="{{ $p->isVoided() ? 'opacity:.5' : '' }}">
                                    <td data-label="Date" class="small">{{ $p->received_at->format('D j M Y, g:i A') }}<div class="text-muted">{{ $p->number }} · {{ $p->receiver?->name }}</div></td>
                                    <td data-label="How" class="small">{{ $p->kind === 'refund' ? 'Refund' : 'Payment' }} by {{ $p->method->label() }}{{ $p->reference ? ' · ' . $p->reference : '' }}@if ($p->isVoided())<div class="text-danger">Voided: {{ $p->void_reason }}</div>@endif</td>
                                    <td data-label="Amount" class="num fw-semibold" style="{{ $p->isVoided() ? 'text-decoration:line-through' : '' }}">{{ $fmt($p->signed()) }}</td>
                                    <td class="text-end">@can('Receive payments')@if (! $p->isVoided())<button type="button" class="action-btn btn-more" data-void="{{ route('events.payments.void', [$e, $p]) }}" data-label="{{ $p->number }}"><i class="ri-close-circle-line"></i></button>@endif @endcan</td>
                                </tr>
                            @empty
                                <tr><td class="text-muted">No payments yet.</td></tr>
                            @endforelse
                            @foreach ($invoices as $inv)
                                <tr><td class="small">{{ $inv->entry_date->format('D j M Y') }}<div class="text-muted">{{ $inv->number }}</div></td><td class="small">Billed to <a href="{{ route('companies.show', $inv->company) }}">{{ $inv->company->name }}</a></td><td class="num fw-semibold">{{ $fmt($inv->amount) }}</td><td></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>

            @include('events.partials.tickets')
        </div>

        <div class="col-xl-4">
            @include('events.partials.day')
            <x-cb.card title="Money" icon="ri-money-dollar-circle-line">
                <div class="d-flex justify-content-between small py-1"><span>Total</span><strong>{{ $fmt($e->total) }}</strong></div>
                <div class="d-flex justify-content-between small py-1"><span>Paid</span><span>{{ $fmt($paid) }}</span></div>
                @if ($billed > 0)<div class="d-flex justify-content-between small py-1"><span>Billed to company</span><span>{{ $fmt($billed) }}</span></div>@endif
                <div class="d-flex justify-content-between border-top mt-1 pt-2 fw-bold" style="color: {{ $left > 0.004 ? '#b91c1c' : 'var(--cb-heading)' }}"><span>{{ $left < -0.004 ? 'Paid over' : 'Left to pay' }}</span><span>{{ $fmt(abs($left)) }}</span></div>
                @if ((float) $e->deposit_due > 0)
                    <div class="small mt-2 {{ $paid + 0.001 >= (float) $e->deposit_due ? 'text-success' : 'text-warning-emphasis' }}"><i class="ri-hand-coin-line"></i> Deposit {{ $fmt($e->deposit_due) }}: {{ $paid + 0.001 >= (float) $e->deposit_due ? 'paid' : 'not paid yet' }}</div>
                @endif
            </x-cb.card>
            <x-cb.card title="Organiser" icon="ri-user-voice-line">
                <div class="small"><strong>{{ $e->organiser_name }}</strong>
                    @if ($e->organiser_phone)<div><a href="tel:{{ preg_replace('/[^0-9+]/', '', $e->organiser_phone) }}">{{ $e->organiser_phone }}</a></div>@endif
                    @if ($e->organiser_email)<div><a href="mailto:{{ $e->organiser_email }}">{{ $e->organiser_email }}</a></div>@endif
                    @if ($e->company)<div class="mt-1"><i class="ri-building-2-line"></i> @can('View company accounts')<a href="{{ route('companies.show', $e->company) }}">{{ $e->company->name }}</a>@else {{ $e->company->name }} @endcan</div>@endif
                </div>
            </x-cb.card>
            @if ($e->requirements)<x-cb.card title="What the client wants" icon="ri-list-check-2"><div class="small" style="white-space:pre-line">{{ $e->requirements }}</div></x-cb.card>@endif
            @if ($e->internal_notes)<x-cb.card title="Notes for staff" icon="ri-sticky-note-line"><div class="small" style="white-space:pre-line">{{ $e->internal_notes }}</div></x-cb.card>@endif
            <div class="small text-muted">Booked by {{ $e->creator?->name ?? '—' }}, {{ $e->created_at->format('j M Y') }}{{ $e->confirmed_at ? ' · confirmed ' . $e->confirmed_at->format('j M Y') : '' }}</div>
        </div>
    </div>

</div>
</div>
</div>

@can('Receive payments')
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.payments.store', $e) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Payment for {{ $e->number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="kind" value="payment" checked> Payment</label><label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="kind" value="refund"> Refund</label></div>
                <div class="col-sm-6"><label for="ep_amount">Amount</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0.01" name="amount" id="ep_amount" class="form-control" required value="{{ $suggest > 0.004 ? number_format($suggest, 2, '.', '') : '' }}"></div></div>
                <div class="col-sm-6"><label for="ep_method">Method</label><select name="method" id="ep_method" class="form-select">@foreach (PaymentMethod::takeable() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="ep_reference">Reference</label><input name="reference" id="ep_reference" class="form-control" maxlength="100"></div>
                @if ($bankAccounts->isNotEmpty())<div class="col-sm-6"><label for="ep_bank">Into account</label><select name="bank_account_id" id="ep_bank" class="form-select"><option value="">—</option>@foreach ($bankAccounts as $b)<option value="{{ $b->id }}">{{ $b->bank_name }} · {{ $b->account_number }}</option>@endforeach</select></div>@endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Record</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" id="voidForm">@csrf
            <div class="modal-header"><h5 class="modal-title">Void <span id="voidLabel"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><label for="v_reason">Reason</label><input name="reason" id="v_reason" class="form-control" maxlength="200" required></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('voidModal'));
    document.querySelectorAll('[data-void]').forEach(b => b.addEventListener('click', () => { document.getElementById('voidForm').action = b.dataset.void; document.getElementById('voidLabel').textContent = b.dataset.label; m.show(); }));
});
</script>
@endcan
@can('Bill to company')
<div class="modal fade" id="companyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.to-company', $e) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Bill to a company</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($companies->isEmpty())
                    <div class="col-12 small text-muted">No company accounts yet.</div>
                @else
                    <div class="col-12"><label for="ec_company">Company</label><select name="company_id" id="ec_company" class="form-select">@foreach ($companies as $c)<option value="{{ $c->id }}" @selected($e->company_id === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                    <div class="col-sm-6"><label for="ec_amount">Amount</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0.01" name="amount" id="ec_amount" class="form-control" required value="{{ number_format(max(0, $left), 2, '.', '') }}"></div></div>
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>@if ($companies->isNotEmpty())<button class="btn btn-primary">Bill</button>@endif</div>
        </form>
    </div>
</div>
@endcan
@can('Manage events')
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.status', $e) }}">@csrf
            <input type="hidden" name="status" value="cancelled">
            <div class="modal-header"><h5 class="modal-title">Cancel {{ $e->title }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="small text-muted">The hall is freed. Payments stay recorded: refund them from Payments if your policy says so.</p><label for="c_reason">Reason</label><input name="reason" id="c_reason" class="form-control" maxlength="255" required></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger">Cancel the event</button></div>
        </form>
    </div>
</div>
@endcan
@endsection
