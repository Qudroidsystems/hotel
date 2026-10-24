@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$room->exists ? 'Edit Room ' . $room->number : 'Add a Room'" icon="ri-door-line"
               subtitle="Rooms belong to a building, a floor and a room type. The rate comes from the room type unless you set a custom rate."
               :back="$room->exists ? route('rooms.show', $room) : route('rooms.index')" :back-label="$room->exists ? 'Room ' . $room->number : 'Room Board'" />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ $room->exists ? route('rooms.update', $room) : route('rooms.store') }}" class="rm-form">
        @csrf
        @if ($room->exists) @method('PUT') @endif

        <x-cb.card title="Room details" icon="ri-information-line">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="number">Room number <span class="text-danger">*</span></label>
                    <input type="text" name="number" id="number" class="form-control" maxlength="20" required
                           value="{{ old('number', $room->number) }}" placeholder="e.g. 101 or B-204">
                </div>
                <div class="col-md-4">
                    <label for="rate_override">Custom nightly rate</label>
                    <div class="input-group">
                        <span class="input-group-text">{{ config('hotel.currency_symbol') }}</span>
                        <input type="number" step="0.01" min="0" name="rate_override" id="rate_override" class="form-control"
                               value="{{ old('rate_override', $room->rate_override) }}" placeholder="Use room type rate">
                    </div>
                    <div class="form-text">Leave empty to use the room type's rate.</div>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" @checked(old('is_active', $room->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active (can be sold)</label>
                    </div>
                </div>

                @include('rooms.partials.location-fields')

                <div class="col-12">
                    <label for="features">Room-specific features</label>
                    <input type="text" name="features" id="features" class="form-control" maxlength="500"
                           value="{{ old('features', implode(', ', $room->features ?? [])) }}" placeholder="e.g. Pool view, Connecting door, Wheelchair accessible">
                    <div class="form-text">Comma separated. Amenities shared by every room of this type belong on the room type.</div>
                </div>
                <div class="col-12">
                    <label for="notes">Internal notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" maxlength="2000">{{ old('notes', $room->notes) }}</textarea>
                </div>
            </div>
        </x-cb.card>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ $room->exists ? route('rooms.show', $room) : route('rooms.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> {{ $room->exists ? 'Save changes' : 'Add room' }}</button>
        </div>
    </form>

</div>
</div>
</div>
@endsection
