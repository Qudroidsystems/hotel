<div class="row g-2">
    <div class="col-md-6"><label class="form-label small">Name</label><input class="form-control form-control-sm" name="name" required maxlength="80" value="{{ $g?->name }}" placeholder="e.g. Perkins 60 kVA"></div>
    <div class="col-md-6"><label class="form-label small">Kind</label>
        <select class="form-select form-select-sm" name="kind">@foreach (['generator' => 'Generator', 'solar' => 'Solar', 'inverter' => 'Inverter / batteries'] as $k => $l)<option value="{{ $k }}" @selected(($g?->kind ?? 'generator') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label small">Power (kW)</label><input class="form-control form-control-sm" type="number" min="1" name="capacity_kw" required value="{{ $g?->capacity_kw }}"><div class="form-text">kVA × 0.8</div></div>
    <div class="col-md-4"><label class="form-label small">Diesel at full load (L/h)</label><input class="form-control form-control-sm" type="number" step="0.1" min="0" name="litres_per_hour" value="{{ $g?->litres_per_hour }}"><div class="form-text">From the data plate; empty = estimated</div></div>
    <div class="col-md-4"><label class="form-label small">Tank (L)</label><input class="form-control form-control-sm" type="number" min="0" name="tank_litres" value="{{ $g?->tank_litres }}"></div>
    @if ($switches->isNotEmpty())
        <div class="col-12"><label class="form-label small">Its switch on the equipment board</label>
            <select class="form-select form-select-sm" name="device_id"><option value="">— (record started / stopped here)</option>@foreach ($switches as $sw)<option value="{{ $sw->id }}" @selected($g?->device_id === $sw->id)>{{ $sw->area ? $sw->area . ': ' : '' }}{{ $sw->name }}</option>@endforeach</select>
            <div class="form-text">Running or not is then read from the board (and a hub reporting it).</div></div>
    @endif
    <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act-{{ $g?->id ?? 'new' }}" @checked($g?->is_active ?? true)><label class="form-check-label small" for="act-{{ $g?->id ?? 'new' }}">In use (can be suggested)</label></div></div>
</div>
