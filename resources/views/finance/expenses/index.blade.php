@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Finance\Expense;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $base = array_filter(['month' => $month->format('Y-m'), 'category' => $categoryId, 'department' => $department, 'q' => $q]);
    $canPay = auth()->user()->can('Pay expenses');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-wallet-3-line" :subtitle="$month->format('F Y') . ' · running costs: power, diesel, repairs, supplies, services. Stock bought for the bar, kitchen and stores is recorded in Inventory, not here.'">
        <x-slot:actions>
            @can('Record expenses')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#expenseModal"><i class="ri-add-line"></i> Record expense</button>@endcan
            @can('Manage expense categories')<a class="cb-hero-btn" href="{{ route('expenses.categories.index') }}"><i class="ri-folder-settings-line"></i> Categories &amp; budgets</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-xl-3"><x-cb.stat :label="'Spent in ' . $month->format('F')" :value="$fmt($stats['month'])" icon="ri-wallet-3-line" accent="teal" :hint="$stats['budget'] > 0 ? round($stats['month'] / $stats['budget'] * 100) . '% of ' . $fmt($stats['budget']) . ' budget' : 'approved & paid'" /></div>
        <div class="col-6 col-xl-3"><a class="text-decoration-none" href="{{ route('expenses.index', ['status' => 'pending']) }}"><x-cb.stat label="Waiting for approval" :value="$stats['pending']" icon="ri-time-line" accent="amber" :hint="$fmt($stats['pending_sum']) . ($stats['to_approve'] ? ' · ' . $stats['to_approve'] . ' for you' : '')" /></a></div>
        <div class="col-6 col-xl-3"><a class="text-decoration-none" href="{{ route('expenses.index', ['status' => 'approved']) }}"><x-cb.stat label="Approved, not paid" :value="$stats['unpaid']" icon="ri-hand-coin-line" accent="rose" :hint="$fmt($stats['unpaid_sum']) . ' to pay'" /></a></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Approval limit" :value="$fmt(config('hotel.expenses.approval_limit'))" icon="ri-shield-check-line" accent="green" hint="above this needs someone else's approval" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <input type="month" name="month" class="cb-select" value="{{ $month->format('Y-m') }}" aria-label="Month">
                    <select name="status" class="cb-select" aria-label="Status"><option value="">All (this month)</option><option value="open" @selected($status === 'open')>To approve or pay (any month)</option>@foreach (Expense::STATUSES as $k => [$label])<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach</select>
                    <select name="category" class="cb-select" aria-label="Category"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected($categoryId === $c->id)>{{ $c->name }}</option>@endforeach</select>
                    <select name="department" class="cb-select" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d }}" @selected($department === $d)>{{ $d }}</option>@endforeach</select>
                    <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="What, payee, number…" aria-label="Search"></div>
                    <button class="action-btn btn-open"><i class="ri-filter-3-line"></i> Show</button>
                </form>
                @if ($expenses->isEmpty())
                    <div class="empty-state py-5"><i class="ri-wallet-3-line"></i><h6>No expenses here</h6>
                        @if ($categories->isEmpty())<p class="small text-muted">Set up expense categories first.</p>@endif</div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Expense</th><th>Category</th><th>Paid to</th><th>Status</th><th class="num">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($expenses as $x)
                                    <tr style="{{ in_array($x->status, ['void', 'rejected']) ? 'opacity:.55' : '' }}">
                                        <td data-label="Expense"><a href="{{ route('expenses.show', $x) }}" class="fw-semibold text-reset">{{ $x->description }}</a>
                                            <div class="small text-muted">{{ $x->number }} · {{ $x->expense_date->format('j M Y') }}{{ $x->department ? ' · ' . $x->department : '' }}{{ $seeAll && $x->recorder ? ' · ' . $x->recorder->name : '' }}
                                                @if ($x->attachments)<i class="ri-attachment-2" title="{{ count($x->attachments) }} receipt(s)"></i>@endif</div></td>
                                        <td data-label="Category" class="small">{{ $x->category?->name }}</td>
                                        <td data-label="Paid to" class="small">{{ $x->payee_name ?: '—' }}</td>
                                        <td data-label="Status"><span class="status-pill {{ $x->status_pill }}">{{ $x->status_label }}</span></td>
                                        <td data-label="Amount" class="num fw-semibold">{{ $fmt($x->amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $expenses->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <x-cb.card :title="$month->format('F') . ' against budget'" icon="ri-pie-chart-2-line">
                @forelse ($budget as $b)
                    @php $p = $b->budget ? $b->spent / $b->budget * 100 : null; @endphp
                    <div class="py-2 border-bottom">
                        <div class="d-flex justify-content-between small"><a href="{{ route('expenses.index', ['month' => $month->format('Y-m'), 'category' => $b->category->id]) }}" class="text-reset fw-semibold">{{ $b->category->name }}</a>
                            <span>{{ $fmt($b->spent) }}@if ($b->budget)<span class="text-muted"> / {{ $fmt($b->budget) }}</span>@endif</span></div>
                        @if ($p !== null)<div class="progress-track mt-1"><div class="progress-fill" style="width: {{ min(100, $p) }}%; {{ $p > 100 ? 'background:#dc2626' : ($p > 85 ? 'background:#f59e0b' : '') }}"></div></div>
                            @if ($p > 100)<div class="small text-danger">{{ $fmt($b->spent - $b->budget) }} over budget</div>@endif @endif
                    </div>
                @empty
                    <div class="small text-muted">Nothing spent this month.</div>
                @endforelse
            </x-cb.card>
            @if ($seeAll)
                <x-cb.card title="By department" icon="ri-building-2-line">
                    @forelse ($byDepartment as $d => $v)
                        <div class="d-flex justify-content-between py-1 border-bottom small"><a class="text-reset" href="{{ route('expenses.index', ['month' => $month->format('Y-m'), 'department' => $d === 'Not given' ? null : $d]) }}">{{ $d }}</a><strong>{{ $fmt($v) }}</strong></div>
                    @empty
                        <div class="small text-muted">—</div>
                    @endforelse
                    @canany(['View finance reports', 'View management reports'])<a href="{{ route('reports.advanced', ['report' => 'profit', 'from' => $month->toDateString(), 'to' => $month->copy()->endOfMonth()->min(today())->toDateString()]) }}" class="action-btn btn-more mt-2"><i class="ri-funds-line"></i> Profit &amp; loss</a>@endcanany
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>

@can('Record expenses')
<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('expenses.store') }}" enctype="multipart/form-data" data-once>@csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-wallet-3-line me-1"></i> Record an expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                @include('finance.expenses._form')
                <div class="col-12"><label for="x_files">Receipts / invoices</label><input type="file" name="attachments[]" id="x_files" class="form-control" multiple accept="image/*,application/pdf"><div class="form-text">Up to 5 photos or PDFs, 5 MB each. On a phone you can take a picture of the receipt.</div></div>
                @if ($canPay)
                    <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="pay_now" value="1" id="x_paynow" @checked(old('pay_now'))> <span class="form-check-label">Already paid — record the payment now <span class="text-muted">(only if within the approval limit)</span></span></label></div>
                    <div class="row g-3 m-0 p-0" id="x_paybox" style="display:none">@include('finance.expenses._pay')</div>
                @endif
            </div>
            <div class="modal-footer"><span class="small text-muted me-auto">Over {{ $fmt(config('hotel.expenses.approval_limit')) }} goes to someone else for approval.</span><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
@include('finance.expenses._scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const box = document.getElementById('x_paybox'), cb = document.getElementById('x_paynow');
    if (cb) { const s = () => box.style.display = cb.checked ? '' : 'none'; cb.addEventListener('change', s); s(); }
    if (location.hash === '#new' || {{ $errors->any() ? 'true' : 'false' }}) bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseModal')).show();
});
</script>
@endcan
@endsection
