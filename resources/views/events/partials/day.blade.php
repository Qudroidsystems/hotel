{{-- On the day: hand the hall over, take it back (attendance, condition, overtime, damage). --}}
@php
    $usage = app(\App\Services\Events\HallUsageService::class);
    $ot = $e->checked_in_at && ! $e->checked_out_at ? $usage->overtimeCharge($e) : null;
@endphp
@if ($e->status === 'confirmed' || $e->checked_in_at)
<x-cb.card title="On the day" icon="ri-door-open-line">
    @if (! $e->checked_in_at)
        <div class="small text-muted mb-2">When the organiser arrives, hand over the hall here. The time is recorded.</div>
        @can('Manage events')
            <form method="POST" action="{{ route('events.check-in', $e) }}" class="rm-form" data-once>@csrf
                <input name="notes" class="form-control form-control-sm mb-2" maxlength="1000" placeholder="Notes (optional): keys, AV handed over…">
                <button class="action-btn btn-go w-100"><i class="ri-login-box-line"></i> Hand over the hall</button>
            </form>
        @endcan
    @else
        <div class="small py-1 d-flex justify-content-between"><span>Handed over</span><span>{{ $e->checked_in_at->format('D j M, g:i A') }}</span></div>
        @if ($e->checked_out_at)
            <div class="small py-1 d-flex justify-content-between"><span>Given back</span><span>{{ $e->checked_out_at->format('D j M, g:i A') }}</span></div>
            <div class="small py-1 d-flex justify-content-between"><span>Came</span><span>{{ $e->guests_actual ?? '—' }} of {{ $e->guests_expected }} expected</span></div>
            <div class="small py-1 d-flex justify-content-between"><span>Hall left</span><span class="{{ $e->hall_condition === 'damaged' ? 'text-danger fw-semibold' : '' }}">{{ \App\Services\Events\HallUsageService::CONDITIONS[$e->hall_condition] ?? '—' }}</span></div>
            @if ($e->usage_notes)<div class="small text-muted mt-1" style="white-space:pre-line">{{ $e->usage_notes }}</div>@endif
            <div class="small text-muted mt-1">By {{ $e->checkedInBy?->name ?? '—' }} / {{ $e->checkedOutBy?->name ?? '—' }}</div>
        @else
            <div class="cb-banner info small my-2"><i class="ri-time-line"></i><div>In the hall now. Booked until {{ $e->ends_at->format('g:i A, D j M') }}.@if ($ot && $ot['hours'] > 0) <strong>Running over by {{ $usage->overtimeMinutes($e) }} min.</strong>@endif</div></div>
            @can('Manage events')
                <button type="button" class="action-btn btn-open w-100" data-bs-toggle="modal" data-bs-target="#checkOutModal"><i class="ri-logout-box-r-line"></i> Take the hall back</button>
            @endcan
        @endif
    @endif
</x-cb.card>

@can('Manage events')
@if ($e->checked_in_at && ! $e->checked_out_at)
<div class="modal fade" id="checkOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.check-out', $e) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Take back {{ $e->hall->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label for="co_guests">How many came</label><input type="number" min="0" max="20000" name="guests_actual" id="co_guests" class="form-control" value="{{ old('guests_actual', $e->tickets()->where('status', 'used')->count() ?: '') }}" placeholder="{{ $e->guests_expected }} expected"></div>
                <div class="col-sm-6"><label for="co_cond">State of the hall</label><select name="hall_condition" id="co_cond" class="form-select">@foreach (\App\Services\Events\HallUsageService::CONDITIONS as $k => $l)<option value="{{ $k }}" @selected(old('hall_condition') === $k)>{{ $l }}</option>@endforeach</select></div>
                @if ($ot && $ot['amount'] > 0)
                    <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="charge_overtime" value="1" checked> Charge overtime: {{ $ot['hours'] }} h × {{ $sym . number_format($ot['rate'], 2) }} = <strong>{{ $sym . number_format($ot['amount'], 2) }}</strong> <span class="small text-muted">(ran past {{ $e->ends_at->format('g:i A') }})</span></label></div>
                @endif
                <div class="col-12"><label>Damage or loss to charge <span class="small text-muted">(optional)</span></label>
                    @for ($i = 0; $i < 3; $i++)
                        <div class="row g-2 mb-1"><div class="col-8"><input name="damages[{{ $i }}][description]" class="form-control form-control-sm" maxlength="150" placeholder="e.g. 2 broken chairs"></div><div class="col-4"><input type="number" step="0.01" min="0" name="damages[{{ $i }}][amount]" class="form-control form-control-sm" placeholder="Amount"></div></div>
                    @endfor
                </div>
                <div class="col-12"><label for="co_notes">Notes</label><textarea name="usage_notes" id="co_notes" class="form-control" rows="2" maxlength="1000">{{ old('usage_notes', $e->usage_notes) }}</textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Take it back</button></div>
        </form>
    </div>
</div>
@endif
@endcan
@endif
