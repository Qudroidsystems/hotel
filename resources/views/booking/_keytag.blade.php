{{-- The stay as a hotel key fob. $in, $out (Carbon), $title, $ref (optional), $sub (optional) --}}
<div class="keytag">
    @if (! empty($ref))<div class="ref">Booking {{ $ref }}</div>@endif
    <div class="room">{{ $title }}</div>
    <div class="dates">
        <div class="d"><div class="lbl">Check-in</div><div class="day">{{ $in->format('j') }}</div><div class="mon">{{ $in->format($in->year === now()->year ? 'M, D' : 'M Y, D') }}</div></div>
        <div class="d"><div class="lbl">Check-out</div><div class="day">{{ $out->format('j') }}</div><div class="mon">{{ $out->format($out->year === now()->year ? 'M, D' : 'M Y, D') }}</div></div>
        <div class="nights">{{ $in->diffInDays($out) }} night{{ $in->diffInDays($out) == 1 ? '' : 's' }}@if (! empty($sub))<br>{{ $sub }}@endif</div>
    </div>
</div>
