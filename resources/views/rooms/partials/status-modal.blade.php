{{-- Shared "change room status" modal. Open it with a button carrying
     data-status-room="{id}" data-status-number="{number}" data-status-current="{value}". --}}
@can('Change room status')
<div class="modal fade" id="roomStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" id="roomStatusForm" action="">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="ri-door-open-line me-1"></i> Room <span id="rsmNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Current status: <strong id="rsmCurrent"></strong></p>
                <label class="form-label fw-semibold">New status</label>
                <div class="d-grid gap-2 mb-3" style="grid-template-columns: 1fr 1fr;">
                    @foreach (\App\Enums\RoomStatus::cases() as $st)
                        <label class="rm-chip m-0" style="--st: {{ $st->color() }}; cursor:pointer;">
                            <input type="radio" name="status" value="{{ $st->value }}" class="form-check-input m-0" data-needs-reason="{{ $st->requiresReason() ? 1 : 0 }}" required>
                            <span class="dot"></span> {{ $st->label() }}
                        </label>
                    @endforeach
                </div>
                <label class="form-label fw-semibold" for="rsmReason">Reason / note <span class="text-muted fw-normal" id="rsmReasonHint">(optional)</span></label>
                <textarea class="form-control" name="reason" id="rsmReason" rows="2" maxlength="500" placeholder="e.g. AC not cooling — technician booked"></textarea>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-light me-auto" id="rsmDetails"><i class="ri-information-line"></i> Room details</a>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update status</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('roomStatusModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('roomStatusForm');
    const reason = document.getElementById('rsmReason');
    const hint = document.getElementById('rsmReasonHint');
    const labels = @json(\App\Enums\RoomStatus::options());
    const statusUrl = @json(route('rooms.status', ['room' => '__ID__']));
    const showUrl = @json(route('rooms.show', ['room' => '__ID__']));

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-status-room]');
        if (!btn) return;
        e.preventDefault();
        const id = btn.dataset.statusRoom;
        form.action = statusUrl.replace('__ID__', id);
        document.getElementById('rsmDetails').href = showUrl.replace('__ID__', id);
        document.getElementById('rsmNumber').textContent = btn.dataset.statusNumber;
        document.getElementById('rsmCurrent').textContent = labels[btn.dataset.statusCurrent] || btn.dataset.statusCurrent;
        form.querySelectorAll('input[name=status]').forEach(r => { r.checked = r.value === btn.dataset.statusCurrent; });
        reason.value = ''; reason.required = false; hint.textContent = '(optional)';
        modal.show();
    });

    form.addEventListener('change', function (e) {
        if (e.target.name !== 'status') return;
        const needs = e.target.dataset.needsReason === '1';
        reason.required = needs;
        hint.textContent = needs ? '(required)' : '(optional)';
    });
});
</script>
@endcan
