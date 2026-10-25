@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Events\Event;
    use App\Models\Events\Hall;
    $e = $event;
    $sym = config('hotel.currency_symbol');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$e->exists ? $e->title : 'New event'" icon="ri-presentation-line"
               :back="$e->exists ? route('events.show', $e) : route('events.index')" :back-label="$e->exists ? $e->number : 'Events & Halls'"
               subtitle="The hall, the time, how many guests, and what they get. The price is worked out from the hall's rates and the package." />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ $e->exists ? route('events.update', $e) : route('events.store') }}" class="rm-form" id="evForm">
        @csrf @if ($e->exists) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="The event" icon="ri-calendar-event-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label for="title">Event name <span class="text-danger">*</span></label><input name="title" id="title" class="form-control" maxlength="150" required value="{{ old('title', $e->title) }}" placeholder="e.g. Okafor–Adeyemi wedding reception"></div>
                        <div class="col-md-4"><label for="event_type">Kind</label><select name="event_type" id="event_type" class="form-select">@foreach (Event::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('event_type', $e->event_type) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label for="hall_id">Hall <span class="text-danger">*</span></label>
                            <select name="hall_id" id="hall_id" class="form-select" required>
                                @foreach ($halls as $h)<option value="{{ $h->id }}" data-rates="{{ json_encode(['hourly' => (float) $h->hourly_rate, 'half_day' => (float) $h->half_day_rate, 'full_day' => (float) $h->full_day_rate]) }}" data-caps="{{ json_encode(collect(Hall::SETUPS)->map(fn ($s) => $h->{$s[1]})) }}" @selected((string) old('hall_id', $e->hall_id) === (string) $h->id)>{{ $h->name }} — {{ $h->capacity_label }}</option>@endforeach
                            </select></div>
                        <div class="col-md-3"><label for="setup">Layout</label><select name="setup" id="setup" class="form-select"><option value="">—</option>@foreach (Hall::SETUPS as $k => $s)<option value="{{ $k }}" @selected(old('setup', $e->setup) === $k)>{{ $s[0] }}</option>@endforeach</select></div>
                        <div class="col-md-3"><label for="guests_expected">Guests <span class="text-danger">*</span></label><input type="number" min="1" max="20000" name="guests_expected" id="guests_expected" class="form-control" required value="{{ old('guests_expected', $e->guests_expected ?: '') }}"><div class="small text-danger" id="capNote"></div></div>
                        <div class="col-md-3"><label for="date">Date <span class="text-danger">*</span></label><input type="date" name="date" id="date" class="form-control" required value="{{ old('date', $e->starts_at?->toDateString()) }}"></div>
                        <div class="col-md-3"><label for="start_time">From <span class="text-danger">*</span></label><input type="time" name="start_time" id="start_time" class="form-control" required value="{{ old('start_time', $e->starts_at?->format('H:i')) }}"></div>
                        <div class="col-md-3"><label for="end_date">Until (date)</label><input type="date" name="end_date" id="end_date" class="form-control" value="{{ old('end_date', $e->ends_at && $e->starts_at && ! $e->ends_at->isSameDay($e->starts_at) ? $e->ends_at->toDateString() : '') }}"><div class="small text-muted">Only for events over several days.</div></div>
                        <div class="col-md-3"><label for="end_time">Until <span class="text-danger">*</span></label><input type="time" name="end_time" id="end_time" class="form-control" required value="{{ old('end_time', $e->ends_at?->format('H:i')) }}"></div>
                        <div class="col-12"><label for="requirements">What the client wants (on the event sheet)</label><textarea name="requirements" id="requirements" class="form-control" rows="4" maxlength="5000" placeholder="Room layout, projector and 2 microphones, high table for 8, tea break at 11:00, lunch at 13:00, 3 vegetarian meals…">{{ old('requirements', $e->requirements) }}</textarea></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Organiser" icon="ri-user-voice-line">
                    <div class="row g-3">
                        @if ($companies->isNotEmpty())<div class="col-12"><label for="company_id">Company (billed to its account if needed)</label><select name="company_id" id="company_id" class="form-select"><option value="">Private client</option>@foreach ($companies as $c)<option value="{{ $c->id }}" @selected((string) old('company_id', $e->company_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach</select></div>@endif
                        <div class="col-md-4"><label for="organiser_name">Contact name <span class="text-danger">*</span></label><input name="organiser_name" id="organiser_name" class="form-control" maxlength="120" required value="{{ old('organiser_name', $e->organiser_name) }}"></div>
                        <div class="col-md-4"><label for="organiser_phone">Phone</label><input name="organiser_phone" id="organiser_phone" class="form-control" maxlength="40" value="{{ old('organiser_phone', $e->organiser_phone) }}"></div>
                        <div class="col-md-4"><label for="organiser_email">Email</label><input type="email" name="organiser_email" id="organiser_email" class="form-control" maxlength="150" value="{{ old('organiser_email', $e->organiser_email) }}"></div>
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Price" icon="ri-money-dollar-circle-line">
                    <label for="hall_rate_type">Hall hire</label>
                    <select name="hall_rate_type" id="hall_rate_type" class="form-select mb-2">
                        @foreach (['full_day' => 'Full day rate', 'half_day' => 'Half day rate', 'hourly' => 'By the hour', 'custom' => 'Agreed price'] as $k => $l)<option value="{{ $k }}" @selected(old('hall_rate_type', $e->hall_rate_type) === $k)>{{ $l }}</option>@endforeach
                    </select>
                    <div class="input-group mb-1" id="customWrap"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="hall_charge" id="hall_charge" class="form-control" value="{{ old('hall_charge', (float) $e->hall_charge ?: '') }}"></div>
                    <div class="small text-muted mb-3" id="hallNote"></div>

                    <label for="package_id">Package</label>
                    <select name="package_id" id="package_id" class="form-select mb-2"><option value="" data-price="0">None</option>@foreach ($packages as $p)<option value="{{ $p->id }}" data-price="{{ (float) $p->price_per_person }}" title="{{ $p->description }}" @selected((string) old('package_id', $e->package_id) === (string) $p->id)>{{ $p->name }} — {{ $sym }}{{ number_format((float) $p->price_per_person) }} a head</option>@endforeach</select>
                    <label for="package_price" class="small">Price per guest</label>
                    <div class="input-group mb-3"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="package_price" id="package_price" class="form-control" value="{{ old('package_price', $e->package_id ? (float) $e->package_price : '') }}"></div>

                    <label for="discount">Discount</label>
                    <div class="input-group mb-3"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="{{ old('discount', (float) $e->discount ?: '') }}"></div>
                    <label for="deposit_due">Deposit to hold the date</label>
                    <div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="deposit_due" id="deposit_due" class="form-control" value="{{ old('deposit_due', (float) $e->deposit_due ?: '') }}"></div>
                    <div class="d-flex justify-content-between border-top mt-3 pt-3"><span class="text-muted fw-semibold">Estimate</span><span class="rs-total" id="estimate">—</span></div>
                    <div class="small text-muted">Before extras (drinks, equipment…), which are added on the event.{{ config('hotel.prices_include_tax') ? ' Taxes included.' : ' Taxes are added.' }}</div>
                </x-cb.card>

                @unless ($e->exists)
                    <x-cb.card title="Status" icon="ri-flag-line">
                        @foreach (['enquiry' => ['Enquiry', 'Doesn’t hold the hall.'], 'tentative' => ['Provisional', 'Holds the hall until confirmed or released.'], 'confirmed' => ['Confirmed', 'Booked.']] as $k => [$l, $hint])
                            <label class="form-check mb-1"><input type="radio" class="form-check-input" name="status" value="{{ $k }}" @checked(old('status', 'tentative') === $k)> <span class="form-check-label"><strong>{{ $l }}</strong> <span class="small text-muted">— {{ $hint }}</span></span></label>
                        @endforeach
                    </x-cb.card>
                @endunless
                <x-cb.card title="Notes for staff" icon="ri-sticky-note-line"><textarea name="internal_notes" class="form-control" rows="3" maxlength="1000">{{ old('internal_notes', $e->internal_notes) }}</textarea></x-cb.card>
                <button class="btn btn-primary w-100 py-2 fw-bold"><i class="ri-save-3-line me-1"></i> Save event</button>
            </div>
        </div>
    </form>

</div>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const SYM = @json($sym), $ = id => document.getElementById(id);
    const money = n => SYM + Number(n || 0).toLocaleString('en-NG', { maximumFractionDigits: 2 });
    const hours = () => {
        const s = new Date(($('date').value || '2000-01-01') + 'T' + ($('start_time').value || '00:00'));
        const e = new Date(($('end_date').value || $('date').value || '2000-01-01') + 'T' + ($('end_time').value || '00:00'));
        return Math.max(0, (e - s) / 3600000);
    };
    const days = () => {
        const s = new Date(($('date').value || '2000-01-01') + 'T00:00'), e = new Date(($('end_date').value || $('date').value || '2000-01-01') + 'T00:00');
        return Math.max(1, Math.round((e - s) / 86400000) + 1);
    };
    function update() {
        const opt = $('hall_id').selectedOptions[0]; if (!opt) return;
        const rates = JSON.parse(opt.dataset.rates), caps = JSON.parse(opt.dataset.caps), type = $('hall_rate_type').value;
        $('customWrap').style.display = type === 'custom' ? '' : 'none';
        const hall = type === 'custom' ? +$('hall_charge').value || 0 : type === 'hourly' ? rates.hourly * Math.max(1, Math.ceil(hours())) : rates[type] * days();
        $('hallNote').textContent = type === 'custom' ? '' : money(hall) + (type === 'hourly' ? ' (' + Math.max(1, Math.ceil(hours())) + ' h)' : days() > 1 ? ' (' + days() + ' days)' : '');
        const pp = $('package_price').value !== '' ? +$('package_price').value : +($('package_id').selectedOptions[0]?.dataset.price || 0);
        const guests = +$('guests_expected').value || 0;
        $('estimate').textContent = money(Math.max(0, hall + ($('package_id').value ? pp * guests : 0) - (+$('discount').value || 0)));
        const cap = caps[$('setup').value];
        $('capNote').textContent = cap && guests > cap ? 'This layout takes ' + cap + ' here.' : '';
    }
    $('package_id').addEventListener('change', () => { $('package_price').value = $('package_id').value ? $('package_id').selectedOptions[0].dataset.price : ''; update(); });
    document.getElementById('evForm').addEventListener('input', update);
    document.getElementById('evForm').addEventListener('change', update);
    update();
});
</script>
@endsection
