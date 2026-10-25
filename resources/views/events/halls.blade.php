@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); $fmt = fn ($v) => $sym . number_format((float) $v, 0); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Halls & Packages" icon="ri-building-4-line" :back="route('events.index')" back-label="Events & Halls" subtitle="Each hall's capacities and hire rates, and the per-guest packages offered for events.">
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-hall="new"><i class="ri-add-line"></i> Add hall</button>
            <button type="button" class="cb-hero-btn" data-package="new"><i class="ri-add-line"></i> Add package</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    <x-cb.card title="Halls" icon="ri-building-4-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Hall</th><th>Capacity</th><th class="num">By the hour</th><th class="num">Half day</th><th class="num">Full day</th><th class="num">Events</th><th></th></tr></thead>
                <tbody>
                    @forelse ($halls as $h)
                        <tr style="{{ $h->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Hall" class="fw-semibold"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $h->color }}"></span> {{ $h->name }}@unless ($h->is_active) <span class="status-pill st-muted">Off</span>@endunless @if ($h->description)<div class="small text-muted fw-normal">{{ $h->description }}</div>@endif</td>
                            <td data-label="Capacity" class="small">{{ $h->capacity_label }}</td>
                            <td data-label="By the hour" class="num">{{ $fmt($h->hourly_rate) }}</td>
                            <td data-label="Half day" class="num">{{ $fmt($h->half_day_rate) }}</td>
                            <td data-label="Full day" class="num">{{ $fmt($h->full_day_rate) }}</td>
                            <td data-label="Events" class="num">{{ $h->events_count }}</td>
                            <td class="text-end"><button type="button" class="action-btn btn-more" data-hall="{{ json_encode($h->only(['id', 'name', 'capacity_theatre', 'capacity_banquet', 'capacity_classroom', 'capacity_boardroom', 'hourly_rate', 'half_day_rate', 'full_day_rate', 'description', 'color', 'sort_order', 'is_active'])) }}"><i class="ri-pencil-line"></i></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No halls yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

    <x-cb.card title="Packages (per guest)" icon="ri-restaurant-2-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Package</th><th>Includes</th><th class="num">Per guest</th><th></th></tr></thead>
                <tbody>
                    @forelse ($packages as $p)
                        <tr style="{{ $p->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Package" class="fw-semibold">{{ $p->name }}@unless ($p->is_active) <span class="status-pill st-muted">Off</span>@endunless</td>
                            <td data-label="Includes" class="small">{{ $p->description ?: '—' }}</td>
                            <td data-label="Per guest" class="num">{{ $fmt($p->price_per_person) }}</td>
                            <td class="text-end"><button type="button" class="action-btn btn-more" data-package="{{ json_encode($p->only(['id', 'name', 'price_per_person', 'description', 'is_active'])) }}"><i class="ri-pencil-line"></i></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">No packages yet, e.g. "Day delegate: tea break, lunch, water".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

</div>
</div>
</div>

<div class="modal fade" id="hallModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" id="hallForm">@csrf<input type="hidden" name="_method" id="hallMethod" value="POST">
            <div class="modal-header"><h5 class="modal-title" id="hallTitle">Add hall</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-8"><label for="h_name">Name</label><input name="name" id="h_name" class="form-control" maxlength="100" required></div>
                <div class="col-md-4"><label for="h_color">Colour</label><input type="color" name="color" id="h_color" class="form-control form-control-color w-100" value="#0f766e"></div>
                @foreach (['theatre' => 'Theatre', 'banquet' => 'Banquet', 'classroom' => 'Classroom', 'boardroom' => 'Boardroom'] as $k => $l)
                    <div class="col-6 col-md-3"><label for="h_capacity_{{ $k }}">{{ $l }} seats</label><input type="number" min="1" name="capacity_{{ $k }}" id="h_capacity_{{ $k }}" class="form-control"></div>
                @endforeach
                @foreach (['hourly_rate' => 'By the hour', 'half_day_rate' => 'Half day', 'full_day_rate' => 'Full day'] as $k => $l)
                    <div class="col-md-4"><label for="h_{{ $k }}">{{ $l }}</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="{{ $k }}" id="h_{{ $k }}" class="form-control"></div></div>
                @endforeach
                <div class="col-md-9"><label for="h_description">Description</label><input name="description" id="h_description" class="form-control" maxlength="500" placeholder="e.g. Ground floor, own entrance, air-conditioned, PA system"></div>
                <div class="col-md-3"><label for="h_sort_order">Order</label><input type="number" min="0" max="999" name="sort_order" id="h_sort_order" class="form-control" value="0"></div>
                <div class="col-12" id="h_active_wrap"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="h_is_active" checked> <span class="form-check-label">In use</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save hall</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="packageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" id="packageForm">@csrf<input type="hidden" name="_method" id="packageMethod" value="POST">
            <div class="modal-header"><h5 class="modal-title" id="packageTitle">Add package</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-8"><label for="p_name">Name</label><input name="name" id="p_name" class="form-control" maxlength="100" required placeholder="e.g. Full-day delegate"></div>
                <div class="col-md-4"><label for="p_price_per_person">Per guest</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="price_per_person" id="p_price_per_person" class="form-control" required></div></div>
                <div class="col-12"><label for="p_description">Includes</label><textarea name="description" id="p_description" class="form-control" rows="2" maxlength="500" placeholder="Two tea breaks, buffet lunch, bottled water, notepads"></textarea></div>
                <div class="col-12" id="p_active_wrap"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="p_is_active" checked> <span class="form-check-label">In use</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save package</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const wire = (attr, formId, modalId, store, update, fields, title) => {
        const form = document.getElementById(formId), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
        document.querySelectorAll('[data-' + attr + ']').forEach(b => b.addEventListener('click', () => {
            const x = b.dataset[attr] === 'new' ? null : JSON.parse(b.dataset[attr]);
            form.reset(); form.action = x ? update.replace('__ID__', x.id) : store;
            document.getElementById(attr + 'Method').value = x ? 'PUT' : 'POST';
            document.getElementById(attr + 'Title').textContent = x ? 'Edit ' + x.name : 'Add ' + title;
            const p = attr[0] + '_';
            document.getElementById(p + 'active_wrap').style.display = x ? '' : 'none';
            if (x) { fields.forEach(k => { const el = document.getElementById(p + k); if (el) el.value = x[k] ?? ''; }); document.getElementById(p + 'is_active').checked = !!x.is_active; }
            modal.show();
        }));
    };
    wire('hall', 'hallForm', 'hallModal', @json(route('events.halls.store')), @json(route('events.halls.update', '__ID__')),
        ['name', 'capacity_theatre', 'capacity_banquet', 'capacity_classroom', 'capacity_boardroom', 'hourly_rate', 'half_day_rate', 'full_day_rate', 'description', 'color', 'sort_order'], 'hall');
    wire('package', 'packageForm', 'packageModal', @json(route('events.packages.store')), @json(route('events.packages.update', '__ID__')),
        ['name', 'price_per_person', 'description'], 'package');
});
</script>
@endsection
