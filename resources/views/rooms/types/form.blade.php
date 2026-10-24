@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
@php
    $selected = old('amenities', $roomType->amenities ?? []);
    $standard = config('hotel.amenities', []);
    $extra = array_values(array_diff($roomType->amenities ?? [], $standard));
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$roomType->exists ? 'Edit ' . $roomType->name : 'New Room Type'" icon="ri-price-tag-3-line"
               subtitle="Rates are per night. Friday and Saturday nights use the weekend rate when you set one."
               :back="route('room-types.index')" back-label="Room Types" />

    @include('rooms.partials.flash')

    <form method="POST" enctype="multipart/form-data" class="rm-form"
          action="{{ $roomType->exists ? route('room-types.update', $roomType) : route('room-types.store') }}">
        @csrf
        @if ($roomType->exists) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-7">
                <x-cb.card title="Details & rates" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="name">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" maxlength="100" required value="{{ old('name', $roomType->name) }}" placeholder="e.g. Deluxe Double">
                        </div>
                        <div class="col-md-4">
                            <label for="code">Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="code" class="form-control text-uppercase" maxlength="20" required value="{{ old('code', $roomType->code) }}" placeholder="DLX">
                        </div>
                        <div class="col-md-6">
                            <label for="base_rate">Nightly rate <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">{{ config('hotel.currency_symbol') }}</span>
                                <input type="number" step="0.01" min="0" name="base_rate" id="base_rate" class="form-control" required value="{{ old('base_rate', $roomType->base_rate) }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="weekend_rate">Weekend rate (Fri &amp; Sat)</label>
                            <div class="input-group">
                                <span class="input-group-text">{{ config('hotel.currency_symbol') }}</span>
                                <input type="number" step="0.01" min="0" name="weekend_rate" id="weekend_rate" class="form-control" value="{{ old('weekend_rate', $roomType->weekend_rate) }}" placeholder="Same as nightly rate">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="max_adults">Max adults <span class="text-danger">*</span></label>
                            <input type="number" min="1" max="20" name="max_adults" id="max_adults" class="form-control" required value="{{ old('max_adults', $roomType->max_adults) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="max_children">Max children <span class="text-danger">*</span></label>
                            <input type="number" min="0" max="20" name="max_children" id="max_children" class="form-control" required value="{{ old('max_children', $roomType->max_children) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="bed_type">Bed</label>
                            <input type="text" name="bed_type" id="bed_type" class="form-control" maxlength="50" list="bedTypes" value="{{ old('bed_type', $roomType->bed_type) }}">
                            <datalist id="bedTypes"><option value="King"><option value="Queen"><option value="Double"><option value="Twin"><option value="Single"></datalist>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="size_sqm">Size (m²)</label>
                            <input type="number" min="1" name="size_sqm" id="size_sqm" class="form-control" value="{{ old('size_sqm', $roomType->size_sqm) }}">
                        </div>
                        <div class="col-12">
                            <label for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="4" maxlength="3000" placeholder="Shown to guests on the booking page later.">{{ old('description', $roomType->description) }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="color">Board colour</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" name="color" id="color" class="form-control form-control-color" value="{{ old('color', $roomType->color) }}">
                                <span class="small text-muted">Dot shown on each room tile</span>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" @checked(old('is_active', $roomType->is_active))>
                                <label class="form-check-label" for="is_active">Active (offered for sale)</label>
                            </div>
                        </div>
                    </div>
                </x-cb.card>
            </div>

            <div class="col-lg-5">
                <x-cb.card title="Amenities" icon="ri-wifi-line">
                    <div class="rm-amenity-grid">
                        @foreach ($standard as $a)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="amenities[]" value="{{ $a }}" id="am{{ $loop->index }}" @checked(in_array($a, $selected, true))>
                                <label class="form-check-label fw-normal" for="am{{ $loop->index }}">{{ $a }}</label>
                            </div>
                        @endforeach
                    </div>
                    <label for="extra_amenities" class="mt-3">Other amenities</label>
                    <input type="text" name="extra_amenities" id="extra_amenities" class="form-control" maxlength="500" value="{{ old('extra_amenities', implode(', ', $extra)) }}" placeholder="Comma separated, e.g. Jacuzzi, Butler service">
                </x-cb.card>

                <x-cb.card title="Photos" icon="ri-image-line" :count="$roomType->exists ? $roomType->photos->count() : null">
                    @if ($roomType->exists && $roomType->photos->isNotEmpty())
                        <div class="rm-photos mb-3">
                            @foreach ($roomType->photos as $photo)
                                <div class="ph">
                                    <img src="{{ $photo->url }}" alt="{{ $roomType->name }} photo" loading="lazy">
                                    <button type="submit" form="delPhoto{{ $photo->id }}" title="Remove photo" aria-label="Remove photo"><i class="ri-close-line"></i></button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <label for="photos">Add photos</label>
                    <input type="file" name="photos[]" id="photos" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                    <div class="form-text">JPG, PNG or WebP, up to 5 MB each, 10 at a time. The first photo is the cover.</div>
                </x-cb.card>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ route('room-types.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> {{ $roomType->exists ? 'Save changes' : 'Create room type' }}</button>
        </div>
    </form>

    {{-- Photo delete forms live outside the main form (forms can't be nested). --}}
    @if ($roomType->exists)
        @foreach ($roomType->photos as $photo)
            <form id="delPhoto{{ $photo->id }}" method="POST" action="{{ route('room-types.photos.destroy', $photo) }}" onsubmit="return confirm('Remove this photo?');" class="d-none">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif

</div>
</div>
</div>
@endsection
