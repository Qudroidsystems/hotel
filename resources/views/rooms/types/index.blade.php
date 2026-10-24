@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Room Types & Rates" icon="ri-price-tag-3-line"
               subtitle="Categories of room you sell — rate, capacity, amenities and photos. Every room belongs to one type."
               :back="route('rooms.index')" back-label="Room Board">
        @can('Manage room types')
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('room-types.create') }}"><i class="ri-add-line"></i> New room type</a>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($roomTypes->isEmpty())
        <x-cb.card>
            <div class="empty-state">
                <i class="ri-price-tag-3-line"></i>
                <h6>No room types yet</h6>
                <p>Start with your categories, e.g. Standard, Deluxe, Executive and Suite.</p>
                @can('Manage room types')
                    <a href="{{ route('room-types.create') }}" class="action-btn btn-primary-cb mt-3"><i class="ri-add-line"></i> New room type</a>
                @endcan
            </div>
        </x-cb.card>
    @else
        <div class="row g-3 mb-4">
            @foreach ($roomTypes as $t)
                <div class="col-sm-6 col-xl-4 col-xxl-3">
                    <div class="rm-type-card" style="--tc: {{ $t->color }}">
                        <div class="rm-type-cover">
                            @if ($t->coverPhoto)
                                <img src="{{ $t->coverPhoto->url }}" alt="{{ $t->name }}" loading="lazy">
                            @else
                                <i class="ri-hotel-bed-line" style="font-size:48px; opacity:.6"></i>
                            @endif
                            <span class="code">{{ $t->code }}</span>
                            @unless ($t->is_active)<span class="inactive-tag">Inactive</span>@endunless
                        </div>
                        <div class="p-3 d-flex flex-column flex-grow-1">
                            <h5 class="fw-bold mb-1" style="color: var(--cb-heading)">{{ $t->name }}</h5>
                            <div class="rm-rate">@money($t->base_rate) <small>/ night</small></div>
                            @if ($t->weekend_rate !== null)
                                <div class="small text-muted">Fri &amp; Sat: @money($t->weekend_rate)</div>
                            @endif
                            <div class="small text-muted mt-2 d-flex flex-wrap gap-3">
                                <span><i class="ri-group-line"></i> {{ $t->capacity_label }}</span>
                                @if ($t->bed_type)<span><i class="ri-hotel-bed-line"></i> {{ $t->bed_type }}</span>@endif
                                @if ($t->size_sqm)<span><i class="ri-ruler-2-line"></i> {{ $t->size_sqm }} m²</span>@endif
                            </div>
                            @if (! empty($t->amenities))
                                <div class="mt-2">
                                    @foreach (array_slice($t->amenities, 0, 5) as $a)<span class="rm-amenity">{{ $a }}</span>@endforeach
                                    @if (count($t->amenities) > 5)<span class="rm-amenity">+{{ count($t->amenities) - 5 }} more</span>@endif
                                </div>
                            @endif
                            <div class="d-flex align-items-center justify-content-between mt-auto pt-3">
                                <a href="{{ route('rooms.index', ['type' => $t->id]) }}" class="status-pill st-info text-decoration-none"><i class="ri-door-line"></i> {{ $t->rooms_count }} rooms</a>
                                @can('Manage room types')
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('room-types.edit', $t) }}" class="action-btn btn-open" title="Edit"><i class="ri-pencil-line"></i></a>
                                        <form method="POST" action="{{ route('room-types.destroy', $t) }}" onsubmit="return confirm('Delete the {{ $t->name }} room type?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="action-btn btn-danger-soft" title="Delete" @disabled($t->rooms_count > 0)><i class="ri-delete-bin-line"></i></button>
                                        </form>
                                    </div>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
</div>
</div>
@endsection
