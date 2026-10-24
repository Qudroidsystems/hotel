@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $type = $room->roomType; $st = $room->status; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Room ' . $room->number" icon="ri-door-open-line"
               :subtitle="$type->name . ' · ' . $room->building->name . ' · ' . $room->floor->name"
               :back="route('rooms.index')" back-label="Room Board">
        <x-slot:pills>
            <span class="cb-meta-pill" style="background: {{ $st->color() }}; border-color: {{ $st->color() }}"><i class="{{ $st->icon() }}"></i> {{ $st->label() }}</span>
            @unless ($room->is_active)<span class="cb-meta-pill"><i class="ri-eye-off-line"></i> Inactive — not sold</span>@endunless
            @if ($room->status_changed_at)
                <span class="cb-meta-pill"><i class="ri-time-line"></i> since {{ $room->status_changed_at->diffForHumans() }}</span>
            @endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Change room status')
                <button type="button" class="cb-hero-btn" data-status-room="{{ $room->id }}" data-status-number="{{ $room->number }}" data-status-current="{{ $st->value }}"><i class="ri-refresh-line"></i> Change status</button>
            @endcan
            @can('Update rooms')
                <a class="cb-hero-btn" href="{{ route('rooms.edit', $room) }}"><i class="ri-pencil-line"></i> Edit</a>
            @endcan
            @can('Delete rooms')
                <form method="POST" action="{{ route('rooms.destroy', $room) }}" onsubmit="return confirm('Delete room {{ $room->number }}? Its status history will be deleted too.');">
                    @csrf @method('DELETE')
                    <button type="submit" class="cb-hero-btn"><i class="ri-delete-bin-line"></i> Delete</button>
                </form>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-lg-5">
            <x-cb.card title="Room details" icon="ri-information-line">
                <dl class="rm-meta mb-0">
                    <dt>Room number</dt><dd>{{ $room->number }}</dd>
                    <dt>Room type</dt><dd><span class="d-inline-flex align-items-center gap-1"><span style="width:9px;height:9px;border-radius:50%;background:{{ $type->color }}"></span>{{ $type->name }}</span> <span class="subject-code">{{ $type->code }}</span></dd>
                    <dt>Building</dt><dd>{{ $room->building->name }}</dd>
                    <dt>Floor</dt><dd>{{ $room->floor->name }}</dd>
                    <dt>Rate / night</dt>
                    <dd>@money($room->rate)
                        @if ($room->rate_override !== null)
                            <span class="status-pill st-violet ms-1">Custom rate</span>
                        @elseif ($type->weekend_rate !== null)
                            <div class="small text-muted fw-normal">Fri &amp; Sat nights: @money($type->weekend_rate)</div>
                        @endif
                    </dd>
                    <dt>Sleeps</dt><dd>{{ $type->capacity_label }}</dd>
                    @if ($type->bed_type)<dt>Bed</dt><dd>{{ $type->bed_type }}</dd>@endif
                    @if ($type->size_sqm)<dt>Size</dt><dd>{{ $type->size_sqm }} m²</dd>@endif
                    <dt>Last status change</dt>
                    <dd>{{ $room->status_changed_at?->format('d M Y, g:i A') ?? '—' }}
                        @if ($room->statusChangedBy)<div class="small text-muted fw-normal">by {{ $room->statusChangedBy->name }}</div>@endif
                    </dd>
                </dl>

                @if (! empty($room->features))
                    <hr>
                    <div class="small fw-bold text-muted text-uppercase mb-2">This room</div>
                    @foreach ($room->features as $f)<span class="rm-amenity">{{ $f }}</span>@endforeach
                @endif
                @if (! empty($type->amenities))
                    <hr>
                    <div class="small fw-bold text-muted text-uppercase mb-2">Amenities ({{ $type->name }})</div>
                    @foreach ($type->amenities as $a)<span class="rm-amenity">{{ $a }}</span>@endforeach
                @endif
                @if ($room->notes)
                    <hr>
                    <div class="small fw-bold text-muted text-uppercase mb-1">Internal notes</div>
                    <div class="small" style="white-space: pre-line">{{ $room->notes }}</div>
                @endif
            </x-cb.card>

            @if ($type->photos->isNotEmpty())
                <x-cb.card title="Photos" icon="ri-image-line" :count="$type->photos->count()">
                    <div class="rm-photos">
                        @foreach ($type->photos as $photo)
                            <a class="ph" href="{{ $photo->url }}" target="_blank" rel="noopener"><img src="{{ $photo->url }}" alt="{{ $type->name }} photo" loading="lazy"></a>
                        @endforeach
                    </div>
                </x-cb.card>
            @endif
        </div>

        <div class="col-lg-7">
            @if ($bookings->isNotEmpty())
                <x-cb.card title="Current & upcoming stays" icon="ri-calendar-check-line" :count="$bookings->count()" :flush="true">
                    <table class="cb-table stack">
                        <tbody>
                            @foreach ($bookings as $b)
                                <tr>
                                    <td data-label="Guest"><a href="{{ route('reservations.show', $b) }}" class="fw-bold text-reset">{{ $b->guest->full_name }}</a> <span class="small text-muted">{{ $b->reference }}</span></td>
                                    <td data-label="Stay" class="small">{{ $b->check_in_date->format('D j M') }} → {{ $b->check_out_date->format('D j M') }}</td>
                                    <td data-label="Status">@include('reservations.partials.status-pill', ['status' => $b->status])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-cb.card>
            @endif
            @if ($cleaning->isNotEmpty() || $jobs->isNotEmpty())
                <x-cb.card title="Cleaning & repairs" icon="ri-tools-line">
                    @foreach ($cleaning as $t)
                        <div class="d-flex justify-content-between py-2 border-bottom small"><span><i class="ri-brush-line"></i> {{ $t->type_label }}{{ $t->assignee ? ' · ' . $t->assignee->name : '' }}</span><span class="status-pill {{ $t->status_pill }}">{{ $t->status_label }}</span></div>
                    @endforeach
                    @foreach ($jobs as $j)
                        <div class="d-flex justify-content-between py-2 border-bottom small">
                            @canany(['View maintenance', 'Manage maintenance'])<a href="{{ route('maintenance.show', $j) }}" class="text-reset">@else<span>@endcanany
                                <i class="{{ $j->category_icon }}"></i> {{ $j->number }} · {{ $j->title }}
                            @canany(['View maintenance', 'Manage maintenance'])</a>@else</span>@endcanany
                            <span><span class="status-pill {{ $j->status_pill }}">{{ $j->status_label }}</span> {{ $j->created_at->format('j M Y') }}{{ $j->cost ? ' · ' . config('hotel.currency_symbol') . number_format((float) $j->cost, 2) : '' }}</span>
                        </div>
                    @endforeach
                    @if ($jobs->isNotEmpty())<div class="small text-muted mt-2">Repair cost on record: @money($jobs->sum('cost'))</div>@endif
                </x-cb.card>
            @endif
            <x-cb.card title="Status history" icon="ri-history-line" :count="$logs->total()">
                @if ($logs->isEmpty())
                    <div class="empty-state py-4"><i class="ri-history-line"></i><h6>No history yet</h6></div>
                @else
                    <ul class="rm-timeline">
                        @foreach ($logs as $log)
                            <li>
                                <span class="ic" style="--st: {{ $log->to_status->color() }}"><i class="{{ $log->to_status->icon() }}"></i></span>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold" style="color: var(--cb-heading)">
                                        @if ($log->from_status)
                                            {{ $log->from_status->label() }} <i class="ri-arrow-right-line text-muted"></i>
                                        @endif
                                        {{ $log->to_status->label() }}
                                    </div>
                                    @if ($log->reason)<div class="small">{{ $log->reason }}</div>@endif
                                    <div class="small text-muted">{{ $log->user?->name ?? 'System' }} · {{ $log->created_at->format('d M Y, g:i A') }} ({{ $log->created_at->diffForHumans() }})</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-3">{{ $logs->links() }}</div>
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@include('rooms.partials.status-modal')
@endsection
