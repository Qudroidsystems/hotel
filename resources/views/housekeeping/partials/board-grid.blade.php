@php
    $me = auth()->user();
    $doer = $me->can('Do housekeeping tasks');
    $labels = ['clean' => 'To clean', 'cleaning' => 'Being cleaned', 'inspect' => 'To inspect', 'ready' => 'Ready', 'occupied' => 'Guest in', 'blocked' => 'Out of order'];
@endphp
<div class="hb-counts" id="hb-counts">
    @foreach (['clean', 'cleaning', 'inspect', 'ready'] as $s)
        @if ($s !== 'inspect' || $inspection)
            <div class="hb-count s-{{ $s }}"><b>{{ $counts[$s] ?? 0 }}</b><span>{{ $labels[$s] }}</span></div>
        @endif
    @endforeach
</div>
@forelse ($floors as $floor => $tiles)
    <section class="hb-floor">
        <h2>{{ $floor }}</h2>
        <div class="hb-grid">
            @foreach ($tiles as $t)
                @php
                    $task = $t->task;
                    $canWork = $task && ($manager || ($doer && in_array($task->assigned_to, [null, $me->id])));
                    $minutes = $task?->started_at ? (int) $task->started_at->diffInMinutes(now()) : null;
                @endphp
                <button type="button" class="hb-tile s-{{ $t->state }} {{ $task?->urgent ? 'urgent' : '' }}"
                    data-room="{{ $t->room->number }}" data-type="{{ $t->room->roomType?->name }}" data-state="{{ $t->state }}"
                    data-task="{{ $task?->id }}" data-task-type="{{ $task?->type_label }}" data-assignee="{{ $task?->assigned_to }}" data-assignee-name="{{ $task?->assignee?->name }}"
                    data-notes="{{ $task?->notes }}" data-can-work="{{ $canWork ? 1 : 0 }}"
                    data-arriving="{{ $t->arriving ? $t->arriving->guest?->full_name . ($t->arriving->guest?->is_vip ? ' (VIP)' : '') : '' }}">
                    <span class="hb-no">{{ $t->room->number }}</span>
                    <span class="hb-state">{{ $labels[$t->state] }}@if ($t->state === 'cleaning' && $minutes !== null) · {{ $minutes < 1 ? 'just started' : ($minutes < 60 ? $minutes . ' min' : intdiv($minutes, 60) . ' h ' . ($minutes % 60)) }}@endif</span>
                    <span class="hb-flags">
                        @if ($task?->urgent)<span class="hb-flag"><i class="ri-flashlight-fill"></i> Rush</span>@endif
                        @if ($t->arriving)<span class="hb-flag"><i class="ri-login-box-line"></i> Arriving{{ $t->arriving->guest?->is_vip ? ' · VIP' : '' }}</span>@endif
                        @if ($t->leaving)<span class="hb-flag"><i class="ri-logout-box-r-line"></i> Due out</span>@endif
                        @if ($task && $task->type !== 'checkout')<span class="hb-flag">{{ $task->type_label }}</span>@endif
                    </span>
                    @if ($task?->assignee)<span class="hb-who"><i class="ri-user-line"></i> {{ $t->mine ? 'You' : $task->assignee->name }}</span>@endif
                </button>
            @endforeach
        </div>
    </section>
@empty
    <div class="hb-empty"><i class="ri-checkbox-circle-line"></i><p>{{ $mine ? 'No rooms for you right now.' : 'No rooms set up yet.' }}</p></div>
@endforelse
<div hidden id="hb-stamp" data-at="{{ now()->format('H:i') }}"></div>
