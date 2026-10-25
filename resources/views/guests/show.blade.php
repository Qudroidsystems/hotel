@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\ReservationStatus;
    $stays = $stats['stays'];
    $sym = config('hotel.currency_symbol');
    $loyaltyOn = $loyalty->enabled();
@endphp
<style>
    .lc-card { border-radius: 14px; padding: 18px 18px 16px; color: #fff; position: relative; overflow: hidden;
        background: linear-gradient(135deg, #14213d 0%, #1d3557 55%, {{ $guest->tier_color }} 140%); }
    .lc-card .lc-tier { font-family: 'Playfair Display', Georgia, serif; font-size: 22px; font-weight: 700; }
    .lc-card .lc-no { font-size: 12px; letter-spacing: .12em; opacity: .75; }
    .lc-card .lc-pts { font-size: 30px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .lc-card .lc-tag { position: absolute; right: -14px; top: -10px; width: 92px; height: 120px; border-radius: 46px 46px 14px 14px; background: {{ $guest->tier_color }}; opacity: .35; transform: rotate(14deg); }
    .lc-card .lc-tag::after { content: ''; position: absolute; left: 50%; top: 22px; width: 18px; height: 18px; margin-left: -9px; border-radius: 50%; background: #14213d; }
    .lc-progress { height: 6px; border-radius: 3px; background: rgba(255,255,255,.2); overflow: hidden; }
    .lc-progress span { display: block; height: 100%; background: #fff; border-radius: 3px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$guest->full_name" icon="ri-user-star-line"
               :subtitle="$stays ? 'Returning guest — ' . $stays . ' completed stay' . ($stays === 1 ? '' : 's') . '.' : 'No completed stays yet.'"
               :back="route('guests.index')" back-label="Guests">
        <x-slot:pills>
            @if ($guest->is_vip)<span class="cb-meta-pill"><i class="ri-vip-crown-line"></i> VIP</span>@endif
            @if ($loyaltyOn && $guest->loyalty_number)<span class="cb-meta-pill"><i class="ri-vip-diamond-line"></i> {{ $guest->tier_label }} · {{ $guest->loyalty_number }}</span>@endif
            @if ($guest->messages_opt_out)<span class="cb-meta-pill"><i class="ri-chat-off-line"></i> No texts</span>@endif
            @if ($guest->company)<span class="cb-meta-pill"><i class="ri-building-line"></i> {{ $guest->company }}</span>@endif
            <span class="cb-meta-pill"><i class="ri-calendar-line"></i> Guest since {{ $guest->created_at->format('M Y') }}</span>
        </x-slot:pills>
        <x-slot:actions>
            @can('Create reservations')
                <a class="cb-hero-btn" href="{{ route('reservations.create', ['guest' => $guest->id]) }}"><i class="ri-add-line"></i> New booking</a>
            @endcan
            @can('Manage guests')
                <a class="cb-hero-btn" href="{{ route('guests.edit', $guest) }}"><i class="ri-pencil-line"></i> Edit</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Completed stays" :value="$stats['stays']" icon="ri-hotel-bed-line" accent="teal" :hint="$stats['nights'] . ' night' . ($stats['nights'] === 1 ? '' : 's') . ' in all'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Spent with us" :value="$sym . number_format($stats['spent'], 0)" icon="ri-money-dollar-circle-line" accent="green" :hint="$stats['stays'] ? 'about ' . $sym . number_format($stats['average'], 0) . ' a stay' : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Last stay" :value="$stats['last'] ? $stats['last']->format('j M Y') : '—'" icon="ri-history-line" accent="amber" :hint="$stats['last'] ? $stats['last']->diffForHumans() : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Usual room" :value="$stats['favourite'] ?? '—'" icon="ri-door-line" accent="violet" :hint="$stats['upcoming'] ? 'Next: ' . $stats['upcoming']->check_in_date->format('j M') . ' (' . $stats['upcoming']->reference . ')' : null" /></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            @if ($loyaltyOn)
                <div class="lc-card mb-3">
                    <div class="lc-tag" aria-hidden="true"></div>
                    <div class="lc-tier">{{ $guest->tier_label }}</div>
                    <div class="lc-no">{{ $guest->loyalty_number ?? 'Member number on first stay' }}</div>
                    <div class="mt-3 lc-pts">{{ number_format($guest->loyalty_points) }} <span style="font-size:14px;font-weight:500;opacity:.8">points</span></div>
                    <div class="small" style="opacity:.85">Worth {{ $sym }}{{ number_format($loyalty->pointsValue($guest->loyalty_points), 2) }} on a bill{{ $loyalty->discountPercent($guest) > 0 ? ' · ' . rtrim(rtrim(number_format($loyalty->discountPercent($guest), 1), '0'), '.') . '% off rooms booked online' : '' }}</div>
                    <div class="mt-3 small" style="opacity:.85">
                        @if ($next)
                            {{ $nights12 }} night{{ $nights12 === 1 ? '' : 's' }} in the last 12 months · {{ $next[1] }} more for {{ \App\Services\Loyalty\LoyaltyService::TIERS[$next[0]][0] }}
                            <div class="lc-progress mt-1"><span style="width: {{ min(100, round($nights12 / max(1, $next[2]) * 100)) }}%"></span></div>
                        @else
                            {{ $nights12 }} nights in the last 12 months · top tier
                        @endif
                    </div>
                </div>
            @endif
            <x-cb.card title="Profile" icon="ri-user-3-line">
                <dl class="rm-meta mb-0" style="grid-template-columns: 110px 1fr">
                    <dt>Phone</dt><dd>{{ $guest->phone ?: '—' }}</dd>
                    <dt>Email</dt><dd class="text-break">{{ $guest->email ?: '—' }}</dd>
                    <dt>Gender</dt><dd>{{ $guest->gender ? ucfirst($guest->gender) : '—' }}</dd>
                    <dt>Nationality</dt><dd>{{ $guest->nationality ?: '—' }}</dd>
                    <dt>ID</dt><dd>{{ $guest->id_type ?: '—' }}@can('Manage guests'){{ $guest->id_number ? ' · ' . $guest->id_number : '' }}@endcan</dd>
                    <dt>Address</dt><dd>{{ $guest->address ?: '—' }}</dd>
                </dl>
                @if ($guest->preferences)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Preferences</div><div class="small">{{ $guest->preferences }}</div>
                @endif
                @if ($guest->notes)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Internal notes</div><div class="small">{{ $guest->notes }}</div>
                @endif
            </x-cb.card>
        </div>
        <div class="col-lg-8">
            @if ($loyaltyOn)
                <x-cb.card title="Points history" icon="ri-vip-diamond-line" :flush="true">
                    @can('Manage guests')
                        <x-slot:tools><button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#pointsModal"><i class="ri-equalizer-line"></i> Adjust points</button></x-slot:tools>
                    @endcan
                    @if ($entries->isEmpty())
                        <div class="empty-state py-4"><i class="ri-star-line"></i><h6>No points yet</h6><p class="small text-muted mb-0">Points are earned when a stay's bill is fully paid after check-out.</p></div>
                    @else
                        <table class="cb-table stack">
                            <thead><tr><th>When</th><th>What</th><th class="num">Points</th><th class="num">Balance</th></tr></thead>
                            <tbody>
                                @foreach ($entries as $e)
                                    <tr>
                                        <td data-label="When" class="small">{{ $e->created_at->format('j M Y') }}<div class="text-muted">{{ $e->creator?->name ?? 'Automatic' }}</div></td>
                                        <td data-label="What" class="small"><span class="status-pill {{ $e->type_pill }}"><i class="{{ $e->type_icon }}"></i> {{ $e->type_label }}</span>
                                            @if ($e->reservation && str_contains((string) $e->note, $e->reservation->reference)){!! str_replace(e($e->reservation->reference), '<a href="' . e(route('reservations.show', $e->reservation)) . '">' . e($e->reservation->reference) . '</a>', e($e->note)) !!}@else{{ $e->note }}@endif
                                            @if ($e->reservation && ! str_contains((string) $e->note, $e->reservation->reference))<a href="{{ route('reservations.show', $e->reservation) }}" class="ms-1">{{ $e->reservation->reference }}</a>@endif</td>
                                        <td data-label="Points" class="num fw-semibold" style="color: {{ $e->points < 0 ? '#b91c1c' : '#15803d' }}">{{ $e->points > 0 ? '+' : '−' }}{{ number_format(abs($e->points)) }}</td>
                                        <td data-label="Balance" class="num">{{ number_format($e->balance_after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-cb.card>
            @endif
            @can('Message guests')
                @php $gMsgs = \App\Models\Messaging\GuestMessage::where('guest_id', $guest->id)->latest('id')->limit(5)->get()->reverse(); @endphp
                <x-cb.card title="Messages" icon="ri-message-3-line">
                    <x-slot:tools><a href="{{ route('messages.index', ['q' => $guest->phone ?: $guest->last_name]) }}" class="action-btn btn-more">All</a></x-slot:tools>
                    @forelse ($gMsgs as $m)
                        <div class="d-flex gap-2 small mb-2">
                            <span class="status-pill {{ $m->direction === 'in' ? 'st-warning' : 'st-info' }}" style="flex:0 0 auto">{{ $m->direction === 'in' ? 'Guest' : $m->template_label }}</span>
                            <span class="flex-grow-1">{{ $m->body }}</span>
                            <span class="text-muted text-nowrap">{{ $m->created_at->format('j M') }} · {{ $m->status_label }}</span>
                        </div>
                    @empty
                        <div class="small text-muted mb-2">No texts yet.</div>
                    @endforelse
                    @if ($guest->messages_opt_out)
                        <div class="small text-muted"><i class="ri-chat-off-line"></i> {{ $guest->first_name }} asked not to receive texts.</div>
                    @elseif ($guest->phone)
                        <form method="POST" action="{{ route('guests.messages.store', $guest) }}" class="d-flex gap-2 mt-2" data-once>@csrf
                            <input name="body" class="form-control form-control-sm" maxlength="640" required placeholder="Text {{ $guest->first_name }}…" aria-label="Message to {{ $guest->full_name }}">
                            <button class="action-btn btn-go"><i class="ri-send-plane-line"></i> Send</button>
                        </form>
                    @endif
                </x-cb.card>
            @endcan
            <x-cb.card title="Bookings" icon="ri-calendar-check-line" :count="$reservations->total()" :flush="true">
                @if ($reservations->isEmpty())
                    <div class="empty-state py-4"><i class="ri-calendar-line"></i><h6>No bookings</h6></div>
                @else
                    <table class="cb-table stack">
                        <thead><tr><th>Reference</th><th>Stay</th><th>Rooms</th><th>Status</th><th class="num">Total</th></tr></thead>
                        <tbody>
                            @foreach ($reservations as $r)
                                <tr>
                                    <td data-label="Reference"><a href="{{ route('reservations.show', $r) }}" class="rs-ref text-decoration-none">{{ $r->reference }}</a></td>
                                    <td data-label="Stay" class="small">{{ $r->check_in_date->format('j M Y') }} → {{ $r->check_out_date->format('j M') }}<div class="text-muted">{{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }}</div></td>
                                    <td data-label="Rooms">@include('reservations.partials.rooms-cell', ['reservation' => $r])</td>
                                    <td data-label="Status">@include('reservations.partials.status-pill', ['status' => $r->status])</td>
                                    <td data-label="Total" class="num">@money($r->total_amount)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3">{{ $reservations->links() }}</div>
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@can('Manage guests')
<div class="modal fade" id="pointsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('guests.points', $guest) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-equalizer-line me-1"></i> Adjust points</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label for="ptsDir">Change</label><select name="direction" id="ptsDir" class="form-select"><option value="add">Add points</option><option value="remove">Take away points</option></select></div>
                <div class="col-6"><label for="ptsN">Points</label><input type="number" name="points" id="ptsN" class="form-control" min="1" step="1" required></div>
                <div class="col-12"><label for="ptsWhy">Reason</label><input type="text" name="reason" id="ptsWhy" class="form-control" maxlength="255" required placeholder="e.g. Sorry for the noisy room, or correcting a mistake"></div>
                <div class="col-12 small text-muted">Recorded on the points history and the audit trail with your name.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
@endcan
@endsection
