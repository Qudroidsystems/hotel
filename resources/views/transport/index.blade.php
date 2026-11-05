@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Transport\RidePlace;
    use App\Models\Transport\Vehicle;
    $sym = config('hotel.currency_symbol');
@endphp
<style>
    .tr-h { font-size: 13px; font-weight: 700; color: var(--cb-muted); margin: 4px 2px 10px; }
    .tr-ride { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-left: 5px solid var(--tr, #94a3b8); border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; }
    .tr-ride header { display: flex; gap: 12px; align-items: flex-start; }
    .tr-ride.st-requested { --tr: #d97706; } .tr-ride.st-requested.soon { box-shadow: 0 0 0 3px rgba(217,119,6,.18); }
    .tr-ride.st-assigned { --tr: #0891b2; } .tr-ride.st-on_the_way, .tr-ride.st-arrived { --tr: #4f46e5; } .tr-ride.st-on_trip { --tr: #15803d; }
    .tr-when { min-width: 54px; text-align: center; font-weight: 800; font-size: 16px; background: #0f2342; color: #fff; border-radius: 9px; padding: 5px 6px; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .tr-when small { display: block; font-size: 10px; font-weight: 600; opacity: .8; }
    .tr-note { color: #b45309; font-weight: 600; }
    .tr-assign { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
    .tr-assign select { flex: 1 1 160px; }
    .tr-map { height: 300px; border-radius: 12px; border: 1px solid var(--cb-border); background: #e5e7eb; z-index: 0; }
    .tr-form .form-label { font-size: 12.5px; }
    .min-w-0 { min-width: 0; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Car Service" icon="ri-taxi-line" subtitle="Guests book the hotel's cars from the guest app or their booking page — picked up anywhere and brought here, or taken out. Assign a driver and car; drivers work their rides from the staff app and guests follow them live.">
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="ri-add-line"></i> Book a car</button>
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'transport']) }}"><i class="ri-money-dollar-circle-line"></i> Fares</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($drivers->where('is_active', true)->isEmpty())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>Add your <strong>cars</strong> and <strong>drivers</strong> first — guests can book once there is a driver. Link each driver to a staff login so they can use Driver mode in the staff app.</div></div>
    @endif

    <div class="rs-tabs">
        <a href="{{ route('transport.index') }}" class="rs-tab {{ $tab === 'rides' ? 'active' : '' }}"><i class="ri-route-line"></i> Rides @if ($requested->count())<span class="cb-count">{{ $requested->count() }}</span>@endif</a>
        <a href="{{ route('transport.index', ['tab' => 'drivers']) }}" class="rs-tab {{ $tab === 'drivers' ? 'active' : '' }}"><i class="ri-steering-2-line"></i> Drivers <span class="cb-count">{{ $drivers->where('on_duty', true)->count() }} on duty</span></a>
        <a href="{{ route('transport.index', ['tab' => 'cars']) }}" class="rs-tab {{ $tab === 'cars' ? 'active' : '' }}"><i class="ri-car-line"></i> Cars</a>
        <a href="{{ route('transport.index', ['tab' => 'places']) }}" class="rs-tab {{ $tab === 'places' ? 'active' : '' }}"><i class="ri-map-pin-line"></i> Places &amp; fares</a>
    </div>

    @if ($tab === 'rides')
        <div id="tr-wrap">@include('transport.partials.board')</div>
    @elseif ($tab === 'drivers')
        <div class="row g-3">
            <div class="col-xl-8"><x-cb.card :flush="true"><div class="table-responsive"><table class="cb-table stack">
                <thead><tr><th>Driver</th><th>Car</th><th>Staff login</th><th>Duty</th><th></th></tr></thead>
                <tbody>@forelse ($drivers as $d)
                    <tr style="{{ $d->is_active ? '' : 'opacity:.55' }}">
                        <td data-label="Driver"><span class="fw-semibold">{{ $d->name }}</span><div class="small text-muted">{{ $d->phone }}{{ $d->licence_number ? ' · ' . $d->licence_number : '' }}</div>
                            @if ($d->licenceExpired())<span class="status-pill st-danger">Licence expired</span>@elseif ($d->licence_expires && $d->licence_expires->lt(today()->addMonth()))<span class="status-pill st-warning">Licence expires {{ $d->licence_expires->format('j M') }}</span>@endif</td>
                        <td data-label="Car" class="small">{{ $d->vehicle?->label ?? '—' }}</td>
                        <td data-label="Staff login" class="small">{{ $d->user?->name ?? 'Not linked' }}</td>
                        <td data-label="Duty">@if ($d->on_duty)<span class="status-pill st-success">On duty</span>@if ($d->seen_at)<div class="small text-muted">seen {{ $d->seen_at->diffForHumans(short: true) }}</div>@endif @else<span class="status-pill st-muted">Off</span>@endif</td>
                        <td class="text-end"><a class="action-btn btn-more" href="{{ route('transport.index', ['tab' => 'drivers', 'edit' => $d->id]) }}"><i class="ri-pencil-line"></i></a></td>
                    </tr>
                @empty <tr><td colspan="5" class="text-muted">No drivers yet.</td></tr> @endforelse</tbody>
            </table></div></x-cb.card></div>
            <div class="col-xl-4">
                @php $e = $drivers->firstWhere('id', (int) request('edit')); @endphp
                <x-cb.card :title="$e ? 'Edit ' . $e->name : 'Add a driver'" icon="ri-steering-2-line">
                    <form method="POST" action="{{ $e ? route('transport.drivers.update', $e) : route('transport.drivers.store') }}" class="tr-form">@csrf @if ($e) @method('PUT') @endif
                        <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" required maxlength="100" value="{{ old('name', $e?->name) }}"></div>
                        <div class="mb-2"><label class="form-label">Phone (guests see it during the ride)</label><input name="phone" class="form-control" required maxlength="30" value="{{ old('phone', $e?->phone) }}"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label">Licence number</label><input name="licence_number" class="form-control" maxlength="40" value="{{ old('licence_number', $e?->licence_number) }}"></div>
                            <div class="col-5"><label class="form-label">Expires</label><input type="date" name="licence_expires" class="form-control" value="{{ old('licence_expires', $e?->licence_expires?->toDateString()) }}"></div>
                        </div>
                        <div class="mb-2"><label class="form-label">Usual car</label><select name="vehicle_id" class="form-select"><option value="">—</option>@foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $e?->vehicle_id) == $v->id)>{{ $v->label }}</option>@endforeach</select></div>
                        <div class="mb-2"><label class="form-label">Staff login (for Driver mode in the staff app)</label><select name="user_id" class="form-select"><option value="">Not linked</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected(old('user_id', $e?->user_id) == $u->id)>{{ $u->name }} · {{ $u->email }}</option>@endforeach</select></div>
                        <div class="form-check mb-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="d-act" @checked(old('is_active', $e?->is_active ?? true))><label class="form-check-label small" for="d-act">Works for the hotel</label></div>
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-save-3-line"></i> {{ $e ? 'Save' : 'Add driver' }}</button>
                    </form>
                </x-cb.card>
            </div>
        </div>
    @elseif ($tab === 'cars')
        <div class="row g-3">
            <div class="col-xl-8"><x-cb.card :flush="true"><div class="table-responsive"><table class="cb-table stack">
                <thead><tr><th>Car</th><th>Kind</th><th class="num">Seats</th><th>Status</th><th></th></tr></thead>
                <tbody>@forelse ($vehicles as $v)
                    <tr style="{{ $v->is_active ? '' : 'opacity:.55' }}">
                        <td data-label="Car"><span class="fw-semibold">{{ $v->name }}</span> <span class="rs-ref">{{ $v->plate }}</span><div class="small text-muted">{{ $v->color }}{{ $v->notes ? ' · ' . $v->notes : '' }}</div></td>
                        <td data-label="Kind" class="small">{{ Vehicle::KINDS[$v->kind] ?? $v->kind }}</td>
                        <td data-label="Seats" class="num">{{ $v->seats }}</td>
                        <td data-label="Status">{!! $v->status === 'available' ? '<span class="status-pill st-success">Available</span>' : '<span class="status-pill st-danger">Out of service</span>' !!}
                            @foreach ($v->expiredPapers() as $p)<span class="status-pill st-danger">{{ $p }} ran out</span>@endforeach
                            @foreach ($v->papersEnding() as $p)<span class="status-pill st-warning">{{ $p }}</span>@endforeach
                            @if ($due = $v->serviceDue())<span class="status-pill st-warning">{{ $due }}</span>@endif
                            <div class="small text-muted">{{ $v->odometer_km !== null ? number_format($v->odometer_km) . ' km' : '' }}{{ $v->checked_at ? ' · checked ' . $v->checked_at->diffForHumans(short: true) : '' }}</div></td>
                        <td class="text-end"><a class="action-btn btn-more" href="{{ route('transport.index', ['tab' => 'cars', 'edit' => $v->id]) }}"><i class="ri-pencil-line"></i></a></td>
                    </tr>
                @empty <tr><td colspan="5" class="text-muted">No cars yet.</td></tr> @endforelse</tbody>
            </table></div></x-cb.card></div>
            <div class="col-xl-4">
                @php $e = $vehicles->firstWhere('id', (int) request('edit')); @endphp
                <x-cb.card :title="$e ? 'Edit ' . $e->name : 'Add a car'" icon="ri-car-line">
                    <form method="POST" action="{{ $e ? route('transport.cars.update', $e) : route('transport.cars.store') }}" class="tr-form">@csrf @if ($e) @method('PUT') @endif
                        <div class="mb-2"><label class="form-label">Make and model</label><input name="name" class="form-control" required maxlength="80" placeholder="e.g. Toyota Camry" value="{{ old('name', $e?->name) }}"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label">Plate</label><input name="plate" class="form-control text-uppercase" required maxlength="20" value="{{ old('plate', $e?->plate) }}"></div>
                            <div class="col-6"><label class="form-label">Colour</label><input name="color" class="form-control" maxlength="30" value="{{ old('color', $e?->color) }}"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label">Kind</label><select name="kind" class="form-select">@foreach (Vehicle::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $e?->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
                            <div class="col-5"><label class="form-label">Seats</label><input type="number" name="seats" class="form-control" min="1" max="60" value="{{ old('seats', $e?->seats ?? 4) }}"></div>
                        </div>
                        <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="available">Available</option><option value="out_of_service" @selected(old('status', $e?->status) === 'out_of_service')>Out of service</option></select></div>
                        <div class="mb-2"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255" value="{{ old('notes', $e?->notes) }}"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label">Insurance until</label><input type="date" name="insurance_expires" class="form-control" value="{{ old('insurance_expires', $e?->insurance_expires?->toDateString()) }}"></div>
                            <div class="col-6"><label class="form-label">Roadworthy until</label><input type="date" name="roadworthy_expires" class="form-control" value="{{ old('roadworthy_expires', $e?->roadworthy_expires?->toDateString()) }}"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-4"><label class="form-label">Km now</label><input type="number" name="odometer_km" class="form-control" min="0" value="{{ old('odometer_km', $e?->odometer_km) }}"></div>
                            <div class="col-4"><label class="form-label">Service every</label><input type="number" name="service_every_km" class="form-control" min="0" placeholder="km" value="{{ old('service_every_km', $e?->service_every_km) }}"></div>
                            <div class="col-4"><label class="form-label">Last at</label><input type="number" name="last_service_km" class="form-control" min="0" placeholder="km" value="{{ old('last_service_km', $e?->last_service_km) }}"></div>
                        </div>
                        <div class="mb-2"><label class="form-label">Next service by</label><input type="date" name="service_due_on" class="form-control" value="{{ old('service_due_on', $e?->service_due_on?->toDateString()) }}"></div>
                        <div class="form-check mb-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="v-act" @checked(old('is_active', $e?->is_active ?? true))><label class="form-check-label small" for="v-act">In the fleet</label></div>
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-save-3-line"></i> {{ $e ? 'Save' : 'Add car' }}</button>
                    </form>
                </x-cb.card>
                @if ($e)
                    <x-cb.card title="Fuel, service or a problem" icon="ri-gas-station-line" class="mt-3">
                        <form method="POST" action="{{ route('transport.cars.log', $e) }}" enctype="multipart/form-data" class="tr-form">@csrf
                            <div class="mb-2"><select name="kind" class="form-select"><option value="fuel">Fuel</option><option value="service">Service / repair</option><option value="issue">Problem</option></select></div>
                            <div class="row g-2 mb-2">
                                <div class="col-4"><input type="number" step="0.1" name="litres" class="form-control" placeholder="Litres"></div>
                                <div class="col-4"><input type="number" step="0.01" name="cost" class="form-control" placeholder="Cost"></div>
                                <div class="col-4"><input type="number" name="km" class="form-control" placeholder="Km now"></div>
                            </div>
                            <div class="mb-2"><input name="notes" class="form-control" maxlength="500" placeholder="What was done / what's wrong"></div>
                            <div class="mb-2"><label class="form-label small">Next service by (after a service)</label><input type="date" name="next_service_on" class="form-control"></div>
                            <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="back_in_service" value="1" id="v-back"><label class="form-check-label small" for="v-back">Back on the road (after a repair)</label></div>
                            <div class="mb-3"><input type="file" name="photo" accept="image/*" class="form-control form-control-sm"></div>
                            <button class="action-btn btn-primary-cb w-100"><i class="ri-add-line"></i> Log it</button>
                        </form>
                        @php $logs = $e->logs()->with('driver:id,name', 'user:id,name')->limit(12)->get(); @endphp
                        @if ($logs->isNotEmpty())
                            <ul class="list-unstyled small mt-3 mb-0">@foreach ($logs as $l)
                                <li class="mb-2"><strong>{{ $l->kind_label }}</strong> · {{ $l->created_at->format('j M H:i') }} · {{ $l->driver?->name ?? $l->user?->name }}
                                    {{ $l->odometer_km ? '· ' . number_format($l->odometer_km) . ' km' : '' }}{{ $l->litres ? ' · ' . $l->litres . ' L' : '' }}{{ $l->cost ? ' · ' . $sym . number_format((float) $l->cost, 0) : '' }}
                                    @if ($l->failed)<span class="status-pill st-warning">{{ implode(', ', $l->failed) }}</span>@endif
                                    @if ($l->notes)<div class="text-muted">{{ $l->notes }}</div>@endif</li>
                            @endforeach</ul>
                        @endif
                    </x-cb.card>
                @endif
            </div>
        </div>
    @else
        <div class="row g-3">
            <div class="col-xl-8"><x-cb.card :flush="true"><div class="table-responsive"><table class="cb-table stack">
                <thead><tr><th>Place</th><th>Kind</th><th class="num">Set fare</th><th></th></tr></thead>
                <tbody>@forelse ($places as $p)
                    <tr style="{{ $p->is_active ? '' : 'opacity:.55' }}">
                        <td data-label="Place" class="fw-semibold">{{ $p->name }}@if ($p->latitude === null)<div class="small text-muted fw-normal">Not on the map</div>@endif</td>
                        <td data-label="Kind" class="small">{{ RidePlace::KINDS[$p->kind] ?? $p->kind }}</td>
                        <td data-label="Set fare" class="num">{{ $p->fare !== null ? $sym . number_format((float) $p->fare, 0) : 'by distance' }}</td>
                        <td class="text-end"><a class="action-btn btn-more" href="{{ route('transport.index', ['tab' => 'places', 'edit' => $p->id]) }}"><i class="ri-pencil-line"></i></a></td>
                    </tr>
                @empty <tr><td colspan="4" class="text-muted">No places yet. Add the airport, bus parks and places guests often go.</td></tr> @endforelse</tbody>
            </table></div></x-cb.card>
            <p class="small text-muted">Anywhere else is priced by distance from the hotel: {{ $sym }}{{ number_format($settings['base_fare']) }} + {{ $sym }}{{ number_format($settings['per_km']) }}/km, at least {{ $sym }}{{ number_format($settings['minimum_fare']) }}. Guests who type an address without a map position get “price confirmed by the hotel”: set it when you assign the driver.</p></div>
            <div class="col-xl-4">
                @php $e = $places->firstWhere('id', (int) request('edit')); @endphp
                <x-cb.card :title="$e ? 'Edit ' . $e->name : 'Add a place'" icon="ri-map-pin-line">
                    <form method="POST" action="{{ $e ? route('transport.places.update', $e) : route('transport.places.store') }}" class="tr-form">@csrf @if ($e) @method('PUT') @endif
                        <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" required maxlength="80" placeholder="e.g. Murtala Muhammed Airport (LOS)" value="{{ old('name', $e?->name) }}"></div>
                        <div class="mb-2"><label class="form-label">Kind</label><select name="kind" class="form-select">@foreach (RidePlace::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $e?->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label">Latitude</label><input name="latitude" class="form-control" inputmode="decimal" value="{{ old('latitude', $e?->latitude) }}"></div>
                            <div class="col-6"><label class="form-label">Longitude</label><input name="longitude" class="form-control" inputmode="decimal" value="{{ old('longitude', $e?->longitude) }}"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label">Set fare (empty = by distance)</label><input type="number" name="fare" class="form-control" min="0" step="100" value="{{ old('fare', $e?->fare !== null ? (float) $e->fare : '') }}"></div>
                            <div class="col-5"><label class="form-label">Order</label><input type="number" name="sort_order" class="form-control" min="0" value="{{ old('sort_order', $e?->sort_order ?? 0) }}"></div>
                        </div>
                        <div class="form-check mb-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="p-act" @checked(old('is_active', $e?->is_active ?? true))><label class="form-check-label small" for="p-act">Offered to guests</label></div>
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-save-3-line"></i> {{ $e ? 'Save' : 'Add place' }}</button>
                    </form>
                </x-cb.card>
            </div>
        </div>
    @endif

</div>
</div>
</div>

<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('transport.rides.store') }}">@csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-taxi-line me-1"></i> Book a car for a guest</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label>Guest's booking</label><select name="reservation_id" class="form-select" required>
                    <option value="">Choose…</option>
                    @foreach ($stays as $s)<option value="{{ $s->id }}">{{ $s->guest?->full_name }} · {{ $s->reference }} · {{ $s->check_in_date->format('j M') }}{{ $s->rooms->pluck('room.number')->filter()->isNotEmpty() ? ' · room ' . $s->rooms->pluck('room.number')->filter()->implode(', ') : '' }}</option>@endforeach
                </select></div>
                <div class="col-12"><label>Trip</label><select name="direction" class="form-select"><option value="to_hotel">Pick up and bring to the hotel</option><option value="from_hotel">From the hotel</option></select></div>
                <div class="col-sm-6"><label>Place</label><select name="place_id" class="form-select"><option value="">Another address…</option>@foreach ($places->where('is_active', true) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label>Address (if not a place)</label><input name="label" class="form-control" maxlength="255"></div>
                <div class="col-sm-6"><label>When (empty = now)</label><input type="datetime-local" name="pickup_at" class="form-control"></div>
                <div class="col-3"><label>People</label><input type="number" name="passengers" class="form-control" min="1" max="20" value="1"></div>
                <div class="col-3"><label>Bags</label><input type="number" name="bags" class="form-control" min="0" max="30" value="0"></div>
                <div class="col-sm-4"><label>Flight</label><input name="flight" class="form-control text-uppercase" maxlength="20"></div>
                <div class="col-sm-4"><label>Fare (optional)</label><input type="number" name="fare" class="form-control" min="0" step="100"></div>
                <div class="col-sm-4"><label>Paid</label><select name="payment" class="form-select"><option value="room">On the bill</option><option value="cash">Cash</option><option value="card">Card</option></select></div>
                <div class="col-12"><label>Notes for the driver</label><input name="notes" class="form-control" maxlength="300"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Book</button></div>
        </form>
    </div>
</div>

@if ($tab === 'rides')
<script>
(() => {
    // The map library loads on its own, after the page: a slow or blocked map never holds up the board.
    const loadMap = () => {
    const css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    const js = document.createElement('script');
    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; js.async = true;
    js.onload = () => draw();
    js.onerror = () => { const el = document.getElementById('tr-map'); if (el) el.innerHTML = '<div class="p-3 small text-muted">The map couldn’t load (no internet?). Everything else works.</div>'; };
    document.head.appendChild(js);
    };
    // After the page has loaded, so the map never holds the page up.
    if (document.readyState === 'complete') loadMap(); else window.addEventListener('load', loadMap);
    const wrap = document.getElementById('tr-wrap');
    let map = null, layer = null;
    function draw() {
        const board = document.getElementById('tr-board');
        const el = document.getElementById('tr-map');
        if (!board || !el || !window.L) return;
        const data = JSON.parse(board.dataset.map || '{}');
        if (!map || !document.body.contains(map.getContainer())) {
            map = L.map(el, { scrollWheelZoom: false });
            const tiles = @json(app(\App\Services\Maps\MapsService::class)->tiles());
            L.tileLayer(tiles.tiles, { maxZoom: tiles.max_zoom, attribution: tiles.attribution }).addTo(map);
            layer = L.layerGroup().addTo(map);
        }
        layer.clearLayers();
        const pts = [];
        const dot = (color, label) => L.divIcon({ className: '', html: `<div style="background:${color};color:#fff;border-radius:12px;padding:2px 7px;font:600 11px system-ui;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.3)">${label}</div>` });
        if (data.hotel) { L.marker(data.hotel, { icon: dot('#b7832f', '🏨 Hotel') }).addTo(layer); pts.push(data.hotel); }
        (data.drivers || []).forEach(d => { L.marker([d.lat, d.lng], { icon: dot('#15803d', '🚗 ' + d.name) }).bindTooltip(d.name + ' · ' + d.seen).addTo(layer); pts.push([d.lat, d.lng]); });
        (data.pickups || []).forEach(p => { L.marker([p.lat, p.lng], { icon: dot('#d97706', p.number) }).bindTooltip(p.label + ' · ' + p.status).addTo(layer); pts.push([p.lat, p.lng]); });
        if (pts.length > 1) map.fitBounds(pts, { padding: [30, 30], maxZoom: 14 }); else if (pts.length) map.setView(pts[0], 13); else map.setView([6.45, 3.4], 11);
    }
    let known = new Set((document.getElementById('tr-board')?.dataset.new || '').split(',').filter(Boolean));
    setInterval(async () => {
        if (document.activeElement && wrap.contains(document.activeElement) && ['INPUT', 'SELECT'].includes(document.activeElement.tagName)) return;
        try {
            const res = await fetch(@json(route('transport.index', ['partial' => 1])), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok || res.redirected) return;
            const mapEl = document.getElementById('tr-map');
            wrap.innerHTML = await res.text();
            // Keep the same map: move it into the new board.
            const slot = document.getElementById('tr-map');
            if (map && slot && mapEl) { slot.replaceWith(mapEl); }
            draw();
            const now = (document.getElementById('tr-board')?.dataset.new || '').split(',').filter(Boolean);
            if (now.some(id => !known.has(id))) document.title = '● New car booking — Car Service';
            known = new Set(now);
        } catch (e) {}
    }, 20000);
})();
</script>
@endif
@endsection
