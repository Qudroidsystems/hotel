@php $sym = config('hotel.currency_symbol'); @endphp
<div id="tr-board" data-new="{{ $requested->pluck('id')->implode(',') }}" data-map='@json($map)'>
    <div class="row g-3">
        <div class="col-xl-5">
            <h6 class="tr-h">New bookings <span class="cb-count">{{ $requested->count() }}</span></h6>
            @forelse ($requested as $r)
                <article class="tr-ride st-requested {{ ! $r->pickup_at || $r->pickup_at->lt(now()->addHour()) ? 'soon' : '' }}">
                    <header>
                        <span class="tr-when">{{ $r->pickup_at ? $r->pickup_at->format('H:i') : 'NOW' }}<small>{{ $r->pickup_at && ! $r->pickup_at->isToday() ? $r->pickup_at->format('D j M') : '' }}</small></span>
                        <div class="flex-grow-1 min-w-0">
                            <strong>{{ $r->toHotel() ? 'Pick up: ' : 'To: ' }}{{ $r->place_label }}</strong>
                            <div class="small text-muted">{{ $r->number }} · {{ $r->guest_name }} · {{ $r->passengers }} pax{{ $r->bags ? ', ' . $r->bags . ' bags' : '' }}{{ $r->flight ? ' · flight ' . $r->flight : '' }}{{ $r->reservation ? ' · ' . $r->reservation->reference : '' }}</div>
                            @if ($r->notes)<div class="small tr-note">“{{ $r->notes }}”</div>@endif
                        </div>
                        <span class="fw-semibold small text-nowrap">{{ $r->fare !== null ? $sym . number_format((float) $r->fare, 0) : 'No price' }}</span>
                    </header>
                    <form method="POST" action="{{ route('transport.rides.assign', $r) }}" class="tr-assign" data-once>@csrf
                        <select name="driver_id" class="form-select form-select-sm" required aria-label="Driver">
                            <option value="">Driver…</option>
                            @foreach ($candidates[$r->id] ?? [] as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}{{ $d->on_duty ? ($d->free ? ' · free' : ' · busy') : ' · off duty' }}{{ $d->km_away !== null ? ' · ' . $d->km_away . ' km' : '' }}{{ $d->vehicle ? ' · ' . $d->vehicle->plate : '' }}</option>
                            @endforeach
                        </select>
                        <select name="vehicle_id" class="form-select form-select-sm" aria-label="Car">
                            <option value="">Their usual car</option>
                            @foreach ($vehicles->where('is_active', true)->where('status', 'available') as $v)<option value="{{ $v->id }}">{{ $v->label }}</option>@endforeach
                        </select>
                        <input type="number" name="fare" class="form-control form-control-sm" min="0" step="100" placeholder="{{ $r->fare !== null ? number_format((float) $r->fare, 0, '.', '') : 'Fare' }}" aria-label="Fare" style="max-width:110px">
                        <button class="action-btn btn-go"><i class="ri-steering-2-line"></i> Assign</button>
                    </form>
                    <div class="d-flex gap-2 mt-2">
                        <form method="POST" action="{{ route('transport.rides.auto', $r) }}" data-once>@csrf<button class="action-btn btn-more"><i class="ri-magic-line"></i> Nearest free driver</button></form>
                        <form method="POST" action="{{ route('transport.rides.cancel', $r) }}" class="d-flex gap-1 flex-grow-1" data-once>@csrf
                            <input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="Reason, to cancel">
                            <button class="action-btn btn-more" title="Cancel the ride"><i class="ri-close-line"></i></button></form>
                    </div>
                </article>
            @empty
                <div class="empty-state py-4"><i class="ri-taxi-line"></i><h6>No new bookings</h6><p class="small text-muted mb-0">Guests book from the guest app or their booking page; you can book for them with “Book a car”.</p></div>
            @endforelse
        </div>
        <div class="col-xl-7">
            <div id="tr-map" class="tr-map" aria-label="Map of drivers and pick-ups"></div>
            <h6 class="tr-h mt-3">Under way <span class="cb-count">{{ $active->count() }}</span></h6>
            @forelse ($active as $r)
                <article class="tr-ride st-{{ $r->status }}">
                    <header>
                        <span class="tr-when">{{ $r->pickup_at ? $r->pickup_at->format('H:i') : 'NOW' }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <strong>{{ $r->route_label }}</strong>
                            <div class="small text-muted">{{ $r->number }} · {{ $r->guest_name }}{{ $r->flight ? ' · flight ' . $r->flight : '' }}</div>
                            <div class="small"><i class="ri-steering-2-line"></i> {{ $r->driver?->name }} · {{ $r->vehicle?->label }}{{ $r->eta_minutes !== null && in_array($r->status, ['on_the_way', 'on_trip'], true) ? ' · ~' . $r->eta_minutes . ' min' : '' }}</div>
                        </div>
                        <span class="status-pill {{ $r->status_pill }}">{{ $r->staff_label }}</span>
                    </header>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        @php $next = ['assigned' => ['start', 'Driving to pick-up'], 'on_the_way' => ['arrived', 'At pick-up'], 'arrived' => ['picked_up', 'Picked up'], 'on_trip' => ['complete', 'Dropped off']][$r->status] ?? null; @endphp
                        @if ($next)<form method="POST" action="{{ route('transport.rides.status', [$r, $next[0]]) }}" data-once>@csrf<button class="action-btn btn-more">{{ $next[1] }}</button></form>@endif
                        @if ($r->status === 'assigned')
                            <form method="POST" action="{{ route('transport.rides.cancel', $r) }}" class="d-flex gap-1" data-once>@csrf<input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="Reason, to cancel"><button class="action-btn btn-more"><i class="ri-close-line"></i></button></form>
                        @endif
                    </div>
                </article>
            @empty
                <p class="small text-muted">No car is out right now.</p>
            @endforelse
        </div>
    </div>
    @if ($done->isNotEmpty())
        <h6 class="tr-h mt-3">Earlier today</h6>
        <div class="table-responsive"><table class="cb-table stack">
            <thead><tr><th>Ride</th><th>Route</th><th>Driver</th><th class="num">Fare</th><th>Status</th></tr></thead>
            <tbody>@foreach ($done as $r)<tr>
                <td data-label="Ride" class="small">{{ $r->number }}<div class="text-muted">{{ $r->updated_at->format('g:i A') }}</div></td>
                <td data-label="Route" class="small">{{ $r->route_label }}<div class="text-muted">{{ $r->guest_name }}</div></td>
                <td data-label="Driver" class="small">{{ $r->driver?->name ?? '—' }}</td>
                <td data-label="Fare" class="num">{{ $r->fare !== null ? $sym . number_format((float) $r->fare, 0) : '—' }}</td>
                <td data-label="Status"><span class="status-pill {{ $r->status_pill }}">{{ $r->staff_label }}</span>@if ($r->cancel_reason)<div class="small text-muted">{{ $r->cancel_reason }}</div>@endif</td>
            </tr>@endforeach</tbody>
        </table></div>
    @endif
</div>
