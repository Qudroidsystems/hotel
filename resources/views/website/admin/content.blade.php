@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php [$single, $plural, $icon, $intro, $fields, $hasPage] = $def; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$plural" :icon="$icon" :subtitle="$intro" :back="route('website.index')" back-label="Website">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('website.content.create', $kind) }}"><i class="ri-add-line"></i> Add {{ strtolower($single) }}</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('website.admin._nav', ['active' => $kind])

    <div class="cb-card">
        @if ($items->isEmpty())
            <div class="empty-state py-5"><i class="{{ $icon }}"></i><h6>No {{ strtolower($plural) }} yet</h6>
                <p class="small text-muted">{{ $intro }}</p><a href="{{ route('website.content.create', $kind) }}" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> Add the first</a></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th style="width:44px">Order</th><th>{{ $single }}</th><th>Details</th><th>Shown</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $it)
                            <tr class="{{ $it->is_active ? '' : 'ws-hidden' }}">
                                <td data-label="Order">
                                    <span class="ws-move">
                                        <form method="POST" action="{{ route('website.content.move', [$kind, $it]) }}">@csrf<input type="hidden" name="dir" value="up"><button title="Up" @disabled($loop->first)><i class="ri-arrow-up-s-line"></i></button></form>
                                        <form method="POST" action="{{ route('website.content.move', [$kind, $it]) }}">@csrf<input type="hidden" name="dir" value="down"><button title="Down" @disabled($loop->last)><i class="ri-arrow-down-s-line"></i></button></form>
                                    </span>
                                </td>
                                <td data-label="{{ $single }}">
                                    <div class="d-flex gap-3 align-items-center">
                                        @if (\App\Models\Website\WebsiteItem::uses($kind, 'image'))
                                            @if ($it->image_url)<img src="{{ $it->image_url }}" class="ws-thumb" alt="">@else<span class="ws-thumb"><i class="ri-image-line"></i></span>@endif
                                        @elseif ($it->icon)<span class="ws-thumb"><i class="{{ $it->icon }}"></i></span>@endif
                                        <div><a href="{{ route('website.content.edit', [$kind, $it]) }}" class="fw-semibold text-reset">{{ $it->title }}</a>
                                            <div class="small text-muted">{{ \Illuminate\Support\Str::limit($it->subtitle ?: $it->summary ?: strip_tags((string) $it->body), 90) }}</div></div>
                                    </div>
                                </td>
                                <td data-label="Details" class="small">
                                    @if ($it->category)<span class="rs-room-chip">{{ $it->category }}</span>@endif
                                    @if ($it->promo_code)<span class="rs-ref">{{ $it->promo_code }}</span>@endif
                                    @if ($it->meta)<div class="text-muted">{{ $it->meta }}</div>@endif
                                    @if ($it->rating)<div class="text-warning">{{ str_repeat('★', $it->rating) }}</div>@endif
                                    @if ($kind === 'post' && $it->published_at)<div class="text-muted">{{ $it->published_at->format('j M Y') }}</div>@endif
                                </td>
                                <td data-label="Shown">
                                    @if (! $it->is_active)<span class="status-pill st-muted">Hidden</span>
                                    @elseif ($it->isScheduledOut())<span class="status-pill st-warning">Outside its dates</span>
                                    @else<span class="status-pill st-success">On the website</span>@endif
                                    @if ($it->is_featured)<span class="status-pill st-info">Featured</span>@endif
                                    @if ($it->starts_on || $it->ends_on)<div class="small text-muted">{{ $it->starts_on?->format('j M') ?? '…' }} – {{ $it->ends_on?->format('j M Y') ?? '…' }}</div>@endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($it->url && $it->is_active)<a href="{{ $it->url }}" target="_blank" rel="noopener" class="btn btn-sm btn-soft-secondary" title="See it on the website"><i class="ri-external-link-line"></i></a>@endif
                                    <form method="POST" action="{{ route('website.content.toggle', [$kind, $it]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-soft-secondary" title="{{ $it->is_active ? 'Hide' : 'Show' }}"><i class="{{ $it->is_active ? 'ri-eye-off-line' : 'ri-eye-line' }}"></i></button></form>
                                    <a href="{{ route('website.content.edit', [$kind, $it]) }}" class="btn btn-sm btn-soft-primary"><i class="ri-edit-line"></i> Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
