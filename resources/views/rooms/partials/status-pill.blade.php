{{-- @include('rooms.partials.status-pill', ['status' => $room->status]) --}}
<span class="rm-status" style="--st: {{ $status->color() }}"><i class="{{ $status->icon() }}"></i>{{ $status->label() }}</span>
