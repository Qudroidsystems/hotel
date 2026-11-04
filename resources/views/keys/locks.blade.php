@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid" style="max-width:900px">
    <x-cb.hero :title="$pagetitle" icon="ri-lock-2-line" :subtitle="'Link each room to its lock in ' . $provider->label() . '. Use the lock id from the lock maker\'s app or server (for TTLock: the Lock ID shown on the lock\'s page).'">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('keys.index') }}"><i class="ri-arrow-left-line"></i> Room keys</a></x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    <form method="POST" action="{{ route('keys.locks.save') }}">@csrf
        <x-cb.card title="Rooms" icon="ri-hotel-bed-line" :flush="true">
            <table class="cb-table">
                <thead><tr><th>Room</th><th>Floor</th><th>Lock id</th></tr></thead>
                <tbody>
                    @foreach ($rooms as $room)
                        <tr><td class="fw-semibold">{{ $room->number }}</td><td class="small text-muted">{{ $room->floor?->name ?? '—' }}</td>
                            <td><input name="lock[{{ $room->id }}]" class="form-control form-control-sm" maxlength="60" value="{{ $room->lock_id }}" aria-label="Lock id for room {{ $room->number }}"></td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3 d-flex justify-content-end"><button class="btn btn-primary">Save</button></div>
        </x-cb.card>
    </form>
</div>
</div>
</div>
@endsection
