@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $form = $edit ?? null; @endphp
<style>
    .ht-mode { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .ht-mode label { display: block; border: 2px solid var(--cb-border); border-radius: 12px; padding: 14px 16px; cursor: pointer; }
    .ht-mode input { margin-right: 8px; }
    .ht-mode label.on { border-color: var(--cb-teal, #0d9488); background: rgba(13,148,136,.06); }
    .ht-mode strong { color: var(--cb-heading); }
    .ht-staff { max-height: 320px; overflow: auto; border: 1px solid var(--cb-border); border-radius: 8px; padding: 6px 10px; }
    .ht-staff label { display: flex; gap: 8px; align-items: center; padding: 4px 0; font-size: 13px; }
    .ht-thumb { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; background: var(--cb-surface-2); display: inline-flex; align-items: center; justify-content: center; color: var(--cb-muted); }
    @media (max-width: 768px) { .ht-mode { grid-template-columns: 1fr; } }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Hotels" icon="ri-hotel-line" subtitle="Run one hotel, or a group of hotels from this system. Each hotel has its own rooms, rates, bookings, bills, stock and settings; staff only see the hotels they work at.">
        @if ($multi)
            <x-slot:actions>
                <a class="cb-hero-btn" href="{{ route('settings.hotels.index', ['new' => 1]) }}"><i class="ri-add-line"></i> Add a hotel</a>
            </x-slot:actions>
        @endif
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="How many hotels?" icon="ri-community-line">
                <form method="POST" action="{{ route('settings.hotels.mode') }}">
                    @csrf
                    <div class="ht-mode mb-3">
                        <label class="{{ $multi ? '' : 'on' }}"><input type="radio" name="multi" value="0" @checked(! $multi)><strong>One hotel</strong>
                            <div class="small text-muted mt-1">Everything is for {{ $hotels->first()?->name }}. No hotel picker anywhere.</div></label>
                        <label class="{{ $multi ? 'on' : '' }}"><input type="radio" name="multi" value="1" @checked($multi)><strong>Several hotels</strong>
                            <div class="small text-muted mt-1">A group: staff switch hotel from the top bar, owners see every hotel, and guests can book any hotel from one booking site.</div></label>
                    </div>
                    <div class="mb-3" style="max-width:420px"><label class="form-label small">Group name <span class="text-muted">(several hotels)</span></label>
                        <input name="group_name" class="form-control" value="{{ config('hotel.group_name') }}" maxlength="100" placeholder="e.g. B Luxury Hotels">
                        <div class="form-text">The title of the group booking site, where guests pick a hotel: {{ route('hotels.public') }}</div></div>
                    <div class="mb-3" style="max-width:420px"><label class="form-label small">Usual commission on group-site bookings (%)</label>
                        <input name="commission_percent" type="number" step="0.5" min="0" max="50" class="form-control" style="max-width:140px" value="{{ rtrim(rtrim(number_format((float) config('hotel.commission_percent', 0), 2), '0'), '.') }}">
                        <div class="form-text">Charged to a hotel on stays booked through the group site or the guest app's hotel list (0 = none). A hotel can have its own rate. See <a href="{{ route('group.commission') }}">Commission</a>.</div></div>
                    <button class="action-btn btn-primary-cb"><i class="ri-save-3-line"></i> Save</button>
                </form>
            </x-cb.card>

            <x-cb.card title="Hotels" icon="ri-hotel-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Hotel</th><th>Where</th><th class="num">Rooms</th><th class="num">Open bookings</th><th class="num">Staff</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($hotels as $h)
                                <tr style="{{ $h->is_active ? '' : 'opacity:.55' }}">
                                    <td data-label="Hotel">
                                        <div class="d-flex align-items-center gap-2">
                                            @if ($h->logo_url)<img src="{{ $h->logo_url }}" alt="" class="ht-thumb" style="object-fit:contain;background:#fff">@else<span class="ht-thumb"><i class="ri-hotel-line"></i></span>@endif
                                            <div><span class="fw-semibold">{{ $h->name }}</span>
                                                @unless ($h->is_active)<span class="status-pill st-muted">Closed</span>@endunless
                                                @if ($multi && ! $h->is_listed)<span class="status-pill st-muted">Not on booking site</span>@endif
                                                <div class="small text-muted">{{ $h->slug }}</div></div>
                                        </div>
                                    </td>
                                    <td data-label="Where" class="small">{{ $h->location ?: '—' }}</td>
                                    <td data-label="Rooms" class="num">{{ $counts['rooms'][$h->id] ?? 0 }}</td>
                                    <td data-label="Open bookings" class="num">{{ $counts['bookings'][$h->id] ?? 0 }}</td>
                                    <td data-label="Staff" class="num">{{ $h->users_count }}</td>
                                    <td class="text-end"><a class="action-btn btn-more" href="{{ route('settings.hotels.index', ['hotel' => $h->id]) }}" title="Edit"><i class="ri-pencil-line"></i></a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
            @unless ($multi)
                <p class="small text-muted">Switch to “Several hotels” to add more hotels.</p>
            @endunless
        </div>

        <div class="col-xl-4">
            @if ($form || ($creating && $multi))
                <x-cb.card :title="$form ? 'Edit ' . $form->name : 'Add a hotel'" :icon="$form ? 'ri-pencil-line' : 'ri-add-line'">
                    <form method="POST" action="{{ $form ? route('settings.hotels.update', $form) : route('settings.hotels.store') }}" enctype="multipart/form-data">
                        @csrf @if ($form) @method('PUT') @endif
                        <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control" value="{{ old('name', $form?->name) }}" required maxlength="120"></div>
                        @if ($form)<div class="mb-2"><label class="form-label small">Web address name</label><input name="slug" class="form-control" value="{{ old('slug', $form->slug) }}" maxlength="80"><div class="form-text">The hotel's page on the booking site: /hotels/{{ $form->slug }}</div></div>@endif
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">City</label><input name="city" class="form-control" value="{{ old('city', $form?->city) }}" maxlength="80"></div>
                            <div class="col-6"><label class="form-label small">State</label><input name="state" class="form-control" value="{{ old('state', $form?->state) }}" maxlength="80"></div>
                        </div>
                        <div class="mb-2"><label class="form-label small">Address</label><input name="address" class="form-control" value="{{ old('address', $form?->address) }}" maxlength="255"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Phone</label><input name="phone" class="form-control" value="{{ old('phone', $form?->phone) }}" maxlength="40"></div>
                            <div class="col-6"><label class="form-label small">Email</label><input name="email" type="email" class="form-control" value="{{ old('email', $form?->email) }}" maxlength="150"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Country</label><input name="country" class="form-control" value="{{ old('country', $form?->country ?? 'Nigeria') }}" maxlength="80"></div>
                            <div class="col-3"><label class="form-label small">Stars</label><input name="stars" type="number" min="1" max="5" class="form-control" value="{{ old('stars', $form?->stars) }}"></div>
                            <div class="col-3"><label class="form-label small">Order</label><input name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $form?->sort_order ?? 0) }}"></div>
                        </div>
                        @if ($multi)<div class="mb-2"><label class="form-label small">Commission on group-site bookings (%)</label><input name="commission_percent" type="number" step="0.5" min="0" max="50" class="form-control" style="max-width:160px" value="{{ old('commission_percent', $form?->commission_percent !== null ? rtrim(rtrim((string) $form->commission_percent, '0'), '.') : '') }}" placeholder="{{ rtrim(rtrim(number_format((float) config('hotel.commission_percent', 0), 2), '0'), '.') }} (usual)"></div>@endif
                        <div class="mb-2"><label class="form-label small">About the hotel <span class="text-muted">(booking site)</span></label><textarea name="description" class="form-control" rows="3" maxlength="3000">{{ old('description', $form?->description) }}</textarea></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Logo</label><input name="logo_file" type="file" accept="image/*" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label small">Cover photo</label><input name="cover_file" type="file" accept="image/*" class="form-control form-control-sm"></div>
                        </div>
                        <div class="form-check mb-1"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ht-active" @checked(old('is_active', $form?->is_active ?? true))><label class="form-check-label small" for="ht-active">Open (staff can work in it)</label></div>
                        <div class="form-check mb-3"><input type="hidden" name="is_listed" value="0"><input class="form-check-input" type="checkbox" name="is_listed" value="1" id="ht-listed" @checked(old('is_listed', $form?->is_listed ?? true))><label class="form-check-label small" for="ht-listed">Show on the group booking site</label></div>

                        @if ($form && $multi)
                            <label class="form-label small">Staff who work here</label>
                            <input type="hidden" name="staff[]" value="">
                            <div class="ht-staff mb-1">
                                @foreach ($users as $u)
                                    <label><input type="checkbox" class="form-check-input m-0" name="staff[]" value="{{ $u->id }}" @checked(in_array($u->id, $assigned, true))> {{ $u->name }} <span class="text-muted">{{ $u->email }}</span></label>
                                @endforeach
                            </div>
                            <div class="form-text mb-3">Owners and anyone with “Access all hotels” see every hotel anyway.</div>
                        @endif
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-save-3-line"></i> {{ $form ? 'Save' : 'Add hotel' }}</button>
                    </form>
                </x-cb.card>
            @else
                <x-cb.card title="How it works" icon="ri-information-line">
                    <p class="small mb-2">Each hotel keeps its own rooms, rates, bookings, guests, bills, restaurant &amp; bar, stock, staff rota and reports. Its name, taxes, payment account and other settings are set while working in it, under Hotel Settings.</p>
                    <p class="small mb-2">Staff work at the hotels they are added to here; with more than one, they switch from the top bar. The staff app does the same.</p>
                    <p class="small mb-0">User accounts and roles are shared: one login for every hotel a person works at.</p>
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
