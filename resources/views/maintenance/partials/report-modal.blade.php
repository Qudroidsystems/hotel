{{--
    "Report a fault" form. Needs: $rooms. Optional: $technicians (managers can assign),
    $back = 'housekeeping' to return to the previous page.
    Open with data-bs-toggle="modal" data-bs-target="#faultModal"; a button may carry
    data-room="{id}" and data-task="{housekeeping task id}" to pre-fill.
--}}
<div class="modal fade" id="faultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ route('maintenance.store') }}" enctype="multipart/form-data" data-once>
            @csrf
            @isset($back)<input type="hidden" name="back" value="{{ $back }}">@endisset
            <input type="hidden" name="housekeeping_task_id" id="ftTask" value="{{ old('housekeeping_task_id') }}">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-tools-line me-1"></i> Report a fault</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-4"><label for="ftRoom">Room</label>
                    <select name="room_id" id="ftRoom" class="form-select">
                        <option value="">Not a room</option>
                        @foreach ($rooms as $r)<option value="{{ $r->id }}" @selected((int) old('room_id') === $r->id)>{{ $r->number }}</option>@endforeach
                    </select></div>
                <div class="col-md-8"><label for="ftLocation">Or where</label>
                    <input type="text" name="location" id="ftLocation" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="e.g. Kitchen, generator house, lobby toilet"></div>
                <div class="col-md-6"><label for="ftCategory">What kind of problem</label>
                    <select name="category" id="ftCategory" class="form-select" required>
                        @foreach (\App\Models\MaintenanceOrder::CATEGORIES as $key => [$label])<option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>@endforeach
                    </select></div>
                <div class="col-md-6"><label for="ftPriority">How urgent</label>
                    <select name="priority" id="ftPriority" class="form-select" required>
                        @foreach (\App\Models\MaintenanceOrder::PRIORITIES as $key => [$label, , $hours])<option value="{{ $key }}" @selected(old('priority', 'normal') === $key)>{{ $label }} — fix within {{ $hours < 48 ? $hours . ' hours' : intdiv($hours, 24) . ' days' }}</option>@endforeach
                    </select></div>
                <div class="col-12"><label for="ftTitle">Problem</label>
                    <input type="text" name="title" id="ftTitle" class="form-control" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. AC not cooling, leaking tap in bathroom"></div>
                <div class="col-12"><label for="ftDesc">Details <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="description" id="ftDesc" class="form-control" rows="2" maxlength="2000">{{ old('description') }}</textarea></div>
                <div class="col-md-6"><label for="ftPhotos">Photos <span class="text-muted fw-normal">(up to 4)</span></label>
                    <input type="file" name="photos[]" id="ftPhotos" class="form-control" accept="image/*" multiple capture="environment"></div>
                @if (! empty($technicians) && $technicians->isNotEmpty() && auth()->user()->can('Manage maintenance'))
                    <div class="col-md-6"><label for="ftAssign">Assign to</label>
                        <select name="assigned_to" id="ftAssign" class="form-select"><option value="">Not yet</option>@foreach ($technicians as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                @endif
                <div class="col-12"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="blocks_room" value="1" id="ftBlocks" @checked(old('blocks_room'))>
                    <span class="form-check-label fw-normal">Keep the room off sale until this is fixed</span></label>
                    <div class="form-text">An occupied room stays with its guest and is held back when they check out.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open"><i class="ri-send-plane-line"></i> Report</button></div>
        </form>
    </div>
</div>
<script>
document.getElementById('faultModal')?.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget; if (!b) return;
    if (b.dataset.room) document.getElementById('ftRoom').value = b.dataset.room;
    document.getElementById('ftTask').value = b.dataset.task || '';
});
</script>
