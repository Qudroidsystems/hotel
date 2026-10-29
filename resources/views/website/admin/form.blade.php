@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Website\WebsiteItem;
    [$single, $plural, $icon, $intro, $fields, $hasPage] = $def;
    $uses = fn ($f) => array_key_exists($f, $fields);
    $lbl = fn ($f, $fallback) => $fields[$f] ?? $fallback;
    $titleLabel = ['review' => 'Guest\'s name', 'faq' => 'Question', 'gallery' => 'Title (for you; not shown)', 'amenity' => 'Highlight', 'slide' => 'Heading'][$kind] ?? 'Name';
    $action = $item->exists ? route('website.content.update', [$kind, $item]) : route('website.content.store', $kind);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" :icon="$icon" :subtitle="$intro" :back="route('website.content.index', $kind)" :back-label="$plural" />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
        @csrf
        @if ($item->exists) @method('PUT') @endif
        @if ($errors->any())<div class="alert alert-danger"><i class="ri-error-warning-line me-1"></i>{{ $errors->first() }}</div>@endif
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="cb-card"><div class="cb-card-body">
                    <div class="ws-field">
                        <label for="f_title">{{ $titleLabel }}</label>
                        <input class="form-control form-control-lg" id="f_title" name="title" value="{{ old('title', $item->title) }}" required maxlength="160">
                    </div>
                    @if ($uses('subtitle'))<div class="ws-field"><label for="f_subtitle">{{ $lbl('subtitle', 'Subtitle') }}</label><input class="form-control" id="f_subtitle" name="subtitle" value="{{ old('subtitle', $item->subtitle) }}" maxlength="200"></div>@endif
                    @if ($uses('summary'))
                        <div class="ws-field"><label for="f_summary">{{ $lbl('summary', 'Summary') }}</label>
                            <textarea class="form-control" id="f_summary" name="summary" rows="{{ $kind === 'review' ? 4 : 2 }}" maxlength="600">{{ old('summary', $item->summary) }}</textarea></div>
                    @endif
                    @if ($uses('body'))
                        <div class="ws-field"><label for="f_body">{{ $lbl('body', 'Text') }}</label>
                            <textarea class="form-control" id="f_body" name="body" rows="{{ in_array($kind, ['post', 'page'], true) ? 14 : 6 }}" maxlength="20000">{{ old('body', $item->body) }}</textarea>
                            <div class="ws-help">Leave a blank line between paragraphs. A line starting with "## " becomes a heading.</div></div>
                    @endif
                    @if ($uses('meta'))<div class="ws-field"><label for="f_meta">{{ $lbl('meta', 'Details') }}</label><input class="form-control" id="f_meta" name="meta" value="{{ old('meta', $item->meta) }}" maxlength="160"></div>@endif
                    @if ($uses('link'))
                        <div class="ws-field"><label>{{ $lbl('link', 'Button') }} <span class="fw-normal text-muted">(optional)</span></label>
                            <div class="row g-2"><div class="col-md-4"><input class="form-control" name="link_label" value="{{ old('link_label', $item->link_label) }}" placeholder="Words, e.g. See the menu" maxlength="60"></div>
                                <div class="col-md-8"><input class="form-control" name="link_url" value="{{ old('link_url', $item->link_url) }}" placeholder="https://… or /dining" maxlength="255"></div></div>
                            <div class="ws-help">A page of this website starts with /, e.g. /offers or /meetings-events.</div></div>
                    @endif
                    @if ($uses('icon'))
                        <div class="ws-field"><label>{{ $lbl('icon', 'Icon') }}</label>
                            <div class="ws-icons">
                                @foreach (WebsiteItem::ICONS as $ic => $name)
                                    <input type="radio" name="icon" id="ic_{{ $loop->index }}" value="{{ $ic }}" @checked(old('icon', $item->icon) === $ic)><label for="ic_{{ $loop->index }}" title="{{ $name }}"><i class="{{ $ic }}"></i></label>
                                @endforeach
                            </div></div>
                    @endif
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="cb-card"><div class="cb-card-body">
                    <div class="ws-field">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="f_active" name="is_active" value="1" @checked(old('is_active', $item->is_active))><label class="form-check-label fw-semibold" for="f_active">Show on the website</label></div>
                        @if (! in_array($kind, ['slide', 'amenity', 'faq', 'gallery'], true))
                            <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="f_feat" name="is_featured" value="1" @checked(old('is_featured', $item->is_featured))><label class="form-check-label" for="f_feat">Featured (shown first)</label></div>
                        @endif
                    </div>
                    @if ($uses('image'))
                        <div class="ws-field"><label for="f_image">{{ $lbl('image', 'Photo') }}</label>
                            @if ($item->image_url)<img src="{{ $item->image_url }}" class="ws-img mb-2 d-block" alt="">@endif
                            <input type="file" class="form-control" id="f_image" name="image" accept="image/jpeg,image/png,image/webp" @if (in_array($kind, ['slide', 'gallery'], true) && ! $item->image) required @endif>
                            <div class="ws-help">JPG, PNG or WebP up to 6 MB. {{ $kind === 'slide' ? 'Wide, at least 1920 px across.' : 'At least 1200 px across looks sharp.' }}</div>
                            @if ($item->image && ! in_array($kind, ['slide', 'gallery'], true))<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="image_remove" value="1" id="f_imrm"><label class="form-check-label small" for="f_imrm">Remove the photo</label></div>@endif
                        </div>
                    @endif
                    @if ($uses('category'))
                        @php $cats = WebsiteItem::ofKind($kind)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'); @endphp
                        <div class="ws-field"><label for="f_cat">{{ $lbl('category', 'Category') }}</label><input class="form-control" id="f_cat" name="category" value="{{ old('category', $item->category) }}" maxlength="60" list="cats">
                            <datalist id="cats">@foreach ($cats as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                    @endif
                    @if ($uses('promo_code'))<div class="ws-field"><label for="f_promo">{{ $lbl('promo_code', 'Promo code') }}</label><input class="form-control text-uppercase" id="f_promo" name="promo_code" value="{{ old('promo_code', $item->promo_code) }}" maxlength="30"><div class="ws-help">The same code as under Rates & Seasons, so the discount is applied when booking.</div></div>@endif
                    @if ($uses('dates'))
                        <div class="ws-field"><label>{{ $lbl('dates', 'Shown between') }}</label>
                            <div class="row g-2"><div class="col-6"><input type="date" class="form-control" name="starts_on" value="{{ old('starts_on', $item->starts_on?->toDateString()) }}" aria-label="From"></div>
                                <div class="col-6"><input type="date" class="form-control" name="ends_on" value="{{ old('ends_on', $item->ends_on?->toDateString()) }}" aria-label="Until"></div></div>
                            <div class="ws-help">Empty: always shown. It hides itself after the last day.</div></div>
                    @endif
                    @if ($uses('rating'))<div class="ws-field"><label for="f_rating">{{ $lbl('rating', 'Stars') }}</label><select class="form-select" id="f_rating" name="rating">@for ($s = 5; $s >= 1; $s--)<option value="{{ $s }}" @selected((int) old('rating', $item->rating ?? 5) === $s)>{{ str_repeat('★', $s) }}</option>@endfor</select></div>@endif
                    @if ($uses('published_at'))<div class="ws-field"><label for="f_pub">{{ $lbl('published_at', 'Publish date') }}</label><input type="datetime-local" class="form-control" id="f_pub" name="published_at" value="{{ old('published_at', $item->published_at?->format('Y-m-d\TH:i')) }}"><div class="ws-help">A date ahead: it appears on that day.</div></div>@endif
                    @if ($hasPage)
                        <div class="ws-field"><label for="f_slug">Web address</label>
                            <div class="input-group"><span class="input-group-text small">{{ ['dining' => '/dining/', 'experience' => '/experiences/', 'offer' => '/offers/', 'post' => '/journal/', 'page' => '/page/'][$kind] }}</span>
                                <input class="form-control" id="f_slug" name="slug" value="{{ old('slug', $item->slug) }}" maxlength="180" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="made from the name"></div></div>
                    @endif
                </div>
                <div class="cb-card-body border-top d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary flex-grow-1"><i class="ri-save-3-line me-1"></i> Save</button>
                    <a href="{{ route('website.content.index', $kind) }}" class="btn btn-light">Cancel</a>
                </div></div>
            </div>
        </div>
    </form>

    @if ($item->exists)
        <form method="POST" action="{{ route('website.content.destroy', [$kind, $item]) }}" class="mt-3" onsubmit="return confirm('Remove “{{ addslashes($item->title) }}” from the website for good?')">
            @csrf @method('DELETE')
            <button class="btn btn-soft-danger btn-sm"><i class="ri-delete-bin-line me-1"></i> Remove this {{ strtolower($single) }}</button>
        </form>
    @endif

</div>
</div>
</div>
@endsection
