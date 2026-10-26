@php $T = $data['totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Events held / booked" :value="$T['events']" icon="ri-presentation-line" accent="teal" :hint="number_format($T['guests']) . ' guests'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Event revenue" :value="$fmt($T['revenue'])" icon="ri-funds-line" accent="green" :hint="$fmt($T['per_guest']) . ' a guest'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Enquiries that booked" :value="$T['enquiries'] ? $pct($T['converted'] / $T['enquiries'] * 100, 0) : '—'" icon="ri-question-answer-line" accent="sky" :hint="$T['converted'] . ' of ' . $T['enquiries'] . ' made in the period'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Cancelled" :value="$T['cancelled']" icon="ri-close-circle-line" accent="rose" /></div>
</div>
<div class="row g-3">
    <div class="col-xl-6">
        <x-cb.card title="Halls" icon="ri-building-4-line">
            @foreach ($data['halls'] as $h)
                <div class="ar-line"><span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $h['hall']->color }}"></span> {{ $h['hall']->name }} <span class="sub">· {{ $h['events'] }} event(s), {{ $h['hours'] }} h, {{ $pct($h['use']) }} busy</span></span><strong>{{ $fmt($h['revenue']) }}</strong></div>
                <div class="ar-bar alt" style="width: {{ $h['use'] }}%; margin: -2px 0 6px"></div>
            @endforeach
            <div class="ar-note mt-2">Busy = hours booked out of 12 hours a day.</div>
        </x-cb.card>
    </div>
    <div class="col-xl-6">
        <x-cb.card title="By kind of event" icon="ri-bookmark-line">
            @forelse ($data['by_type'] as $x)
                <div class="ar-line"><span>{{ $x['label'] }} <span class="sub">· {{ $x['events'] }} event(s), {{ number_format($x['guests']) }} guests</span></span><strong>{{ $fmt($x['revenue']) }}</strong></div>
            @empty
                <div class="small text-muted">No confirmed events in this period.</div>
            @endforelse
        </x-cb.card>
    </div>
</div>
<x-cb.card title="Events" icon="ri-list-check" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>When</th><th>Event</th><th>Hall</th><th>Status</th><th class="num">Guests</th><th class="num">Total</th></tr></thead>
            <tbody>
                @forelse ($data['list'] as $e)
                    <tr><td data-label="When" class="small">{{ $e->starts_at->format('D j M, g:i A') }}</td>
                        <td data-label="Event">@canany(['View events', 'Manage events'])<a href="{{ route('events.show', $e) }}">{{ $e->title }}</a>@else{{ $e->title }}@endcanany<div class="small text-muted">{{ $e->type_label }}</div></td>
                        <td data-label="Hall" class="small">{{ $e->hall?->name }}</td><td data-label="Status"><span class="status-pill {{ $e->status_class }}">{{ $e->status_label }}</span></td>
                        <td data-label="Guests" class="num">{{ $e->guests_expected }}</td><td data-label="Total" class="num">{{ $fmt($e->total) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="text-muted">No events in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>
