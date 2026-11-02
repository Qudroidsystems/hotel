@php $id = $z?->id ?? 'new'; @endphp
<div class="row g-2">
    <div class="col-md-6"><label class="form-label small">Name</label><input class="form-control form-control-sm" name="name" required maxlength="80" value="{{ $z?->name }}"></div>
    <div class="col-md-6"><label class="form-label small">Kind</label>
        <select class="form-select form-select-sm" name="kind">@foreach (\App\Models\Energy\EnergyZone::KINDS as $k => $l)<option value="{{ $k }}" @selected(($z?->kind ?? 'common') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label small">Watts when on</label><input class="form-control form-control-sm" type="number" min="0" name="watts" required value="{{ $z?->watts ?? 2000 }}"></div>
    <div class="col-md-3"><label class="form-label small">Watts when down</label><input class="form-control form-control-sm" type="number" min="0" name="low_watts" value="{{ $z?->low_watts ?? 0 }}"><div class="form-text">Security lights, fridges</div></div>
    <div class="col-md-3"><label class="form-label small">On from</label><input class="form-control form-control-sm" type="time" name="opens" value="{{ $z?->opens }}"></div>
    <div class="col-md-3"><label class="form-label small">until</label><input class="form-control form-control-sm" type="time" name="closes" value="{{ $z?->closes }}"><div class="form-text">Empty = by its link below</div></div>
    <div class="col-md-4"><label class="form-label small">A floor's corridor</label><select class="form-select form-select-sm" name="floor_id"><option value="">—</option>@foreach ($floors as $f)<option value="{{ $f->id }}" @selected($z?->floor_id === $f->id)>{{ $f->building ? $f->building->name . ' · ' : '' }}{{ $f->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label small">A hall (on around events)</label><select class="form-select form-select-sm" name="hall_id"><option value="">—</option>@foreach ($halls as $h)<option value="{{ $h->id }}" @selected($z?->hall_id === $h->id)>{{ $h->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label small">An outlet (its hours)</label><select class="form-select form-select-sm" name="outlet_id"><option value="">—</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected($z?->outlet_id === $o->id)>{{ $o->name }}</option>@endforeach</select></div>
    <div class="col-md-8"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes" maxlength="255" value="{{ $z?->notes }}" placeholder="e.g. switchboard DB-3 in the plant room"></div>
    <div class="col-md-4 d-flex flex-column justify-content-end">
        <div class="form-check"><input type="hidden" name="always_on" value="0"><input class="form-check-input" type="checkbox" name="always_on" value="1" id="ao-{{ $id }}" @checked($z?->always_on)><label class="form-check-label small" for="ao-{{ $id }}">Always on (never suggest)</label></div>
        <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="za-{{ $id }}" @checked($z?->is_active ?? true)><label class="form-check-label small" for="za-{{ $id }}">Counted</label></div>
    </div>
</div>
