@php $sym = config('hotel.currency_symbol'); @endphp
<div id="ro-live" data-new="{{ $live->where('status', 'placed')->pluck('id')->implode(',') }}">
    @if ($live->isEmpty())
        <div class="empty-state py-5"><i class="ri-restaurant-line"></i><h6>No room orders right now</h6>
            <p class="small text-muted mb-0">New orders from the guest app and the QR cards in the rooms appear here and on the staff app. This page checks every 20 seconds.</p></div>
    @else
        <div class="ro-grid">
            @foreach ($live as $o)
                @php $vip = (bool) $o->reservation?->guest?->is_vip; $late = $o->status === 'placed' && $o->created_at->lt(now()->subMinutes(5)); @endphp
                <article class="ro-ticket st-{{ $o->status }} {{ $late ? 'late' : '' }}">
                    <header>
                        <span class="ro-room">{{ $o->room_number ?: '—' }}</span>
                        <div class="ro-who"><strong>{{ $o->guest_name }}</strong>@if ($vip) <span class="status-pill st-warning">VIP</span>@endif
                            <div class="small text-muted">{{ $o->number }} · {{ $o->outlet?->name }} · {{ $o->created_at->format('g:i A') }} ({{ $o->created_at->diffForHumans(short: true) }})</div></div>
                        <span class="status-pill {{ $o->status_pill }}">{{ $o->staff_label }}</span>
                    </header>
                    <ul class="ro-lines">
                        @foreach ($o->lines as $l)
                            <li><b>{{ rtrim(rtrim(number_format((float) $l['quantity'], 2), '0'), '.') }}×</b> {{ $l['name'] }}@if (! empty($l['note']))<div class="ro-note">{{ $l['note'] }}</div>@endif</li>
                        @endforeach
                    </ul>
                    @if ($o->note)<div class="ro-note mb-2">“{{ $o->note }}”</div>@endif
                    <div class="ro-foot">
                        <span class="fw-semibold">{{ $sym }}{{ number_format((float) $o->total, 2) }}</span>
                        @if ($o->handler && $o->status !== 'placed')<span class="small text-muted">{{ $o->handler->name }}</span>@endif
                    </div>
                    <div class="ro-actions">
                        @if ($o->status === 'placed')
                            <form method="POST" action="{{ route('pos.room-orders.act', [$o, 'accept']) }}" data-once>@csrf
                                <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Message to the guest (optional)">
                                <button class="action-btn btn-go w-100"><i class="ri-check-line"></i> Accept — add to bill</button></form>
                            <form method="POST" action="{{ route('pos.room-orders.act', [$o, 'decline']) }}" data-once class="ro-decline">@csrf
                                <input type="text" name="note" class="form-control form-control-sm" maxlength="255" required placeholder="Reason, if declining (the guest sees it)">
                                <button class="action-btn btn-more"><i class="ri-close-line"></i> Decline</button></form>
                        @else
                            @if ($o->status === 'accepted')
                                <form method="POST" action="{{ route('pos.room-orders.act', [$o, 'send']) }}" data-once>@csrf<button class="action-btn btn-go w-100"><i class="ri-e-bike-2-line"></i> On the way</button></form>
                            @endif
                            <form method="POST" action="{{ route('pos.room-orders.act', [$o, 'deliver']) }}" data-once>@csrf<button class="action-btn {{ $o->status === 'on_the_way' ? 'btn-go' : 'btn-more' }} w-100"><i class="ri-checkbox-circle-line"></i> Delivered</button></form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>

<x-cb.card title="Earlier today" icon="ri-history-line" :flush="true" class="mt-3">
    @if ($done->isEmpty())
        <div class="small text-muted p-3">Nothing yet today.</div>
    @else
        <table class="cb-table stack">
            <thead><tr><th>Order</th><th>Room</th><th>What</th><th class="num">Total</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($done as $o)
                    <tr>
                        <td data-label="Order" class="small">{{ $o->number }}<div class="text-muted">{{ $o->updated_at->format('g:i A') }} · {{ $o->outlet?->name }}</div></td>
                        <td data-label="Room">{{ $o->room_number }}</td>
                        <td data-label="What" class="small">{{ $o->summary }}@if ($o->staff_note && $o->status === 'declined')<div class="text-muted">{{ $o->staff_note }}</div>@endif</td>
                        <td data-label="Total" class="num">{{ $sym }}{{ number_format((float) $o->total, 2) }}</td>
                        <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->staff_label }}</span>@if ($o->handler)<div class="small text-muted">{{ $o->handler->name }}</div>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-cb.card>
