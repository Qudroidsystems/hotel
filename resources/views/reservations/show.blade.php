@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\ReservationStatus;
    use App\Enums\BookingSource;
    $r = $reservation; $g = $r->guest; $st = $r->status;
    $canCheckIn = $r->isArrivalDue() && $r->check_out_date->gt(today());
    $sym = config('hotel.currency_symbol');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$g->full_name" icon="ri-calendar-check-line"
               :subtitle="$r->check_in_date->format('D j M Y') . ' → ' . $r->check_out_date->format('D j M Y') . ' · ' . $r->nights . ' night' . ($r->nights === 1 ? '' : 's') . ' · ' . $r->rooms->count() . ' room' . ($r->rooms->count() === 1 ? '' : 's')"
               :back="route('reservations.index')" back-label="Front Desk">
        <x-slot:pills>
            <span class="cb-meta-pill" style="background: {{ $st->color() }}; border-color: {{ $st->color() }}"><i class="{{ $st->icon() }}"></i> {{ $st->label() }}</span>
            <span class="cb-meta-pill"><i class="ri-hashtag"></i> {{ $r->reference }}</span>
            <span class="cb-meta-pill"><i class="ri-route-line"></i> {{ $r->channel ?: $r->source->label() }}{{ $r->channel_reference ? ' · ' . $r->channel_reference : '' }}</span>
            @if ($r->rate_plan_name)<span class="cb-meta-pill" title="{{ $r->ratePlan?->summary }}{{ $r->ratePlan?->inclusions ? ' · ' . $r->ratePlan->inclusions : '' }}"><i class="ri-price-tag-3-line"></i> {{ $r->rate_plan_name }}{{ $r->promo_code ? ' · ' . $r->promo_code : '' }}</span>@endif
            @if ($r->company)<a class="cb-meta-pill text-decoration-none" @can('View company accounts') href="{{ route('companies.show', $r->company) }}" @endcan><i class="ri-building-2-line"></i> Bill to {{ $r->company->name }}</a>@endif
            @if ($g->is_vip)<span class="cb-meta-pill"><i class="ri-vip-crown-line"></i> VIP</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if ($st === ReservationStatus::Pending)
                @can('Update reservations')
                    <form method="POST" action="{{ route('reservations.confirm', $r) }}">@csrf<button class="cb-hero-btn" type="submit"><i class="ri-check-double-line"></i> Confirm</button></form>
                @endcan
            @endif
            @if ($canCheckIn)
                @can('Check in guests')
                    <form method="POST" action="{{ route('reservations.check-in', $r) }}">@csrf
                        <button class="cb-hero-btn" type="submit" style="background:#16a34a;border-color:#16a34a" @disabled(! $r->allRoomsAssigned()) title="{{ $r->allRoomsAssigned() ? '' : 'Assign a room to every line first' }}"><i class="ri-login-box-line"></i> Check in</button></form>
                @endcan
            @endif
            @if ($st === ReservationStatus::CheckedIn)
                @can('Check out guests')
                    <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#checkoutModal" style="background:#7c3aed;border-color:#7c3aed"><i class="ri-logout-box-r-line"></i> Check out</button>
                @endcan
            @endif
            <a class="cb-hero-btn" href="{{ route('reservations.print', $r) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Confirmation</a>
            @if ($st->isOpen())
                @can('Update reservations')
                    <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#datesModal"><i class="ri-calendar-event-line"></i> {{ $st === ReservationStatus::CheckedIn ? 'Extend / shorten' : 'Change dates' }}</button>
                @endcan
            @endif
            @if (in_array($st, [ReservationStatus::Pending, ReservationStatus::Confirmed], true))
                @can('Cancel reservations')
                    @if ($r->check_in_date->lte(today()))
                        <form method="POST" action="{{ route('reservations.no-show', $r) }}" onsubmit="return confirm('Mark {{ $g->full_name }} as a no-show? The room(s) will be released.');">@csrf
                            <button class="cb-hero-btn" type="submit"><i class="ri-user-unfollow-line"></i> No-show</button></form>
                    @endif
                    <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="ri-close-circle-line"></i> Cancel</button>
                @endcan
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @php $coming = app(\App\Services\Booking\ArrivalService::class)->forStaff($r); @endphp
    @if ($coming)
        <div class="cb-banner {{ $coming['state'] === 'here' ? 'success' : 'info' }}"><i class="{{ $coming['state'] === 'here' ? 'ri-map-pin-user-fill' : 'ri-navigation-line' }}"></i>
            <div>@if ($coming['state'] === 'here')<strong>The guest is at the hotel</strong> (since {{ $coming['since'] }}) — they shared their arrival from the guest app.
                @else<strong>The guest is on the way</strong> — about {{ str_replace('On the way · ', '', $coming['label']) }} ({{ $coming['distance'] }}), updated {{ $coming['updated'] }}. Have the room ready.@endif</div></div>
    @endif

    @if ($canCheckIn && ! $r->allRoomsAssigned())
        <div class="cb-banner warning"><i class="ri-door-line"></i><div>Assign a room to every line below before checking the guest in.</div></div>
    @endif
    @if ($r->status === ReservationStatus::Cancelled)
        <div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $r->cancelled_at?->format('j M Y, g:i A') }}. Reason: {{ $r->cancel_reason }}</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">

            {{-- Rooms --}}
            <x-cb.card title="Rooms" icon="ri-door-line" :count="$r->rooms->count()" :flush="true" id="rooms">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Booked as</th><th>Room</th><th>Guests</th><th class="num">Charge</th></tr></thead>
                        <tbody>
                            @foreach ($r->rooms as $i => $line)
                                <tr>
                                    <td data-label="Booked as">
                                        <span class="d-inline-flex align-items-center gap-1 fw-semibold"><span style="width:9px;height:9px;border-radius:50%;background:{{ $line->roomType->color }}"></span>{{ $line->roomType->name }}</span>
                                        @if ($line->room && $line->room->room_type_id !== $line->room_type_id)
                                            <div class="small text-muted">in a {{ $line->room->roomType->name }} room</div>
                                        @endif
                                    </td>
                                    <td data-label="Room" style="min-width:260px">
                                        @if ($line->room)
                                            <div class="d-flex align-items-center gap-2">
                                                <a href="{{ route('rooms.show', $line->room) }}" class="fw-bold text-reset">Room {{ $line->room->number }}</a>
                                                <span class="small text-muted">{{ $line->room->floor->name }}</span>
                                                @include('rooms.partials.status-pill', ['status' => $line->room->status])
                                            </div>
                                        @else
                                            <span class="rs-room-chip unassigned">Not assigned</span>
                                        @endif
                                        @if ($st->isOpen() && isset($assignable[$line->id]))
                                            @can('Update reservations')
                                                <form method="POST" action="{{ route('reservations.assign-room', [$r, $line]) }}" class="d-flex gap-1 mt-2 flex-wrap">
                                                    @csrf
                                                    <select name="room_id" class="form-select form-select-sm" style="max-width:230px" required aria-label="Room for line {{ $i + 1 }}">
                                                        <option value="">{{ $line->room ? 'Move to…' : 'Choose a room…' }}</option>
                                                        @foreach ($assignable[$line->id]->groupBy(fn ($room) => $room->roomType->name) as $typeName => $opts)
                                                            <optgroup label="{{ $typeName }}">
                                                                @foreach ($opts as $room)
                                                                    <option value="{{ $room->id }}">{{ $room->number }} · {{ $room->floor->name }}{{ in_array($room->status->value, ['available', 'reserved']) ? '' : ' (' . $room->status->label() . ')' }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>
                                                    @if ($line->room)
                                                        <input type="text" name="reason" class="form-control form-control-sm" style="max-width:160px" maxlength="255" placeholder="Reason">
                                                    @endif
                                                    <button class="action-btn btn-go" type="submit">{{ $line->room ? 'Move' : 'Assign' }}</button>
                                                </form>
                                                @if ($line->room && $st !== ReservationStatus::CheckedIn)
                                                    <form method="POST" action="{{ route('reservations.unassign-room', [$r, $line]) }}" class="mt-1">@csrf
                                                        <button class="btn btn-link btn-sm p-0 text-muted" type="submit">Release room</button></form>
                                                @endif
                                            @endcan
                                        @endif
                                    </td>
                                    <td data-label="Guests" class="small">{{ $line->adults }} adult{{ $line->adults === 1 ? '' : 's' }}{{ $line->children ? ', ' . $line->children . ' child' . ($line->children === 1 ? '' : 'ren') : '' }}</td>
                                    <td data-label="Charge" class="num">
                                        <div class="fw-semibold">@money($line->amount)</div>
                                        <details class="small text-muted">@php $n = count($line->nightly_rates ?? []); @endphp<summary>{{ $n }} {{ $n === 1 ? 'night' : 'nights' }}, night by night</summary>
                                            @foreach ($line->nightly_rates as $night => $rate)
                                                <div class="d-flex justify-content-between gap-3"><span>{{ \Carbon\Carbon::parse($night)->format('D j M') }}</span><span>@money($rate)</span></div>
                                            @endforeach
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>

            {{-- Bill --}}
            @php $bal = $bill['balance']; @endphp
            <x-cb.card title="Bill" icon="ri-bill-line" id="bill">
                <x-slot:tools>
                    @can('View folios')<a href="{{ route('folios.show', $folio) }}" class="action-btn btn-open"><i class="ri-file-list-3-line"></i> Open bill {{ $folio->number }}</a>@endcan
                </x-slot:tools>
                <div class="row g-3 align-items-start">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Booked room total ({{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }})</span><span>@money($r->room_total)</span></div>
                        @if ($r->discount_amount > 0)
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Discount{{ $r->discount_reason ? ' — ' . $r->discount_reason : '' }}</span><span class="text-success">−@money($r->discount_amount)</span></div>
                        @endif
                        <div class="d-flex justify-content-between mb-1 border-top pt-2 mt-2"><span class="text-muted">Charged so far</span><span>@money($bill['charged'])</span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Paid</span><span>−@money($bill['paid'])</span></div>
                        <div class="d-flex justify-content-between border-top pt-2 mt-2"><strong>{{ $bal < -0.004 ? 'Credit' : 'Balance due' }}</strong>
                            <strong class="fs-5" style="color: {{ $bal > 0.004 ? '#b91c1c' : ($bal < -0.004 ? '#15803d' : 'var(--cb-heading)') }}">@money(abs($bal))</strong></div>
                        @if (in_array($st, [ReservationStatus::Pending, ReservationStatus::Confirmed], true))
                            <div class="small text-muted mt-2">Room nights are charged to the bill as they are used, after check-in.</div>
                        @endif
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @can('Receive payments')
                                <button type="button" class="action-btn btn-go" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="ri-bank-card-line"></i> {{ in_array($st, [ReservationStatus::Pending, ReservationStatus::Confirmed], true) ? 'Take deposit' : 'Receive payment' }}</button>
                            @endcan
                            @can('Post charges')
                                @unless (in_array($st, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true))
                                    <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#chargeModal"><i class="ri-add-line"></i> Add charge</button>
                                @endunless
                            @endcan
                            @can('Bill to company')
                                @if ($bal > 0.004 && in_array($st, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true))
                                    <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#companyModal"><i class="ri-building-2-line"></i> Bill to company</button>
                                @endif
                            @endcan
                        </div>
                        @if ($bill['recent']->isNotEmpty())
                            <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Recent extras</div>
                            @foreach ($bill['recent'] as $c)
                                <div class="d-flex justify-content-between small"><span><i class="{{ $c->type->icon() }} text-muted"></i> {{ $c->description }}</span><span>{{ $c->total < 0 ? '−' : '' }}@money(abs($c->total))</span></div>
                            @endforeach
                        @endif
                    </div>
                    @can('Apply reservation discounts')
                        @unless (in_array($st, [ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::CheckedOut], true))
                            <div class="col-md-6">
                                <form method="POST" action="{{ route('reservations.discount', $r) }}" class="cb-banner info flex-column gap-2 mb-0">
                                    @csrf
                                    <strong><i class="ri-price-tag-3-line"></i> Booking discount</strong>
                                    <div class="input-group input-group-sm"><span class="input-group-text">{{ $sym }}</span>
                                        <input type="number" step="0.01" min="0" max="{{ $r->room_total }}" name="discount_amount" class="form-control" value="{{ (float) $r->discount_amount }}" aria-label="Discount amount"></div>
                                    <input type="text" name="discount_reason" class="form-control form-control-sm" maxlength="255" value="{{ $r->discount_reason }}" placeholder="Reason (required)">
                                    <button class="action-btn btn-primary-cb align-self-start" type="submit">Save discount</button>
                                    <span class="small text-muted">Comes off the room charges and shows on the bill.</span>
                                </form>
                            </div>
                        @endunless
                    @endcan
                </div>
            </x-cb.card>

            {{-- Activity --}}
            <x-cb.card title="Activity" icon="ri-history-line" :count="$events->count()">
                <ul class="rm-timeline">
                    @foreach ($events as $e)
                        <li>
                            <span class="ic" style="--st: var(--cb-navy-2)"><i class="{{ $e->icon() }}"></i></span>
                            <div>
                                <div class="small fw-semibold" style="color: var(--cb-heading)">{{ $e->description }}</div>
                                <div class="small text-muted">{{ $e->user?->name ?? 'System' }} · {{ $e->created_at->format('j M Y, g:i A') }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            {{-- Guest --}}
            <x-cb.card title="Guest" icon="ri-user-3-line">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white fw-bold" style="width:46px;height:46px;background:var(--cb-navy-2)">{{ $g->initials }}</span>
                    <div>
                        <div class="fw-bold" style="color: var(--cb-heading)">{{ $g->full_name }}</div>
                        @can('View guests')<a href="{{ route('guests.show', $g) }}" class="small">View profile &amp; history</a>@endcan
                    </div>
                </div>
                <dl class="rm-meta mb-0" style="grid-template-columns: 110px 1fr">
                    @if ($g->phone)<dt>Phone</dt><dd><a href="tel:{{ $g->phone }}">{{ $g->phone }}</a></dd>@endif
                    @if ($g->email)<dt>Email</dt><dd class="text-break"><a href="mailto:{{ $g->email }}">{{ $g->email }}</a></dd>@endif
                    @if ($g->company)<dt>Company</dt><dd>{{ $g->company }}</dd>@endif
                    @if ($g->nationality)<dt>Nationality</dt><dd>{{ $g->nationality }}</dd>@endif
                    @if ($g->id_type)<dt>ID</dt><dd>{{ $g->id_type }}@can('Manage guests'){{ $g->id_number ? ' · ' . $g->id_number : '' }}@endcan</dd>@endif
                </dl>
                @if ($g->preferences)<div class="cb-banner violet mt-3 mb-0"><i class="ri-heart-line"></i><div>{{ $g->preferences }}</div></div>@endif
                @if (config('hotel.loyalty.enabled') && ($g->loyalty_number || $g->loyalty_points > 0))
                    @php $ptsValue = round($g->loyalty_points * (float) config('hotel.loyalty.point_value', 1), 2); $minPts = (int) config('hotel.loyalty.min_redeem', 500); @endphp
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                        <div><span class="status-pill" style="background: {{ $g->tier_color }}22; color: {{ $g->tier_color }}"><i class="ri-vip-diamond-line"></i> {{ $g->tier_label }}</span>
                            <span class="small text-muted ms-1">{{ $g->loyalty_number }}</span></div>
                        <div class="text-end"><strong>{{ number_format($g->loyalty_points) }}</strong> <span class="small text-muted">pts · @money($ptsValue)</span></div>
                    </div>
                    @can('Receive payments')
                        @if ($folio->isOpen() && $bal > 0.004 && $g->loyalty_points >= $minPts)
                            @php $maxPts = (int) min($g->loyalty_points, floor($bal / max(0.01, (float) config('hotel.loyalty.point_value', 1)))); @endphp
                            <form method="POST" action="{{ route('folios.loyalty', $folio) }}" class="d-flex gap-2 mt-2" onsubmit="return confirm('Use these points on the bill?')">
                                @csrf
                                <input type="number" name="points" class="form-control form-control-sm" min="{{ $minPts }}" max="{{ $maxPts }}" step="1" value="{{ $maxPts }}" aria-label="Points to use" required>
                                <button class="action-btn btn-go text-nowrap" type="submit"><i class="ri-gift-line"></i> Use points</button>
                            </form>
                            <div class="small text-muted mt-1">Up to {{ number_format($maxPts) }} points can go on this bill.</div>
                        @endif
                    @endcan
                @endif
            </x-cb.card>

            {{-- Texts to and from the guest --}}
            @can('Message guests')
                @php $gMsgs = \App\Models\Messaging\GuestMessage::where('reservation_id', $r->id)->latest('id')->limit(6)->get()->reverse(); @endphp
                <x-cb.card title="Messages" icon="ri-message-3-line" :count="$gMsgs->count() ?: null">
                    @foreach ($gMsgs as $m)
                        <div class="mb-2 {{ $m->direction === 'in' ? '' : 'text-end' }}">
                            <div class="d-inline-block text-start small px-3 py-2" style="border-radius: 12px; max-width: 92%; background: {{ $m->direction === 'in' ? 'rgba(183,131,47,.12)' : 'rgba(15,118,110,.08)' }}">{{ $m->body }}</div>
                            <div class="small text-muted" style="font-size: 11px">{{ $m->direction === 'in' ? 'Guest' : $m->template_label }} · {{ $m->created_at->format('j M, g:i A') }} · <span class="{{ in_array($m->status, ['failed', 'skipped'], true) ? 'text-danger' : '' }}" title="{{ $m->error }}">{{ $m->status_label }}</span></div>
                        </div>
                    @endforeach
                    @if ($g->messages_opt_out)
                        <div class="small text-muted mb-2"><i class="ri-chat-off-line"></i> The guest asked not to receive texts. Call them instead, or turn texts back on in their profile if they ask.</div>
                    @endif
                    <form method="POST" action="{{ route('reservations.messages.store', $r) }}" data-once>
                        @csrf
                        <textarea name="body" class="form-control form-control-sm" rows="2" maxlength="640" required placeholder="Text {{ $g->first_name }} at {{ $g->phone ?: 'no number on file' }}…" aria-label="Message to the guest"></textarea>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <a href="{{ route('messages.index', ['q' => $r->reference]) }}" class="small">All messages</a>
                            <button class="action-btn btn-go" type="submit" @disabled(! $g->phone || $g->messages_opt_out)><i class="ri-send-plane-line"></i> Send</button>
                        </div>
                    </form>
                </x-cb.card>
            @endcan

            {{-- Details --}}
            <x-cb.card title="Booking details" icon="ri-information-line">
                <x-slot:tools>
                    @can('Update reservations')
                        <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#detailsModal"><i class="ri-pencil-line"></i> Edit</button>
                    @endcan
                </x-slot:tools>
                <dl class="rm-meta mb-0" style="grid-template-columns: 120px 1fr">
                    <dt>Reference</dt><dd><span class="rs-ref">{{ $r->reference }}</span></dd>
                    <dt>Guests</dt><dd>{{ $r->adults }} adult{{ $r->adults === 1 ? '' : 's' }}{{ $r->children ? ', ' . $r->children . ' child' . ($r->children === 1 ? '' : 'ren') : '' }}</dd>
                    <dt>Arrival time</dt><dd>{{ $r->expected_arrival_time ? \Carbon\Carbon::parse($r->expected_arrival_time)->format('g:i A') : '—' }}</dd>
                    <dt>Booked</dt><dd>{{ $r->created_at->format('j M Y, g:i A') }}<div class="small text-muted fw-normal">by {{ $r->createdBy?->name ?? '—' }}</div></dd>
                    @if ($r->checked_in_at)<dt>Checked in</dt><dd>{{ $r->checked_in_at->format('j M, g:i A') }}<div class="small text-muted fw-normal">by {{ $r->checkedInBy?->name }}</div></dd>@endif
                    @if ($r->checked_out_at)<dt>Checked out</dt><dd>{{ $r->checked_out_at->format('j M, g:i A') }}<div class="small text-muted fw-normal">by {{ $r->checkedOutBy?->name }}</div></dd>@endif
                </dl>
                @if ($r->special_requests)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Special requests</div>
                    <div class="small" style="white-space: pre-line">{{ $r->special_requests }}</div>
                @endif
                @if ($r->notes)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Internal notes</div>
                    <div class="small" style="white-space: pre-line">{{ $r->notes }}</div>
                @endif
            </x-cb.card>

            {{-- QR --}}
            <x-cb.card title="Check-in QR" icon="ri-qr-code-line">
                <div class="d-flex gap-3 align-items-center">
                    <div id="qr" class="p-2 bg-white rounded-3 border" style="width:128px;height:128px"></div>
                    <div class="small text-muted">The guest shows this at reception. It contains a random code only — no personal details.
                        <div class="mt-2"><a href="{{ route('reservations.print', $r) }}" target="_blank" rel="noopener" class="action-btn btn-open"><i class="ri-printer-line"></i> Print / share</a></div></div>
                </div>
            </x-cb.card>

            {{-- The guest's own booking page (pay, cancel, requests) --}}
            <x-cb.card title="Guest's booking page" icon="ri-global-line">
                @if ($r->status->value === 'pending' && $r->hold_expires_at)
                    <div class="small text-warning mb-2"><i class="ri-time-line"></i> Online hold — released {{ $r->hold_expires_at->diffForHumans() }} ({{ $r->hold_expires_at->format('D j M, g:i A') }}) unless the deposit is paid or you confirm it.</div>
                @endif
                @if ($r->guest_token)
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="guestLink" value="{{ route('stay.show', $r->guest_token) }}" readonly aria-label="Guest booking link">
                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard?.writeText(document.getElementById('guestLink').value); this.textContent='Copied';">Copy</button>
                    </div>
                    <div class="small text-muted mt-1">Send this to the guest (WhatsApp, SMS or email). Anyone with the link can see, pay and cancel the booking.</div>
                @else
                    <form method="POST" action="{{ route('reservations.guest-link', $r) }}" data-once>@csrf
                        <button class="action-btn btn-open"><i class="ri-link"></i> Create a link for the guest</button></form>
                    <div class="small text-muted mt-1">Lets the guest pay online, see their QR code and send requests during the stay.</div>
                @endif
                @php $ops = $r->onlinePayments()->latest()->limit(5)->get(); @endphp
                @if ($ops->isNotEmpty())
                    <div class="small fw-bold text-muted mt-3 mb-1">Online payments</div>
                    @foreach ($ops as $op)
                        <div class="d-flex justify-content-between small border-bottom py-1"><span>{{ $op->created_at->format('j M, g:i A') }} · {{ ucfirst($op->purpose) }}<span class="text-muted"> {{ $op->reference }}</span></span>
                            <span>@money($op->amount) <span class="status-pill {{ ['success' => 'st-success', 'failed' => 'st-danger', 'abandoned' => 'st-muted'][$op->status] ?? 'st-warning' }}">{{ $op->status === 'initialized' ? 'Started' : ucfirst($op->status) }}</span></span></div>
                    @endforeach
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

{{-- Modals --}}
@can('Update reservations')
<div class="modal fade" id="datesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('reservations.dates', $r) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-calendar-event-line me-1"></i> Change dates</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label>Check-in</label>
                    <input type="date" name="check_in_date" class="form-control" required value="{{ $r->check_in_date->toDateString() }}" @if ($st === ReservationStatus::CheckedIn) readonly @else min="{{ today()->toDateString() }}" @endif></div>
                <div class="col-6"><label>Check-out</label><input type="date" name="check_out_date" class="form-control" required value="{{ $r->check_out_date->toDateString() }}"></div>
                <div class="col-12"><label>Reason</label><input type="text" name="reason" class="form-control" maxlength="255" placeholder="e.g. Guest extending for a conference"></div>
                <div class="col-12 small text-muted">Nights already booked keep their price; new nights use today’s rates. Availability is re-checked for every room.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save dates</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('reservations.update', $r) }}">
            @csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title">Booking details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label>Source</label>
                    <select name="source" class="form-select">@foreach (BookingSource::options() as $v => $l)<option value="{{ $v }}" @selected($r->source->value === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-6"><label>Expected arrival</label><input type="time" name="expected_arrival_time" class="form-control" value="{{ $r->expected_arrival_time ? \Carbon\Carbon::parse($r->expected_arrival_time)->format('H:i') : '' }}"></div>
                <div class="col-12"><label>Special requests</label><textarea name="special_requests" class="form-control" rows="3" maxlength="2000">{{ $r->special_requests }}</textarea></div>
                <div class="col-12"><label>Internal notes</label><textarea name="notes" class="form-control" rows="3" maxlength="2000">{{ $r->notes }}</textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
@endcan
@can('Cancel reservations')
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('reservations.cancel', $r) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="ri-close-circle-line me-1"></i> Cancel {{ $r->reference }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label>Reason <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" maxlength="500" required placeholder="e.g. Guest called to cancel — travel plans changed"></textarea>
                <div class="small text-muted mt-2">Any rooms held for this booking are released. This can’t be undone.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep booking</button><button class="btn btn-danger" type="submit">Cancel reservation</button></div>
        </form>
    </div>
</div>
@endcan

@if ($st === ReservationStatus::CheckedIn)
@can('Check out guests')
@php $owing = session('checkout_owing', max(0, $bill['balance'])); @endphp
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rm-form">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-logout-box-r-line me-1"></i> Check out {{ $g->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                @if ($r->check_out_date->gt(today()))
                    <div class="cb-banner warning"><i class="ri-time-line"></i><div>Leaving early — booked until {{ $r->check_out_date->format('D j M') }}. Only nights used so far are charged.</div></div>
                @endif
                @if ($owing >= 0.01)
                    <div class="d-flex justify-content-between align-items-center p-3 rounded-3 mb-3" style="background:#fee2e2">
                        <span class="fw-semibold" style="color:#b91c1c">Guest still owes</span><strong class="fs-4" style="color:#b91c1c">@money($owing)</strong>
                    </div>
                    @can('Receive payments')
                        <button type="button" class="btn btn-success w-100 mb-3" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="ri-bank-card-line"></i> Take payment first</button>
                    @endcan
                    @can('Bill to company')
                        <button type="button" class="btn btn-outline-primary w-100 mb-3" data-bs-toggle="modal" data-bs-target="#companyModal"><i class="ri-building-2-line"></i> {{ $r->company ? 'Bill ' . $r->company->name : 'Bill to a company' }}</button>
                    @endcan
                    @can('Check out with balance')
                        <form method="POST" action="{{ route('reservations.check-out', $r) }}" class="border rounded-3 p-3">
                            @csrf
                            <label for="balReason" class="fw-semibold">Or let the guest leave owing — reason <span class="text-danger">*</span></label>
                            <input type="text" name="balance_reason" id="balReason" class="form-control mb-2" maxlength="255" required placeholder="e.g. Company account — invoice to be sent">
                            <button class="btn btn-outline-danger w-100" type="submit">Check out with balance owing</button>
                        </form>
                    @else
                        <div class="small text-muted">A manager must approve checking out a guest who still owes money.</div>
                    @endcan
                @else
                    @if ($bill['balance'] < -0.004)
                        <div class="cb-banner info"><i class="ri-refund-2-line"></i><div>The guest is @money(-$bill['balance']) in credit. Refund it from the bill before or after check-out.</div></div>
                    @else
                        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>The bill is settled.</div></div>
                    @endif
                    <form method="POST" action="{{ route('reservations.check-out', $r) }}">@csrf
                        <button class="btn btn-primary w-100" type="submit"><i class="ri-logout-box-r-line"></i> Check out — send room(s) to housekeeping</button></form>
                @endif
            </div>
        </div>
    </div>
</div>
@endcan
@endif

@include('billing.partials.modals', ['folio' => $folio, 'balance' => $bill['balance'], 'paidSoFar' => $bill['paid']])

<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.QRCode) new QRCode(document.getElementById('qr'), { text: @json(route('reservations.qr', $r->qr_token)), width: 112, height: 112, correctLevel: QRCode.CorrectLevel.M });
    if (location.hash === '#rooms') document.getElementById('rooms')?.scrollIntoView({ behavior: 'smooth' });
    @if (session()->has('checkout_owing'))
        bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutModal')).show();
    @endif
});
</script>
@endsection
