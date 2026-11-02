@extends('layouts.master')

@section('content')
@php
    use App\Models\Energy\EnergyZone;
    $have = fn (string $col, $id) => $zones->contains($col, $id);
@endphp
<style>
    .es-row summary { list-style: none; cursor: pointer; display: flex; gap: 12px; align-items: center; padding: 10px 14px; border-top: 1px solid var(--cb-border); }
    .es-row summary::-webkit-details-marker { display: none; }
    .es-row summary .grow { flex: 1; min-width: 0; }
    .es-row[open] summary { background: var(--cb-surface-2, #f8fafc); }
    .es-row form { padding: 12px 14px 16px; border-top: 1px dashed var(--cb-border); }
    .es-quick { display: flex; flex-wrap: wrap; gap: 6px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Places & generators" icon="ri-settings-3-line" subtitle="What uses power besides the guest rooms, and where the power comes from. Watts are what each draws when on; a rough figure is fine.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('energy.index') }}"><i class="ri-arrow-left-line"></i> Energy Saver</a>
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'energy']) }}"><i class="ri-equalizer-line"></i> Room watts &amp; prices</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-5">
            <x-cb.card title="Generators & power" icon="ri-oil-line" :flush="true">
                @foreach ($sources as $g)
                    <details class="es-row">
                        <summary>
                            <i class="{{ $g->kind === 'grid' ? 'ri-plug-line' : ($g->kind === 'generator' ? 'ri-oil-line' : 'ri-sun-line') }} fs-5 text-muted"></i>
                            <div class="grow"><strong>{{ $g->name }}</strong><div class="small text-muted">{{ $g->kind_label }}{{ $g->kind !== 'grid' ? ' · ' . $g->capacity_kw . ' kW' : '' }}{{ $g->burnsFuel() ? ' · ' . ($g->litres_per_hour ? $g->litres_per_hour . ' L/h at full load' : 'about ' . round($g->capacity_kw * 0.27, 1) . ' L/h at full load (estimated)') : '' }}{{ $g->is_active ? '' : ' · not in use' }}</div></div>
                            @if ($g->kind !== 'grid')<i class="ri-pencil-line text-muted"></i>@endif
                        </summary>
                        @if ($g->kind !== 'grid')
                            <form method="POST" action="{{ route('energy.sources.update', $g) }}">@csrf @method('PUT')
                                @include('energy.partials.source-fields', ['g' => $g])
                                <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Save</button></div>
                            </form>
                            <form method="POST" action="{{ route('energy.sources.destroy', $g) }}" class="px-3 pb-3" onsubmit="return confirm('Remove {{ $g->name }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
                        @else
                            <div class="p-3 small text-muted">The public supply. Mark it off and on from the Energy Saver page, or link it to a switch on the equipment board that a hub reports (the changeover switch).
                                @if ($switches->isNotEmpty())
                                    <form method="POST" action="{{ route('energy.sources.update', $g) }}" class="d-flex gap-2 mt-2 p-0 border-0">@csrf @method('PUT')
                                        <select class="form-select form-select-sm" name="device_id"><option value="">— not linked</option>@foreach ($switches as $sw)<option value="{{ $sw->id }}" @selected($g->device_id === $sw->id)>{{ $sw->area ? $sw->area . ': ' : '' }}{{ $sw->name }}</option>@endforeach</select>
                                        <button class="btn btn-sm btn-primary">Save</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </details>
                @endforeach
                <details class="es-row" {{ $sources->where('kind', '!=', 'grid')->isEmpty() ? 'open' : '' }}>
                    <summary><i class="ri-add-line fs-5 text-primary"></i><div class="grow"><strong class="text-primary">Add a generator, solar or inverter</strong></div></summary>
                    <form method="POST" action="{{ route('energy.sources.store') }}">@csrf
                        @include('energy.partials.source-fields', ['g' => null])
                        <button class="btn btn-sm btn-primary mt-2">Add</button>
                    </form>
                </details>
            </x-cb.card>

            <x-cb.card title="Equipment board" icon="ri-dashboard-3-line">
                @if ($boardCount)
                    <p class="small mb-0">{{ $boardCount }} room devices are on the <a href="{{ route('it.facilities.index') }}">equipment board</a>. Energy Saver uses their real state: a room is flagged only when its AC, lights, TV or water heater are actually on, and <strong>Done</strong> switches them off on the board.</p>
                @else
                    <p class="small mb-0">Add each room's equipment on the <a href="{{ route('it.facilities.index') }}">equipment board</a> (it can add the usual AC, lights, TV … to every room in one go). Energy Saver will then flag only rooms with things really left on, by name.</p>
                @endif
            </x-cb.card>

            <x-cb.card title="Sensors and switches (optional)" icon="ri-sensor-line">
                <p class="small mb-2">A room key-card switch, motion sensor, the changeover switch or a fuel gauge can tell Energy Saver what is happening, through a building-management system or a small device:</p>
<pre class="small bg-light p-2 rounded mb-2" style="white-space:pre-wrap">POST {{ url('/api/v1/energy/device') }}{{ \App\Support\Tenancy\CurrentHotel::multi() ? '?hotel=' . \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug : '' }}
X-Device-Key: (DEVICE_API_KEY in .env)
{"grid": false,
 "rooms": {"104": "empty", "105": "occupied"},
 "running": {"{{ $sources->firstWhere('kind', 'generator')?->name ?? 'Gen 1' }}": true},
 "fuel": {"{{ $sources->firstWhere('kind', 'generator')?->name ?? 'Gen 1' }}": 340}}</pre>
                <p class="small text-muted mb-0">Without them, everything works from bookings and from what the control room records. {{ filled(config('services.device.key')) ? 'A device key is set.' : 'No device key is set yet (DEVICE_API_KEY).' }}</p>
            </x-cb.card>
        </div>

        <div class="col-xl-7">
            <x-cb.card title="Places" icon="ri-building-2-line" :count="$zones->count()" :flush="true">
                @php
                    $quick = collect()
                        ->merge($floors->reject(fn ($f) => $have('floor_id', $f->id))->map(fn ($f) => ['label' => 'Corridor: ' . ($f->building ? $f->building->name . ' ' : '') . $f->name, 'kind' => 'corridor', 'floor_id' => $f->id, 'watts' => 1500, 'low_watts' => 300]))
                        ->merge($halls->reject(fn ($h) => $have('hall_id', $h->id))->map(fn ($h) => ['label' => $h->name, 'kind' => 'hall', 'hall_id' => $h->id, 'watts' => 15000, 'low_watts' => 300]))
                        ->merge($outlets->reject(fn ($o) => $have('outlet_id', $o->id))->map(fn ($o) => ['label' => $o->name, 'kind' => 'outlet', 'outlet_id' => $o->id, 'watts' => 8000, 'low_watts' => 1500]));
                @endphp
                @if ($quick->isNotEmpty())
                    <div class="p-3">
                        <div class="small text-muted mb-2">Add in one click (change the watts after):</div>
                        <div class="es-quick">
                            @foreach ($quick as $q)
                                <form method="POST" action="{{ route('energy.zones.store') }}">@csrf
                                    @foreach (array_diff_key($q, ['label' => 1]) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                                    <input type="hidden" name="name" value="{{ $q['label'] }}">
                                    <button class="btn btn-sm btn-outline-primary"><i class="ri-add-line"></i> {{ $q['label'] }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
                @foreach ($zones as $z)
                    <details class="es-row">
                        <summary>
                            <div class="grow"><strong>{{ $z->name }}</strong>
                                <div class="small text-muted">{{ $z->kind_label }} · {{ number_format($z->watts) }} W{{ $z->low_watts ? ' (' . number_format($z->low_watts) . ' W when down)' : '' }}
                                    · {{ $z->always_on ? 'always on' : ($z->hall ? 'on around events in ' . $z->hall->name : ($z->hours_label ? $z->hours_label : ($z->outlet ? 'open hours of ' . $z->outlet->name : ($z->floor ? 'off when ' . $z->floor->name . ' is empty' : 'on all the time (add hours to switch it down)')))) }}{{ $z->is_active ? '' : ' · not counted' }}</div></div>
                            <i class="ri-pencil-line text-muted"></i>
                        </summary>
                        <form method="POST" action="{{ route('energy.zones.update', $z) }}">@csrf @method('PUT')
                            @include('energy.partials.zone-fields', ['z' => $z])
                            <button class="btn btn-sm btn-primary mt-2">Save</button>
                        </form>
                        <form method="POST" action="{{ route('energy.zones.destroy', $z) }}" class="px-3 pb-3" onsubmit="return confirm('Remove {{ $z->name }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
                    </details>
                @endforeach
                <details class="es-row" {{ $zones->isEmpty() && $quick->isEmpty() ? 'open' : '' }}>
                    <summary><i class="ri-add-line fs-5 text-primary"></i><div class="grow"><strong class="text-primary">Add a place</strong> <span class="small text-muted">lobby, kitchen, laundry, pool pumps, garden lights, offices …</span></div></summary>
                    <form method="POST" action="{{ route('energy.zones.store') }}">@csrf
                        @include('energy.partials.zone-fields', ['z' => null])
                        <button class="btn btn-sm btn-primary mt-2">Add</button>
                    </form>
                </details>
            </x-cb.card>
            <div class="small text-muted">Guest rooms use {{ number_format($settings['room_watts']) }} W with a guest in and {{ number_format($settings['standby_watts']) }} W switched off; rooms are switched on {{ $settings['precool_minutes'] }} minutes before a guest is expected. Change these in <a href="{{ route('settings.hotel.index', ['tab' => 'energy']) }}">Hotel Settings → Energy Saver</a>.</div>
        </div>
    </div>
</div>
</div>
</div>
@endsection
