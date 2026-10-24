@foreach ($reservation->rooms as $line)
    @if ($line->room)
        <span class="rs-room-chip" title="{{ $line->roomType->name }}"><span style="width:7px;height:7px;border-radius:50%;background:{{ $line->roomType->color }}"></span>{{ $line->room->number }}</span>
    @else
        <span class="rs-room-chip unassigned" title="No room assigned yet">{{ $line->roomType->code }} · ?</span>
    @endif
@endforeach
