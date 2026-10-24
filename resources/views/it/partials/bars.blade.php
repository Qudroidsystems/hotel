{{-- @include('it.partials.bars', ['checks' => $collection, 'n' => 30]) --}}
@php $pad = max(0, ($n ?? 30) - $checks->count()); @endphp
<div class="it-bars" aria-label="Recent checks">
    @for ($i = 0; $i < $pad; $i++)<span></span>@endfor
    @foreach ($checks as $c)<span class="{{ $c->status }}" title="{{ $c->checked_at->format('j M, g:i A') }} — {{ \App\Models\It\ItMonitor::STATUSES[$c->status][0] ?? $c->status }}{{ $c->latency_ms ? ' · ' . $c->latency_ms . ' ms' : '' }}: {{ $c->message }}"></span>@endforeach
</div>
