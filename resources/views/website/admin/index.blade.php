@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-global-line" subtitle="Your public website: pictures, texts, dining, offers, gallery and news. Rooms, prices, halls and events come from their own pages, so they are always up to date.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('home') }}" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> Open the website</a>
            @can('Manage website')<a class="cb-hero-btn" href="{{ route('website.settings') }}"><i class="ri-settings-3-line"></i> Settings</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('website.admin._nav', ['active' => 'overview'])

    @unless (\App\Support\Website::enabled())
        <div class="alert alert-warning d-flex gap-2 align-items-start"><i class="ri-eye-off-line fs-5"></i><div>The website is switched off: the hotel's main address opens staff sign-in. @can('Manage website')<a href="{{ route('website.settings') }}">Switch it on</a>@endcan</div></div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('website.enquiries.index') }}"><x-cb.stat label="New enquiries" :value="$stats['new']" icon="ri-inbox-unarchive-line" accent="rose" hint="waiting for an answer" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Enquiries this week" :value="$stats['week']" icon="ri-mail-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('website.subscribers') }}"><x-cb.stat label="Newsletter sign-ups" :value="$stats['subscribers']" icon="ri-mail-star-line" accent="violet" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Items on the website" :value="$stats['live']" icon="ri-eye-line" accent="green" hint="shown right now" /></div>
    </div>

    <div class="row g-3">
        @can('Manage website')
        <div class="col-xl-7">
            <x-cb.card title="What is on the website" icon="ri-layout-masonry-line">
                <div class="ws-kinds">
                    @foreach (\App\Models\Website\WebsiteItem::KINDS as $k => $d)
                        @php $c = $counts->get($k); @endphp
                        <a class="ws-kind" href="{{ route('website.content.index', $k) }}"><i class="{{ $d[2] }}"></i><span><b>{{ $d[1] }}</b><small>{{ $c ? $c->live . ' shown' . ($c->n > $c->live ? ', ' . ($c->n - $c->live) . ' hidden' : '') : 'None yet' }}</small></span></a>
                    @endforeach
                    <a class="ws-kind" href="{{ route('room-types.index') }}"><i class="ri-hotel-bed-line"></i><span><b>Rooms & prices</b><small>From Room Types</small></span></a>
                    @if (Route::has('events.index'))<a class="ws-kind" href="{{ route('events.index') }}"><i class="ri-calendar-event-line"></i><span><b>Halls & events</b><small>From Events & Halls</small></span></a>@endif
                </div>
            </x-cb.card>
        </div>
        @endcan
        <div class="col-xl-5">
            <x-cb.card title="Latest enquiries" icon="ri-inbox-line" :flush="true">
                <x-slot:tools><a href="{{ route('website.enquiries.index') }}" class="btn btn-sm btn-soft-secondary">All</a></x-slot:tools>
                @forelse ($latest as $m)
                    <a href="{{ route('website.enquiries.show', $m) }}" class="d-flex gap-3 align-items-start px-3 py-2 border-bottom text-reset text-decoration-none">
                        <i class="{{ \App\Models\Website\WebsiteMessage::KINDS[$m->kind][1] ?? 'ri-mail-line' }} fs-5 text-muted mt-1"></i>
                        <div class="flex-grow-1 min-w-0"><div class="fw-semibold">{{ $m->name }} <span class="status-pill {{ $m->status_pill }} ms-1">{{ $m->status_label }}</span></div>
                            <div class="small text-muted text-truncate">{{ $m->subject }}</div></div>
                        <small class="text-muted text-nowrap">{{ $m->created_at->diffForHumans(null, true) }}</small>
                    </a>
                @empty
                    <div class="empty-state py-4"><i class="ri-inbox-line"></i><h6>No enquiries yet</h6><p class="small text-muted mb-0">Messages from the website's contact, events and dining forms arrive here.</p></div>
                @endforelse
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
