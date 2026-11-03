@php
    $sym = config('hotel.currency_symbol');
    $cols = [
        'requested' => ['To collect', 'ri-hand-heart-line', 'No bags waiting. Guests ask from the guest app or their booking page.'],
        'collected' => ['At the laundry', 'ri-t-shirt-air-line', 'Nothing at the laundry.'],
        'ready'     => ['Ready to take up', 'ri-arrow-up-circle-line', 'Nothing waiting to go up.'],
    ];
@endphp
<div class="ld-cols">
    @foreach ($cols as $status => [$title, $icon, $empty])
        @php $bags = $live->where('status', $status); @endphp
        <section class="ld-col">
            <h6><i class="{{ $icon }}"></i> {{ $title }} <span class="n">{{ $bags->count() }}</span></h6>
            @forelse ($bags as $o)
                @php $vip = (bool) $o->reservation?->guest?->is_vip; $late = $o->isLate(); @endphp
                <article class="ld-bag st-{{ $o->status }} {{ $late ? 'late' : '' }}">
                    <header>
                        <span class="ld-room">{{ $o->room_number ?: '—' }}</span>
                        <div class="ld-who"><strong>{{ $o->guest_name }}</strong>@if ($vip) <span class="status-pill st-warning">VIP</span>@endif @if ($o->express) <span class="status-pill st-danger">Express</span>@endif
                            <div class="small text-muted">{{ $o->number }} · {{ $o->status === 'requested' ? 'asked ' . $o->created_at->format('g:i A') . ' (' . $o->created_at->diffForHumans(short: true) . ')' : 'collected ' . $o->collected_at?->format('D g:i A') . ($o->collector ? ' by ' . $o->collector->name : '') }}</div></div>
                    </header>
                    <div class="ld-what">
                        @if ($o->lines){{ $o->summary }} @if ($o->status !== 'requested')<span class="text-muted">· {{ $o->pieces }} pcs · {{ $sym }}{{ number_format((float) $o->total, 2) }}</span>@endif
                        @else<span class="text-muted">No list — count the bag when you collect it.</span>@endif
                    </div>
                    @if ($o->note)<div class="ld-note">“{{ $o->note }}”</div>@endif
                    @if ($o->issue)<div class="ld-issue"><i class="ri-error-warning-line"></i> {{ $o->issue }}</div>@endif
                    @if ($o->due_at && $o->status !== 'requested')<div class="ld-due {{ $late ? 'late' : '' }}">{{ $late ? 'Overdue — was due ' : 'Due back ' }}{{ $o->due_at->isToday() ? $o->due_at->format('g:i A') . ' today' : $o->due_at->format('D g:i A') }}</div>@endif
                    @if ($canHandle)
                        <div class="ld-actions">
                            @if ($o->status === 'requested')
                                <a href="{{ route('laundry.collect.form', $o) }}" class="action-btn btn-go"><i class="ri-list-check-2"></i> Collect &amp; count</a>
                                <form method="POST" action="{{ route('laundry.act', [$o, 'decline']) }}" data-once>@csrf
                                    <input type="text" name="note" class="form-control form-control-sm" maxlength="255" required placeholder="Reason, to decline">
                                    <button class="action-btn btn-more">Decline</button></form>
                            @else
                                @if ($o->status === 'collected')
                                    <form method="POST" action="{{ route('laundry.act', [$o, 'ready']) }}" data-once>@csrf<button class="action-btn btn-go w-100"><i class="ri-check-line"></i> Ready</button></form>
                                @endif
                                <form method="POST" action="{{ route('laundry.act', [$o, 'returned']) }}" data-once>@csrf<button class="action-btn {{ $o->status === 'ready' ? 'btn-go' : 'btn-more' }} w-100"><i class="ri-door-open-line"></i> Returned to room</button></form>
                                <form method="POST" action="{{ route('laundry.act', [$o, 'issue']) }}" data-once class="w-100">@csrf
                                    <input type="text" name="note" class="form-control form-control-sm" maxlength="500" required placeholder="Damaged or missing piece?">
                                    <button class="action-btn btn-more">Record</button></form>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="ld-empty">{{ $empty }}</div>
            @endforelse
        </section>
    @endforeach
</div>

<x-cb.card title="Since yesterday" icon="ri-history-line" :flush="true" class="mt-3">
    @if ($done->isEmpty())
        <div class="small text-muted p-3">Nothing finished yet.</div>
    @else
        <table class="cb-table stack">
            <thead><tr><th>Bag</th><th>Room</th><th>What</th><th class="num">Charged</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($done as $o)
                    <tr>
                        <td data-label="Bag" class="small">{{ $o->number }}<div class="text-muted">{{ $o->updated_at->format('D g:i A') }}</div></td>
                        <td data-label="Room">{{ $o->room_number }}</td>
                        <td data-label="What" class="small">{{ $o->lines ? $o->summary : '—' }}@if ($o->staff_note && $o->status === 'declined')<div class="text-muted">{{ $o->staff_note }}</div>@endif @if ($o->issue)<div class="ld-issue">{{ $o->issue }}</div>@endif</td>
                        <td data-label="Charged" class="num">{{ $o->folio_charge_id ? $sym . number_format((float) $o->total, 2) : '—' }}</td>
                        <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->staff_label }}</span>@if ($o->handler)<div class="small text-muted">{{ $o->handler->name }}</div>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-cb.card>
