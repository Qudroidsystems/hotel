@php
    $p = $snap['power'];
    $sym = config('hotel.currency_symbol');
    $kw = fn ($w) => number_format($w / 1000, $w >= 10000 ? 0 : 1) . ' kW';
    $groups = [
        'Power' => $actions->where('target', 'source'),
        'Switch on' => $actions->whereIn('action', ['precool', 'on']),
        'Rooms to switch off' => $actions->where('target', 'room')->whereIn('action', ['off', 'low', 'eco']),
        'Places to switch off' => $actions->where('target', 'zone')->where('action', 'low'),
    ];
    $saveNow = $actions->filter->saves()->sum('watts');
    $grid = $p['grid'];
    $carrying = $p['running']->sum('capacity_kw');
@endphp

<div class="en-power mb-3">
    <div class="en-state {{ $p['state'] }}">
        <div class="ico"><i class="{{ $p['state'] === 'grid' ? 'ri-plug-line' : ($p['state'] === 'dark' ? 'ri-error-warning-line' : 'ri-oil-line') }}"></i></div>
        <div class="flex-grow-1">
            <h3>{{ $p['state'] === 'grid' ? 'On grid power' : ($p['state'] === 'dark' ? 'Grid is off' : 'On generator: ' . $p['running']->pluck('name')->join(' + ')) }}</h3>
            <p>{{ $p['line'] }}</p>
            @if (! $p['grid_on'] && $p['litres_per_hour'] > 0)
                <p class="mt-1 small">Burning about {{ $p['litres_per_hour'] }} L of diesel an hour ({{ $sym }}{{ number_format($p['litres_per_hour'] * $snap['settings']['diesel_price']) }}).</p>
            @endif
            <div class="btns">
                @if ($grid)
                    @if ($p['grid_on'])
                        <button class="btn btn-sm btn-light" data-post="{{ route('energy.grid') }}" data-body='{"on":false}'><i class="ri-shut-down-line"></i> Grid went off</button>
                    @else
                        <button class="btn btn-sm btn-light" data-post="{{ route('energy.grid') }}" data-body='{"on":true}'><i class="ri-plug-line"></i> Grid is back</button>
                    @endif
                @endif
                @if ($p['choice'] && ! $p['grid_on'] && ! $p['running']->contains('id', $p['choice']->id))
                    <button class="btn btn-sm btn-light" data-post="{{ route('energy.running', $p['choice']) }}" data-body='{"on":true}'><i class="ri-play-line"></i> {{ $p['choice']->name }} started</button>
                @endif
            </div>
        </div>
    </div>

    <x-cb.card title="Power sources" icon="ri-battery-2-charge-line">
        @forelse ($p['sources'] as $g)
            <div class="en-src">
                <span class="dot {{ $g->running ? 'on' : '' }}"></span>
                <div class="grow">
                    <strong>{{ $g->name }}</strong>
                    <span class="small text-muted">{{ $g->kind === 'grid' ? ($g->running ? 'on' : 'off') : $g->capacity_kw . ' kW' }}{{ $g->running_since ? ' · since ' . $g->running_since->format($g->running_since->isToday() ? 'g:i A' : 'D g:i A') : '' }}{{ ($runHours[$g->id] ?? 0) > 0 ? ' · ' . $runHours[$g->id] . ' h today' : '' }}</span>
                    @if ($g->burnsFuel() && $g->fuel_litres !== null)
                        <div class="small">{{ number_format($g->fuel_litres) }} L{{ $g->tank_litres ? ' of ' . number_format($g->tank_litres) : '' }}
                            @if ($g->running && ($h = $g->hoursLeft($p['now_kw'] * $g->capacity_kw / max(1, $carrying)))) · about {{ $h }} h left @endif</div>
                        @if ($g->tank_litres)<div class="en-fuel"><span style="width: {{ min(100, round($g->fuel_litres / $g->tank_litres * 100)) }}%"></span></div>@endif
                    @endif
                </div>
                @if ($g->kind !== 'grid')
                    @if ($g->burnsFuel())<button class="btn btn-sm btn-light" title="Record the fuel level" data-post="{{ route('energy.fuel', $g) }}" data-ask="Litres of diesel in {{ $g->name }} now:" data-field="litres" data-value="{{ $g->fuel_litres }}"><i class="ri-gas-station-line"></i></button>@endif
                    <button class="btn btn-sm {{ $g->running ? 'btn-outline-danger' : 'btn-outline-success' }}" data-post="{{ route('energy.running', $g) }}" data-body='{"on":{{ $g->running ? 'false' : 'true' }}}'>{{ $g->running ? 'Stopped' : 'Started' }}</button>
                @endif
            </div>
        @empty
            <p class="text-muted small mb-0">No generators added yet.</p>
        @endforelse
    </x-cb.card>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><x-cb.stat label="Power needed now" :value="number_format($snap['now_kw'], 1) . ' kW'" icon="ri-flashlight-line" accent="teal" :hint="'of ' . number_format($snap['all_on_kw'], 0) . ' kW with everything on'" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Rooms in use" :value="$snap['counts']['in_use'] . ' of ' . $snap['counts']['rooms']" icon="ri-hotel-bed-line" accent="amber" :hint="$snap['counts']['precool'] ? $snap['counts']['precool'] . ' to switch on for arrivals' : null" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Wasted if left on" :value="$kw($saveNow)" icon="ri-leaf-line" accent="green" :hint="'by ' . $actions->filter->saves()->count() . ' rooms and places to switch off'" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Saved in the last 7 days" :value="number_format($week['kwh'], 0) . ' kWh'" icon="ri-hand-coin-line" accent="rose" :hint="$sym . number_format($week['grid_value']) . ' on grid, or ' . number_format($week['litres'], 0) . ' L of diesel'" /></div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <x-cb.card title="To do now" icon="ri-task-line" :count="$actions->count()">
            @if ($actions->isEmpty())
                <p class="text-muted mb-0"><i class="ri-checkbox-circle-line text-success"></i> Nothing to switch right now. Everything that is on is in use.</p>
            @endif
            @foreach ($groups as $label => $list)
                @continue($list->isEmpty())
                <div class="en-group"><span>{{ $label }} · {{ $list->count() }}</span>
                    @if ($list->count() > 1)<button class="btn btn-sm btn-link p-0" data-post="{{ route('energy.done-many') }}" data-body='{{ json_encode(['ids' => $list->pluck('id')->values()]) }}'>Mark all done</button>@endif
                </div>
                @foreach ($list as $a)
                    @if ($loop->index === 8 && $list->count() > 10)
                        <button type="button" class="btn btn-sm btn-light w-100 my-2" onclick="this.nextElementSibling.hidden = false; this.remove()">Show {{ $list->count() - 8 }} more</button>
                        <div hidden>
                    @endif
                    <div class="en-act">
                        <span class="badge {{ $a->saves() ? 'bg-success-subtle text-success' : ($a->target === 'source' ? 'bg-warning-subtle text-warning' : 'bg-info-subtle text-info') }} badge-kw">
                            @if ($a->target === 'source')<i class="ri-oil-line"></i>@elseif ($a->saves())−{{ $kw($a->watts) }}@else +{{ $kw($a->watts) }}@endif
                        </span>
                        <div class="grow">
                            <div class="what">{{ $a->title }}</div>
                            <div class="why">{{ $a->reason }}@if ($a->saves() && $a->until && ! str_contains((string) $a->reason, $a->until->format('g:i A'))) · needed again {{ $a->until->format($a->until->isToday() ? 'g:i A' : 'D g:i A') }}@endif</div>
                        </div>
                        <div class="go">
                            <button class="btn btn-sm btn-success" data-post="{{ route('energy.done', $a) }}"><i class="ri-check-line"></i> Done</button>
                            <button class="btn btn-sm btn-light" title="Leave it as it is" data-post="{{ route('energy.skip', $a) }}" data-ask="Why leave it on? (optional)" data-field="note"><i class="ri-close-line"></i></button>
                        </div>
                    </div>
                    @if ($loop->last && $list->count() > 10)</div>@endif
                @endforeach
            @endforeach
        </x-cb.card>

        @if ($off->isNotEmpty())
            <x-cb.card title="Switched off now" icon="ri-shut-down-line" :count="$off->count()">
                <div class="small text-muted mb-2">Counted as saved until each is needed again; Energy Saver says when to switch it back on.</div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($off as $a)
                        <span class="badge bg-light text-dark border" title="{{ $a->doneBy?->name }} at {{ $a->done_at?->format('g:i A') }}">{{ preg_replace('/:.*$/', '', $a->title) }}@if ($a->until) · until {{ $a->until->format($a->until->isToday() ? 'g:i A' : 'D g:i A') }}@endif</span>
                    @endforeach
                </div>
            </x-cb.card>
        @endif
    </div>

    <div class="col-xl-5">
        <x-cb.card title="Power needed, next 24 hours" icon="ri-bar-chart-2-line">
            <div style="height: 210px"><canvas id="en-chart" data-points='@json(collect($snap['forecast'])->map(fn ($f) => ['label' => $f['label'], 'kw' => $f['kw']]))' data-cap="{{ $p['grid_on'] ? 0 : $carrying * $snap['settings']['headroom'] / 100 }}"></canvas></div>
            @if ($p['sources']->where('kind', '!=', 'grid')->isNotEmpty())
                <div class="small mt-2"><strong>If the grid is off:</strong> run the smallest set that carries each stretch — about {{ number_format($p['plan_litres'], 0) }} L of diesel for the day
                    @if ($p['plan_saves_litres'] > 0) ({{ number_format($p['plan_saves_litres'], 0) }} L less than running {{ $p['biggest']->name }} all day) @endif.</div>
                <div class="en-plan">
                    @foreach (array_slice($p['plan'], 0, 4) as $seg)
                        <div title="{{ $seg['hours'] }} h, up to {{ $seg['peak_kw'] }} kW"><strong>{{ $seg['source'] ?? '—' }}</strong>{{ $seg['from']->format('g A') }} – {{ $seg['to']->copy()->addHour()->format('g A') }}<br><span class="text-muted">up to {{ $seg['peak_kw'] }} kW</span></div>
                    @endforeach
                </div>
            @endif
        </x-cb.card>

        <x-cb.card title="Rooms" icon="ri-layout-grid-line">
            <div class="en-key mb-2"><span><i style="background:#0f766e"></i>Guest in</span><span><i style="background:#f59e0b"></i>Switch on, arriving</span><span><i style="background:#bbf7d0"></i>Out (eco)</span><span><i style="background:#e0e7ff"></i>Waiting for cleaning</span><span><i style="border:1px solid #cbd5e1"></i>Empty, off</span><span><i style="border:2px solid #dc2626"></i>Empty, equipment left on</span></div>
            @foreach ($snap['floors'] as $f)
                <div class="en-floor">
                    <div class="head"><strong>{{ $f['name'] }}</strong><span class="text-muted">{{ $f['empty'] ? 'empty' : $f['in_use'] . ' in use' }} · {{ $f['kw'] }} kW</span></div>
                    <div class="en-rooms">
                        @foreach ($f['rooms'] as $r)
                            @php $waste = in_array($r['need'], ['off', 'low'], true) && $r['devices_on']->isNotEmpty(); @endphp
                            <div class="en-room {{ $r['need'] }} {{ $waste ? 'waste' : '' }}" title="{{ $r['room']->number }}: {{ $r['reason'] }}{{ $r['devices_on']->isNotEmpty() ? ' · on: ' . $r['devices_on']->pluck('name')->join(', ') : '' }}">{{ $r['room']->number }}<small>{{ $waste ? $r['devices_on']->count() . ' on' : ['on' => 'in', 'precool' => 'on now', 'eco' => 'eco', 'low' => 'clean', 'off' => 'off'][$r['need']] }}</small></div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </x-cb.card>

        @if ($snap['zones']->isNotEmpty())
            <x-cb.card title="Places" icon="ri-building-2-line" :flush="true">
                <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <tbody>
                        @foreach ($snap['zones'] as $z)
                            <tr>
                                <td><strong>{{ $z['zone']->name }}</strong><div class="small text-muted">{{ $z['reason'] }}</div></td>
                                <td class="text-end text-nowrap"><span class="badge {{ $z['need'] === 'on' ? 'bg-success-subtle text-success' : 'bg-light text-muted border' }}">{{ $z['need'] === 'on' ? 'On' : ($z['zone']->low_watts ? 'Essentials only' : 'Off') }}</span>
                                    <div class="small text-muted">{{ $kw($z['need'] === 'on' ? $z['zone']->watts : $z['zone']->low_watts) }}</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </x-cb.card>
        @endif

        @if ($log->isNotEmpty())
            <x-cb.card title="Power log" icon="ri-history-line" :flush="true">
                <ul class="list-unstyled mb-0 small">
                    @foreach ($log as $e)
                        <li class="px-3 py-2 border-top">{{ $e->created_at->format($e->created_at->isToday() ? 'g:i A' : 'D j M, g:i A') }} · {{ $e->note }}<span class="text-muted"> · {{ $e->user?->name ?? ($e->by === 'device' ? 'sensor' : 'system') }}</span></li>
                    @endforeach
                </ul>
            </x-cb.card>
        @endif
    </div>
</div>
<div class="small text-muted mt-2">Updated {{ $snap['at']->format('g:i A') }}. Figures are estimates from the watts set for rooms and places (Hotel Settings → Energy Saver and the setup page).</div>
