@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Rates\RatePlan;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 0);
    $short = fn ($v) => $v >= 1000000 ? rtrim(rtrim(number_format($v / 1000000, 2), '0'), '.') . 'm' : ($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k' : number_format($v));
@endphp
<style>
    .rt-grid { border-collapse: separate; border-spacing: 0; font-size: 12px; }
    .rt-grid th, .rt-grid td { padding: 6px 4px; text-align: center; border-bottom: 1px solid var(--cb-border); min-width: 46px; }
    .rt-grid th.type, .rt-grid td.type { position: sticky; left: 0; background: var(--cb-surface, #fff); text-align: left; min-width: 150px; z-index: 1; font-weight: 700; color: var(--cb-heading); padding-left: 12px; }
    .rt-grid thead th { font-weight: 600; color: var(--cb-muted, #64748b); }
    .rt-grid thead th.we { color: var(--cb-heading); }
    .rt-grid td.cell { font-variant-numeric: tabular-nums; position: relative; }
    .rt-grid td.cell.own { font-weight: 800; color: var(--cb-heading); text-decoration: underline dotted; }
    .rt-grid td.cell.closed { background: repeating-linear-gradient(135deg, rgba(220,38,38,.07) 0 6px, transparent 6px 12px); color: #b91c1c; }
    .rt-grid td.cell .min { display: block; font-size: 10px; color: #8a5f1c; font-weight: 700; }
    .rt-grid td.cell .bar { position: absolute; left: 2px; right: 2px; bottom: 0; height: 3px; border-radius: 2px; }
    .rt-grid tr.today-col td { }
    .rt-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: var(--cb-muted, #64748b); }
    .rt-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .rt-sw { width: 14px; height: 10px; border-radius: 3px; display: inline-block; }
    .rt-days label { margin-right: 10px; font-size: 13px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Rates & Seasons" icon="ri-price-tag-3-line" subtitle="How rooms are priced: rate plans, seasons and the nightly rate calendar. Changes apply to new bookings; bookings already made keep their prices.">
        <x-slot:actions>
            @if ($tab === 'plans')<a class="cb-hero-btn" href="{{ route('rates.plans.create') }}"><i class="ri-add-line"></i> New rate plan</a>@endif
            @if ($tab === 'seasons')<button type="button" class="cb-hero-btn" data-season="new"><i class="ri-add-line"></i> New season</button>@endif
            @can('Manage room types')<a class="cb-hero-btn" href="{{ route('room-types.index') }}"><i class="ri-hotel-bed-line"></i> Room types &amp; base rates</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="rs-tabs">
        <a href="{{ route('rates.index', ['tab' => 'plans']) }}" class="rs-tab {{ $tab === 'plans' ? 'active' : '' }}"><i class="ri-price-tag-3-line"></i> Rate plans</a>
        <a href="{{ route('rates.index', ['tab' => 'seasons']) }}" class="rs-tab {{ $tab === 'seasons' ? 'active' : '' }}"><i class="ri-sun-cloudy-line"></i> Seasons</a>
        <a href="{{ route('rates.index', ['tab' => 'calendar']) }}" class="rs-tab {{ $tab === 'calendar' ? 'active' : '' }}"><i class="ri-calendar-2-line"></i> Rate calendar</a>
    </div>

    {{-- ── Plans ─────────────────────────────────────────── --}}
    @if ($tab === 'plans')
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card :flush="true">
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Rate plan</th><th>Price</th><th>Sold</th><th class="num">Bookings</th><th></th></tr></thead>
                            <tbody>
                                <tr>
                                    <td data-label="Rate plan" class="fw-semibold">Standard rate<div class="small text-muted fw-normal">The room type's rate, moved by seasons and the rate calendar.</div></td>
                                    <td data-label="Price" class="small">Room type rate</td>
                                    <td data-label="Sold" class="small">Front desk · Online</td>
                                    <td data-label="Bookings" class="num">—</td><td></td>
                                </tr>
                                @foreach ($plans as $p)
                                    <tr style="{{ $p->is_active ? '' : 'opacity:.55' }}">
                                        <td data-label="Rate plan" class="fw-semibold">{{ $p->name }} <span class="small text-muted fw-normal">{{ $p->code }}</span>
                                            @unless ($p->is_active) <span class="status-pill st-muted">Off</span>@endunless
                                            @if ($p->non_refundable) <span class="status-pill st-warning">Non-refundable</span>@endif
                                            @if ($p->company) <span class="status-pill st-info">{{ $p->company->name }}</span>@endif
                                            @if ($p->inclusions)<div class="small text-muted fw-normal">{{ $p->inclusions }}</div>@endif
                                            @if ($p->valid_from || $p->valid_to)<div class="small text-muted fw-normal">Arrivals {{ $p->valid_from ? 'from ' . $p->valid_from->format('j M Y') : '' }} {{ $p->valid_to ? 'to ' . $p->valid_to->format('j M Y') : '' }}</div>@endif
                                        </td>
                                        <td data-label="Price" class="small">{{ $p->summary }}@if (! empty($p->room_type_ids))<div class="text-muted">{{ $types->whereIn('id', $p->room_type_ids)->pluck('name')->implode(', ') }} only</div>@endif</td>
                                        <td data-label="Sold" class="small">{{ $p->channels_label }}</td>
                                        <td data-label="Bookings" class="num">{{ $used[$p->id] ?? 0 }}</td>
                                        <td class="text-end text-nowrap">
                                            <a class="action-btn btn-more" href="{{ route('rates.plans.edit', $p) }}" title="Edit"><i class="ri-pencil-line"></i></a>
                                            <form method="POST" action="{{ route('rates.plans.destroy', $p) }}" class="d-inline" onsubmit="return confirm('{{ ($used[$p->id] ?? 0) ? 'This plan has bookings, so it will be switched off.' : 'Delete this rate plan?' }}')">@csrf @method('DELETE')<button class="action-btn btn-more" title="{{ ($used[$p->id] ?? 0) ? 'Switch off' : 'Delete' }}"><i class="ri-delete-bin-6-line"></i></button></form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Price checker" icon="ri-calculator-line">
                    <form id="checker" class="row g-2">
                        <div class="col-12"><label class="small">Room type</label><select class="form-select form-select-sm" name="type">@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="small">Arrive</label><input type="date" class="form-control form-control-sm" name="in" value="{{ today()->toDateString() }}"></div>
                        <div class="col-6"><label class="small">Leave</label><input type="date" class="form-control form-control-sm" name="out" value="{{ today()->addDays(2)->toDateString() }}"></div>
                        <div class="col-12"><label class="small">Rate</label><select class="form-select form-select-sm" name="plan"><option value="">Standard rate</option>@foreach ($plans->where('is_active', true) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                    </form>
                    <div id="checkOut" class="small mt-3 text-muted">Pick dates to see the price night by night.</div>
                </x-cb.card>
                <x-cb.card title="How prices are worked out" icon="ri-information-line">
                    <ol class="small ps-3 mb-0">
                        <li class="mb-1">Each night starts at the room type's rate (weekend rate on Friday and Saturday nights).</li>
                        <li class="mb-1">A season covering the night moves it up or down. A price set on the rate calendar replaces both.</li>
                        <li class="mb-1">A rate plan then takes its discount, adds its extra (e.g. breakfast), or uses its own fixed price.</li>
                        <li>Online, closed nights and minimum stays are enforced; at the front desk they show as warnings.</li>
                    </ol>
                </x-cb.card>
            </div>
        </div>
    @endif

    {{-- ── Seasons ───────────────────────────────────────── --}}
    @if ($tab === 'seasons')
        <x-cb.card :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Season</th><th>Dates</th><th>Price change</th><th>Room types</th><th>Online minimum stay</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($seasons as $s)
                            @php $past = $s->ends_on->lt(today()); $now = $s->covers(today()->toDateString()); @endphp
                            <tr style="{{ $s->is_active && ! $past ? '' : 'opacity:.55' }}">
                                <td data-label="Season" class="fw-semibold"><span class="rt-sw" style="background: {{ $s->color }}"></span> {{ $s->name }}
                                    @if ($now && $s->is_active) <span class="status-pill st-success">Now</span>@elseif ($past) <span class="status-pill st-muted">Past</span>@endif
                                    @unless ($s->is_active) <span class="status-pill st-muted">Off</span>@endunless</td>
                                <td data-label="Dates" class="small">{{ $s->starts_on->format('D j M Y') }} – {{ $s->ends_on->format('D j M Y') }}<div class="text-muted">{{ $s->starts_on->diffInDays($s->ends_on) + 1 }} nights</div></td>
                                <td data-label="Price change" class="fw-semibold {{ ((float) $s->change_percent + (float) $s->change_amount) < 0 ? 'text-success' : '' }}">{{ $s->change_label }}</td>
                                <td data-label="Room types" class="small">{{ empty($s->room_type_ids) ? 'All' : $types->whereIn('id', $s->room_type_ids)->pluck('name')->implode(', ') }}</td>
                                <td data-label="Online minimum stay" class="small">{{ $s->min_nights ? $s->min_nights . ' nights' : '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-season="{{ json_encode(['id' => $s->id, 'name' => $s->name, 'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString(), 'change_percent' => (float) $s->change_percent, 'change_amount' => (float) $s->change_amount, 'room_type_ids' => $s->room_type_ids ?? [], 'min_nights' => $s->min_nights, 'priority' => $s->priority, 'color' => $s->color, 'is_active' => $s->is_active]) }}" title="Edit"><i class="ri-pencil-line"></i></button>
                                    <form method="POST" action="{{ route('rates.seasons.destroy', $s) }}" class="d-inline" onsubmit="return confirm('Delete this season?')">@csrf @method('DELETE')<button class="action-btn btn-more" title="Delete"><i class="ri-delete-bin-6-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No seasons yet. Add one for peak times (Christmas, Easter, big events in town) or quiet months.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>

        <div class="modal fade" id="seasonModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form class="modal-content rm-form" method="POST" action="{{ route('rates.seasons.store') }}" id="seasonForm">@csrf
                    <input type="hidden" name="_method" value="POST" id="seasonMethod">
                    <div class="modal-header"><h5 class="modal-title" id="seasonTitle">New season</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body row g-3">
                        @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                        <div class="col-md-8"><label for="s_name">Name <span class="text-danger">*</span></label><input name="name" id="s_name" class="form-control" maxlength="100" required placeholder="e.g. Christmas & New Year" value="{{ old('name') }}"></div>
                        <div class="col-md-4"><label for="s_color">Colour</label><input type="color" name="color" id="s_color" class="form-control form-control-color w-100" value="{{ old('color', '#b7832f') }}"></div>
                        <div class="col-md-6"><label for="s_starts_on">First night <span class="text-danger">*</span></label><input type="date" name="starts_on" id="s_starts_on" class="form-control" required value="{{ old('starts_on') }}"></div>
                        <div class="col-md-6"><label for="s_ends_on">Last night <span class="text-danger">*</span></label><input type="date" name="ends_on" id="s_ends_on" class="form-control" required value="{{ old('ends_on') }}"></div>
                        <div class="col-md-6"><label for="s_change_percent">Price change (%)</label><div class="input-group"><input type="number" step="0.01" min="-90" max="500" name="change_percent" id="s_change_percent" class="form-control" value="{{ old('change_percent', 0) }}"><span class="input-group-text">%</span></div><div class="small text-muted">25 = a quarter dearer; −10 = 10% cheaper.</div></div>
                        <div class="col-md-6"><label for="s_change_amount">…and/or per night</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" name="change_amount" id="s_change_amount" class="form-control" value="{{ old('change_amount', 0) }}"></div><div class="small text-muted">Negative for less.</div></div>
                        <div class="col-md-6"><label for="s_min_nights">Online minimum stay</label><input type="number" min="1" max="60" name="min_nights" id="s_min_nights" class="form-control" value="{{ old('min_nights') }}" placeholder="None"><div class="small text-muted">For online guests arriving during the season.</div></div>
                        <div class="col-md-6"><label for="s_priority">Priority</label><input type="number" min="-100" max="100" name="priority" id="s_priority" class="form-control" value="{{ old('priority', 0) }}"><div class="small text-muted">If seasons overlap, the higher number wins.</div></div>
                        <div class="col-12"><label>Room types</label><div class="d-flex flex-wrap gap-3">@foreach ($types as $t)<label class="form-check mb-0"><input type="checkbox" class="form-check-input s-type" name="room_type_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, old('room_type_ids', [])))> <span class="form-check-label">{{ $t->name }}</span></label>@endforeach</div><div class="small text-muted">None ticked = every room type.</div></div>
                        <div class="col-12" id="s_active_wrap"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="s_is_active" checked> <span class="form-check-label">In use</span></label></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save season</button></div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── Calendar ──────────────────────────────────────── --}}
    @if ($tab === 'calendar')
        <x-cb.card :flush="true">
            <x-slot:tools>
                <a class="btn btn-sm btn-light" href="{{ route('rates.index', ['tab' => 'calendar', 'month' => $month->copy()->subMonth()->format('Y-m')]) }}"><i class="ri-arrow-left-s-line"></i></a>
                <strong>{{ $month->format('F Y') }}</strong>
                <a class="btn btn-sm btn-light" href="{{ route('rates.index', ['tab' => 'calendar', 'month' => $month->copy()->addMonth()->format('Y-m')]) }}"><i class="ri-arrow-right-s-line"></i></a>
            </x-slot:tools>
            <div class="table-responsive">
                <table class="rt-grid">
                    <thead><tr><th class="type">Room type</th>
                        @foreach ($days as $d)<th class="{{ $d->isFriday() || $d->isSaturday() ? 'we' : '' }}" @if ($d->isToday()) style="background: rgba(15,118,110,.1)" @endif>{{ $d->format('D') }}<br>{{ $d->day }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($types as $t)
                            <tr><td class="type">{{ $t->name }}<div class="small text-muted fw-normal">{{ $fmt($t->base_rate) }}{{ $t->weekend_rate ? ' · wknd ' . $fmt($t->weekend_rate) : '' }}</div></td>
                                @foreach ($days as $d)
                                    @php $c = $grid[$t->id][$d->toDateString()]; $e = $c['entry']; @endphp
                                    <td class="cell {{ $e?->price !== null ? 'own' : '' }} {{ $e?->closed ? 'closed' : '' }}" title="{{ $d->format('D j M') }}: {{ $fmt($c['price']) }}{{ $c['season'] && $e?->price === null ? ' (' . $c['season']->name . ')' : '' }}{{ $e?->closed ? ' · closed online' : '' }}{{ $e?->min_nights ? ' · min ' . $e->min_nights . ' nights' : '' }}{{ $e?->note ? ' · ' . $e->note : '' }}">
                                        {{ $short($c['price']) }}
                                        @if ($e?->min_nights)<span class="min">{{ $e->min_nights }}n+</span>@endif
                                        @if ($c['season'] && $e?->price === null)<span class="bar" style="background: {{ $c['season']->color }}"></span>@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3 rt-legend">
                <span><span class="rt-sw" style="background:#b7832f"></span> Coloured line: a season moves the price</span>
                <span><strong style="text-decoration: underline dotted">45k</strong> Price set on this calendar</span>
                <span><span class="rt-sw" style="background: repeating-linear-gradient(135deg, rgba(220,38,38,.3) 0 3px, transparent 3px 6px)"></span> Closed online</span>
                <span><strong style="color:#8a5f1c">3n+</strong> Online minimum stay for arrivals that day</span>
            </div>
        </x-cb.card>

        <x-cb.card title="Change nights" icon="ri-edit-2-line">
            <form method="POST" action="{{ route('rates.calendar.update') }}" class="row g-3 rm-form">@csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                <div class="col-md-3"><label for="c_from">From night</label><input type="date" name="from" id="c_from" class="form-control" required value="{{ old('from', $month->copy()->max(today())->toDateString()) }}"></div>
                <div class="col-md-3"><label for="c_to">To night</label><input type="date" name="to" id="c_to" class="form-control" required value="{{ old('to', $month->copy()->endOfMonth()->toDateString()) }}"></div>
                <div class="col-md-6"><label>Only on</label><div class="rt-days">@foreach (RatePlan::DAYS as $n => $label)<label><input type="checkbox" class="form-check-input" name="weekdays[]" value="{{ $n }}" @checked(in_array($n, old('weekdays', [])))> {{ $label }}</label>@endforeach</div><div class="small text-muted">None ticked = every day.</div></div>
                <div class="col-12"><label>Room types</label><div class="d-flex flex-wrap gap-3">@foreach ($types as $t)<label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="room_type_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, old('room_type_ids', $types->pluck('id')->all())))> <span class="form-check-label">{{ $t->name }}</span></label>@endforeach</div></div>
                <div class="col-md-4"><label for="c_action">Change</label>
                    <select name="action" id="c_action" class="form-select">
                        <option value="price">Set the price per night</option>
                        <option value="clear_price">Back to the normal price</option>
                        <option value="close">Close online (stop selling)</option>
                        <option value="open">Open online again</option>
                        <option value="min_nights">Set an online minimum stay</option>
                        <option value="clear_min">Remove the minimum stay</option>
                        <option value="clear_all">Clear every change</option>
                    </select></div>
                <div class="col-md-3" data-for="price"><label for="c_price">Price per night</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="price" id="c_price" class="form-control" value="{{ old('price') }}"></div></div>
                <div class="col-md-3 d-none" data-for="min_nights"><label for="c_min">Minimum nights</label><input type="number" min="1" max="60" name="min_nights" id="c_min" class="form-control" value="{{ old('min_nights', 2) }}"></div>
                <div class="col-md-5"><label for="c_note">Note (optional)</label><input type="text" name="note" id="c_note" class="form-control" maxlength="150" placeholder="e.g. Lagos marathon weekend" value="{{ old('note') }}"></div>
                <div class="col-12"><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i> Apply to these nights</button></div>
            </form>
        </x-cb.card>
    @endif

</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const SYM = @json($sym);
    const money = n => SYM + Number(n || 0).toLocaleString('en-NG', { maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Price checker
    const checker = document.getElementById('checker'), out = document.getElementById('checkOut');
    if (checker) {
        const run = async () => {
            const qs = new URLSearchParams(new FormData(checker));
            if (!qs.get('in') || !qs.get('out') || qs.get('out') <= qs.get('in')) { out.textContent = 'Leave must be after arrive.'; return; }
            try {
                const r = await fetch(@json(route('rates.check')) + '?' + qs, { headers: { Accept: 'application/json' } });
                const j = await r.json();
                if (!r.ok) throw new Error(j.message || 'Could not price that.');
                out.innerHTML = j.nights.map(n => `<div class="d-flex justify-content-between"><span>${esc(n.date)}${n.season ? ' <span class="text-muted">· ' + esc(n.season) + '</span>' : ''}</span><span>${money(n.price)}</span></div>`).join('')
                    + `<div class="d-flex justify-content-between border-top mt-2 pt-2 fw-bold" style="color:var(--cb-heading)"><span>Total</span><span>${money(j.total)}</span></div>`
                    + j.problems.map(p => `<div class="text-danger mt-1"><i class="ri-forbid-line"></i> ${esc(p)}</div>`).join('')
                    + (j.online ? `<div class="text-warning-emphasis mt-1"><i class="ri-error-warning-line"></i> Online: ${esc(j.online)}</div>` : '');
            } catch (e) { out.textContent = e.message; }
        };
        checker.addEventListener('change', run); run();
    }

    // Season modal
    const sForm = document.getElementById('seasonForm');
    if (sForm) {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('seasonModal'));
        const storeUrl = @json(route('rates.seasons.store')), updateUrl = @json(route('rates.seasons.update', '__ID__'));
        document.querySelectorAll('[data-season]').forEach(b => b.addEventListener('click', () => {
            const s = b.dataset.season === 'new' ? null : JSON.parse(b.dataset.season);
            sForm.reset(); sForm.action = s ? updateUrl.replace('__ID__', s.id) : storeUrl;
            document.getElementById('seasonMethod').value = s ? 'PUT' : 'POST';
            document.getElementById('seasonTitle').textContent = s ? 'Edit ' + s.name : 'New season';
            document.getElementById('s_active_wrap').style.display = s ? '' : 'none';
            sForm.querySelectorAll('.s-type').forEach(c => c.checked = !!s && s.room_type_ids.map(Number).includes(+c.value));
            if (s) {
                ['name', 'starts_on', 'ends_on', 'change_percent', 'change_amount', 'min_nights', 'priority', 'color'].forEach(k => document.getElementById('s_' + k).value = s[k] ?? '');
                document.getElementById('s_is_active').checked = !!s.is_active;
            }
            modal.show();
        }));
        @if ($errors->any() && $tab === 'seasons') modal.show(); @endif
    }

    // Calendar form: show the box the chosen change needs.
    const action = document.getElementById('c_action');
    if (action) {
        const sync = () => document.querySelectorAll('[data-for]').forEach(el => el.classList.toggle('d-none', el.dataset.for !== action.value));
        action.addEventListener('change', sync); sync();
    }
});
</script>
@endsection
