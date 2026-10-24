{{-- Building → floor → room type selects. Floors are filtered to the chosen building. --}}
<div class="col-md-4">
    <label for="building_id">Building <span class="text-danger">*</span></label>
    <select name="building_id" id="building_id" class="form-select" required>
        <option value="">Select…</option>
        @foreach ($buildings as $b)
            <option value="{{ $b->id }}" @selected(old('building_id', $room->building_id) == $b->id)>{{ $b->name }}</option>
        @endforeach
    </select>
</div>
<div class="col-md-4">
    <label for="floor_id">Floor <span class="text-danger">*</span></label>
    <select name="floor_id" id="floor_id" class="form-select" required>
        <option value="">Select…</option>
        @foreach ($buildings as $b)
            @foreach ($b->floors as $f)
                <option value="{{ $f->id }}" data-building="{{ $b->id }}" @selected(old('floor_id', $room->floor_id) == $f->id)>{{ $f->name }}</option>
            @endforeach
        @endforeach
    </select>
</div>
<div class="col-md-4">
    <label for="room_type_id">Room type <span class="text-danger">*</span></label>
    <select name="room_type_id" id="room_type_id" class="form-select" required>
        <option value="">Select…</option>
        @foreach ($roomTypes as $t)
            <option value="{{ $t->id }}" data-rate="{{ $t->base_rate }}" @selected(old('room_type_id', $room->room_type_id) == $t->id)>{{ $t->name }} — @money($t->base_rate)</option>
        @endforeach
    </select>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const b = document.getElementById('building_id'), f = document.getElementById('floor_id');
    function filterFloors() {
        Array.from(f.options).forEach(o => {
            if (!o.dataset.building) return;
            const show = !b.value || o.dataset.building === b.value;
            o.hidden = !show; o.disabled = !show;
        });
        if (f.selectedOptions[0]?.disabled) f.value = '';
    }
    b.addEventListener('change', filterFloors); filterFloors();
});
</script>
