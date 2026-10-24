@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\ReservationStatus;
    $col = function ($date) use ($start, $days) {
        return max(0, min($days, (int) $start->diffInDays(\Illuminate\Support\Carbon::parse($date), false)));
    };
    $byRoom = $lines->whereNotNull('room_id')->groupBy('room_id');
    $unassigned = $lines->whereNull('room_id')->filter(fn ($l) => $l->reservation->status->isOpen())->groupBy('room_type_id');
    $canBook = auth()->user()->can('Create reservations');
    $roomTypes = \App\Models\RoomType::whereIn('id', $rooms->keys()->merge($unassigned->keys()))->get()->keyBy('id');
@endphp
<style>
.tc-wrap { overflow-x:auto; }
.tc { --cw: {{ $days > 21 ? 44 : 64 }}px; min-width: calc(150px + var(--cw) * {{ $days }}); }
.tc-row { display:grid; grid-template-columns: 150px repeat({{ $days }}, var(--cw)); align-items:stretch; border-bottom:1px solid var(--cb-border); min-height:42px; }
.tc-row > * { grid-row:1; }
.tc-head { position:sticky; top:0; z-index:3; background: var(--cb-surface); }
.tc-head .d { text-align:center; font-size:11px; color: var(--cb-muted); padding:6px 0; border-left:1px solid var(--cb-border); line-height:1.2; }
.tc-head .d strong { display:block; font-size:14px; color: var(--cb-heading); }
.tc-label { position:sticky; left:0; z-index:2; background: var(--cb-surface); padding:8px 12px; border-right:1px solid var(--cb-border); font-weight:700; color: var(--cb-heading); font-size:13px; display:flex; align-items:center; gap:6px; }
.tc-label small { font-weight:600; color: var(--cb-muted); }
.tc-cell { border-left:1px solid var(--cb-border); }
.tc-cell.we { background: color-mix(in srgb, var(--cb-sky) 5%, transparent); }
.tc-cell.today, .tc-head .d.today { background: color-mix(in srgb, var(--cb-teal) 12%, transparent); }
.tc-group { background: var(--cb-surface-2); font-size:11px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color: var(--cb-muted); padding:6px 12px; border-bottom:1px solid var(--cb-border); position:sticky; left:0; }
.tc-bar { z-index:1; margin:6px 2px; border-radius:8px; padding:3px 8px; font-size:11.5px; font-weight:700; color:#fff; background: var(--st); text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:4px; box-shadow: 0 2px 6px rgba(15,35,66,.15); }
.tc-bar:hover { color:#fff; filter:brightness(1.08); }
.tc-bar.past { opacity:.55; }
.tc-bar.cut-l { border-top-left-radius:0; border-bottom-left-radius:0; }
.tc-bar.cut-r { border-top-right-radius:0; border-bottom-right-radius:0; }
.tc-empty { z-index:0; display:block; }
.tc-empty:hover { background: color-mix(in srgb, var(--cb-teal) 18%, transparent); }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Booking Calendar" icon="ri-calendar-2-line"
               :subtitle="$start->format('j M') . ' – ' . $end->copy()->subDay()->format('j M Y') . '. Each bar is a stay; click one to open it' . (auth()->user()->can('Create reservations') ? ', or click an empty day to book that room.' : '.')"
               :back="route('reservations.index')" back-label="Front Desk">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('reservations.calendar', ['start' => $start->copy()->subDays($days)->toDateString(), 'days' => $days]) }}"><i class="ri-arrow-left-s-line"></i> Earlier</a>
            <a class="cb-hero-btn" href="{{ route('reservations.calendar', ['days' => $days]) }}"><i class="ri-focus-3-line"></i> Today</a>
            <a class="cb-hero-btn" href="{{ route('reservations.calendar', ['start' => $start->copy()->addDays($days)->toDateString(), 'days' => $days]) }}">Later <i class="ri-arrow-right-s-line"></i></a>
        </x-slot:actions>
    </x-cb.hero>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <label class="small fw-semibold text-muted m-0" for="start">From</label>
            <input type="date" name="start" id="start" class="cb-select" value="{{ $start->toDateString() }}" onchange="this.form.submit()">
            <div class="term-chips">
                @foreach ([7, 14, 21, 30] as $d)
                    <a href="{{ route('reservations.calendar', ['start' => $start->toDateString(), 'days' => $d]) }}" class="term-chip {{ $days === $d ? 'active' : '' }}">{{ $d }} days</a>
                @endforeach
            </div>
            <div class="ms-auto d-flex flex-wrap gap-2 small">
                @foreach ([ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut] as $s)
                    <span class="d-inline-flex align-items-center gap-1"><span style="width:10px;height:10px;border-radius:3px;background:{{ $s->color() }}"></span>{{ $s->label() }}</span>
                @endforeach
            </div>
        </form>

        @if ($rooms->isEmpty())
            <div class="empty-state"><i class="ri-hotel-bed-line"></i><h6>No rooms yet</h6><p>Add rooms to see the calendar.</p></div>
        @else
        <div class="tc-wrap">
            <div class="tc">
                <div class="tc-row tc-head">
                    <div class="tc-label">Room</div>
                    @foreach ($dates as $d)
                        <div class="d {{ $d->isToday() ? 'today' : '' }}">{{ $d->format('D') }}<strong>{{ $d->format('j') }}</strong>{{ $d->format('M') }}</div>
                    @endforeach
                </div>

                @foreach ($rooms as $typeId => $typeRooms)
                    <div class="tc-group">{{ $roomTypes[$typeId]->name ?? 'Rooms' }}</div>
                    @foreach ($typeRooms as $room)
                        <div class="tc-row">
                            <div class="tc-label"><span style="width:8px;height:8px;border-radius:50%;background:{{ $room->roomType->color }}"></span>{{ $room->number }} <small>{{ $room->floor->name }}</small></div>
                            @foreach ($dates as $i => $d)
                                @if ($canBook && $d->gte(today()))
                                    <a class="tc-cell tc-empty {{ $d->isWeekend() ? 'we' : '' }} {{ $d->isToday() ? 'today' : '' }}" style="grid-column: {{ $i + 2 }}"
                                       href="{{ route('reservations.create', ['in' => $d->toDateString(), 'out' => $d->copy()->addDay()->toDateString()]) }}" title="Book from {{ $d->format('D j M') }}" aria-label="Book from {{ $d->format('D j M') }}"></a>
                                @else
                                    <div class="tc-cell {{ $d->isWeekend() ? 'we' : '' }}" style="grid-column: {{ $i + 2 }}"></div>
                                @endif
                            @endforeach
                            @foreach ($byRoom[$room->id] ?? [] as $line)
                                @php
                                    $res = $line->reservation;
                                    $a = $col($res->check_in_date); $b = $col($res->check_out_date);
                                @endphp
                                @if ($b > $a)
                                    <a href="{{ route('reservations.show', $res) }}" class="tc-bar {{ $res->status === ReservationStatus::CheckedOut ? 'past' : '' }} {{ $res->check_in_date->lt($start) ? 'cut-l' : '' }} {{ $res->check_out_date->gt($end) ? 'cut-r' : '' }}"
                                       style="--st: {{ $res->status->color() }}; grid-column: {{ $a + 2 }} / {{ $b + 2 }}"
                                       title="{{ $res->guest->full_name }} · {{ $res->reference }} · {{ $res->check_in_date->format('j M') }}–{{ $res->check_out_date->format('j M') }} · {{ $res->status->label() }}">
                                        <i class="{{ $res->status->icon() }}"></i> {{ $res->guest->last_name }} <span style="opacity:.75">{{ $res->reference }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                @endforeach

                @if ($unassigned->isNotEmpty())
                    <div class="tc-group" style="color:#b45309"><i class="ri-alert-line"></i> Booked but no room assigned yet</div>
                    @foreach ($unassigned as $typeId => $typeLines)
                        @foreach ($typeLines as $line)
                            @php $res = $line->reservation; $a = $col($res->check_in_date); $b = $col($res->check_out_date); @endphp
                            <div class="tc-row">
                                <div class="tc-label"><span style="width:8px;height:8px;border-radius:50%;background:{{ $line->roomType->color }}"></span>{{ $line->roomType->name }}</div>
                                @foreach ($dates as $i => $d)<div class="tc-cell {{ $d->isWeekend() ? 'we' : '' }} {{ $d->isToday() ? 'today' : '' }}" style="grid-column: {{ $i + 2 }}"></div>@endforeach
                                @if ($b > $a)
                                    <a href="{{ route('reservations.show', $res) }}#rooms" class="tc-bar" style="--st: {{ $res->status->color() }}; grid-column: {{ $a + 2 }} / {{ $b + 2 }}; outline: 2px dashed rgba(255,255,255,.7); outline-offset:-4px"
                                       title="Assign a room — {{ $res->guest->full_name }} · {{ $res->reference }}">
                                        {{ $res->guest->last_name }} <span style="opacity:.75">{{ $res->reference }}</span>
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                @endif
            </div>
        </div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
