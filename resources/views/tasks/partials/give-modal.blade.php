{{-- "Give a task" form. Needs: $departments, $people, $rooms. Open with data-bs-target="#taskModal". --}}
<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ route('tasks.store') }}" enctype="multipart/form-data" data-once>
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-task-line me-1"></i> Give a task</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="tkTitle">What needs doing</label>
                    <input type="text" name="title" id="tkTitle" class="form-control" maxlength="160" required value="{{ old('title') }}" placeholder="e.g. Set up the conference hall for 40 people"></div>
                <div class="col-12"><label for="tkDetails">Details <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="details" id="tkDetails" class="form-control" rows="2" maxlength="3000">{{ old('details') }}</textarea></div>
                <div class="col-md-6"><label for="tkDept">Department</label>
                    <select name="department_id" id="tkDept" class="form-select">
                        <option value="">—</option>
                        @foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) old('department_id') === $d->id)>{{ $d->name }}</option>@endforeach
                    </select></div>
                <div class="col-md-6"><label for="tkPerson">Person <span class="text-muted fw-normal">(or leave for anyone in the department)</span></label>
                    <select name="assigned_to" id="tkPerson" class="form-select">
                        <option value="">Anyone in the department</option>
                        @foreach ($people as $p)<option value="{{ $p->id }}" data-dept="{{ $p->staffProfile?->department_id }}" @selected((int) old('assigned_to') === $p->id)>{{ $p->name }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label for="tkPriority">Priority</label>
                    <select name="priority" id="tkPriority" class="form-select" required>
                        @foreach (\App\Models\StaffTask::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected(old('priority', 'normal') === $k)>{{ $label }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label for="tkDue">Due</label>
                    <input type="datetime-local" name="due_at" id="tkDue" class="form-control" value="{{ old('due_at') }}"></div>
                <div class="col-md-4"><label for="tkRoom">Room <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="room_id" id="tkRoom" class="form-select"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected((int) old('room_id') === $r->id)>{{ $r->number }}</option>@endforeach</select></div>
                <div class="col-md-6"><label for="tkLocation">Or where</label>
                    <input type="text" name="location" id="tkLocation" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="e.g. Main hall, kitchen, car park"></div>
                <div class="col-md-6"><label for="tkFiles">Photos or documents <span class="text-muted fw-normal">(up to 5)</span></label>
                    <input type="file" name="files[]" id="tkFiles" class="form-control" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"></div>
                <div class="col-12"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="needs_check" value="1" @checked(old('needs_check'))>
                    <span class="form-check-label fw-normal">A supervisor must check it before it counts as done</span></label></div>
                <div class="col-12 form-text mt-0">If it isn't done on time, the person and their department manager are reminded; if it's still not done after a while (1 hour for urgent, 2 for high, 4 for normal, a day for low), management is told.</div>
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open"><i class="ri-send-plane-line"></i> Give task</button></div>
        </form>
    </div>
</div>
<script>
// Picking a person fills in their department.
document.getElementById('tkPerson')?.addEventListener('change', function () {
    var d = this.selectedOptions[0]?.dataset.dept; if (d) document.getElementById('tkDept').value = d;
});
</script>
