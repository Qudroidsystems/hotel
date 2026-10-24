@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Add Rooms in Bulk" icon="ri-stack-line"
               subtitle="Create a run of rooms on one floor in one step — for example rooms 101 to 120, all Deluxe. Numbers that already exist are skipped."
               :back="route('rooms.index')" back-label="Room Board" />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('rooms.bulk.store') }}" class="rm-form">
        @csrf
        <x-cb.card title="Rooms to create" icon="ri-door-line">
            <div class="row g-3">
                @include('rooms.partials.location-fields')

                <div class="col-md-4">
                    <label for="prefix">Prefix</label>
                    <input type="text" name="prefix" id="prefix" class="form-control" maxlength="5" value="{{ old('prefix') }}" placeholder="optional, e.g. B">
                </div>
                <div class="col-md-4">
                    <label for="start">From number <span class="text-danger">*</span></label>
                    <input type="number" name="start" id="start" class="form-control" min="0" required value="{{ old('start', 101) }}">
                </div>
                <div class="col-md-4">
                    <label for="end">To number <span class="text-danger">*</span></label>
                    <input type="number" name="end" id="end" class="form-control" min="0" required value="{{ old('end', 110) }}">
                </div>
                <div class="col-12">
                    <div class="cb-banner info mb-0"><i class="ri-eye-line"></i><div id="bulkPreview">—</div></div>
                </div>
            </div>
        </x-cb.card>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ route('rooms.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="ri-add-line"></i> Create rooms</button>
        </div>
    </form>

</div>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const p = document.getElementById('prefix'), s = document.getElementById('start'), e = document.getElementById('end'), out = document.getElementById('bulkPreview');
    function preview() {
        const a = parseInt(s.value, 10), b = parseInt(e.value, 10), pre = (p.value || '').toUpperCase();
        if (isNaN(a) || isNaN(b) || b < a) { out.textContent = 'Enter a valid range (the “to” number must be at least the “from” number).'; return; }
        const n = b - a + 1;
        if (n > 200) { out.textContent = n + ' rooms — the limit is 200 at a time.'; return; }
        out.innerHTML = 'Will create <strong>' + n + '</strong> room' + (n === 1 ? '' : 's') + ': <strong>' + pre + a + '</strong>' + (n > 1 ? ' … <strong>' + pre + b + '</strong>' : '');
    }
    [p, s, e].forEach(el => el.addEventListener('input', preview)); preview();
});
</script>
@endsection
