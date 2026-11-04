@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero :title="$pagetitle" icon="ri-door-open-line" subtitle="Every door opened from the system (the guest's phone, staff opening a door) and, with connected locks, the locks' own records of codes and cards used. Read in every 10 minutes.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('keys.index') }}"><i class="ri-arrow-left-line"></i> Room keys</a>
            <form method="POST" action="{{ route('keys.sync') }}" class="d-inline">@csrf<button class="cb-hero-btn"><i class="ri-refresh-line"></i> Read the locks now</button></form>
        </x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    <form method="GET" class="mb-3" style="max-width:240px"><select name="room" class="form-select" onchange="this.form.submit()" aria-label="Room"><option value="">Every room</option>@foreach ($rooms as $rm)<option value="{{ $rm->id }}" @selected($room === $rm->id)>Room {{ $rm->number }}</option>@endforeach</select></form>
    <x-cb.card title="Door openings" icon="ri-list-check" :flush="true">
        <table class="cb-table stack">
            <thead><tr><th>When</th><th>Room</th><th>How</th><th>Who</th><th>Result</th></tr></thead>
            <tbody>
                @forelse ($events as $e)
                    <tr>
                        <td data-label="When" class="small">{{ $e->at->format('D j M, g:i:s A') }}</td>
                        <td data-label="Room" class="fw-semibold">{{ $e->room?->number }}</td>
                        <td data-label="How">{{ $e->kind_label }}@if ($e->note)<div class="small text-muted">{{ $e->note }}</div>@endif</td>
                        <td data-label="Who" class="small">{{ $e->user?->name ?? $e->who ?? '—' }}</td>
                        <td data-label="Result"><span class="status-pill {{ $e->ok ? 'st-success' : 'st-danger' }}">{{ $e->ok ? 'Opened' : 'Failed' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="small text-muted">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-3 py-2">{{ $events->links() }}</div>
    </x-cb.card>
</div>
</div>
</div>
@endsection
