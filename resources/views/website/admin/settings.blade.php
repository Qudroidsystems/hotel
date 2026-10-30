@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Support\Website;
    use App\Http\Controllers\Website\WebsiteAdminController as W;
    [$gTitle, $gIcon, $fields] = $groups[$group];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-settings-3-line" subtitle="Texts, pictures, colours and links for the public website. Empty fields use the wording shown in grey." :back="route('website.index')" back-label="Website">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('home') }}" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> Open the website</a></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('website.admin._nav', ['active' => 'settings'])

    <div class="rs-tabs">
        @foreach ($groups as $k => [$t, $i])<a href="{{ route('website.settings', $k) }}" class="rs-tab {{ $group === $k ? 'active' : '' }}"><i class="{{ $i }}"></i> {{ $t }}</a>@endforeach
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('website.settings.save', $group) }}" enctype="multipart/form-data" class="cb-card">
                @csrf
                <div class="cb-card-header"><h5><i class="{{ $gIcon }}"></i>{{ $gTitle }}</h5></div>
                <div class="cb-card-body">
                    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
                    @foreach ($fields as $key => $f)
                        @php $name = W::input($key); $saved = Website::all()[$key] ?? null; $value = old($name, $saved); $id = 'ws_' . $name; $default = $f['default'] ?? null; @endphp
                        <div class="ws-field">
                            @if ($f['type'] === 'bool')
                                <div class="form-check form-switch">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) ($saved ?? $default))>
                                    <label class="form-check-label fw-semibold" for="{{ $id }}">{{ $f['label'] }}</label>
                                </div>
                            @else
                                <label for="{{ $id }}">{{ $f['label'] }}</label>
                                @switch($f['type'])
                                    @case('textarea')
                                        <textarea class="form-control" id="{{ $id }}" name="{{ $name }}" rows="{{ str_contains($key, 'about') ? 6 : 3 }}" placeholder="{{ $default }}">{{ $value }}</textarea>
                                        @break
                                    @case('list')
                                        <textarea class="form-control font-monospace" id="{{ $id }}" name="{{ $name }}" rows="4" placeholder="{{ implode("\n", (array) $default) }}">{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
                                        @break
                                    @case('select')
                                        <div class="ws-theme">
                                            @foreach (Website::THEMES as $tk => [$tl, $a, , $ink])
                                                <input type="radio" name="{{ $name }}" id="{{ $id . $tk }}" value="{{ $tk }}" @checked(($value ?: $default) === $tk)>
                                                <label for="{{ $id . $tk }}"><span class="sw" style="background:{{ $ink }};--a:{{ $a }}"></span>{{ $tl }}</label>
                                            @endforeach
                                        </div>
                                        @break
                                    @case('image')
                                        @if ($saved)
                                            <div class="d-flex gap-3 align-items-center mb-2"><img src="{{ Website::image($key) }}" class="ws-img" alt="">
                                                <div class="form-check"><input class="form-check-input" type="checkbox" name="{{ $name }}__remove" value="1" id="{{ $id }}_rm"><label class="form-check-label small" for="{{ $id }}_rm">Remove</label></div></div>
                                        @endif
                                        <input type="file" class="form-control" id="{{ $id }}" name="{{ $name }}" accept="image/jpeg,image/png,image/webp">
                                        @if (! $saved)<div class="ws-help">None yet: the page uses one of your other photos.</div>@endif
                                        @break
                                    @case('file')
                                        @if (is_array($saved) && ! empty($saved['path']))
                                            <div class="d-flex gap-3 align-items-center mb-2 p-2 border rounded">
                                                <i class="ri-android-line fs-3 text-success"></i>
                                                <div class="flex-grow-1 small"><b>{{ $saved['name'] ?? 'app.apk' }}</b><br><span class="text-muted">{{ Website::bytes((int) ($saved['size'] ?? 0)) }} · uploaded {{ \Illuminate\Support\Carbon::parse($saved['at'] ?? now())->format('d M Y H:i') }}</span>
                                                    @if (Website::appFile(explode('.', $key)[1]))
                                                        · <a href="{{ route('site.app.download', explode('.', $key)[1]) }}">download</a>
                                                    @else
                                                        · <span class="text-danger">the file is missing on the server, upload it again</span>
                                                    @endif
                                                </div>
                                                <div class="form-check"><input class="form-check-input" type="checkbox" name="{{ $name }}__remove" value="1" id="{{ $id }}_rm"><label class="form-check-label small" for="{{ $id }}_rm">Remove</label></div>
                                            </div>
                                        @endif
                                        <input type="file" class="form-control ws-apk" id="{{ $id }}" name="{{ $name }}" accept=".apk,application/vnd.android.package-archive" data-limit="{{ Website::uploadLimit() }}">
                                        <div class="ws-help">{{ is_array($saved) && ! empty($saved['path']) ? 'Choose a new file to replace it.' : 'No file yet.' }} This server takes files up to {{ Website::uploadLimit() >= PHP_INT_MAX ? 'any size' : Website::bytes(Website::uploadLimit()) }}.</div>
                                        @break
                                    @default
                                        <input type="{{ $f['type'] === 'url' ? 'url' : 'text' }}" class="form-control" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $default ?? ($f['type'] === 'url' ? 'https://' : '') }}" maxlength="255">
                                @endswitch
                            @endif
                            @isset($f['help'])<div class="ws-help">{{ $f['help'] }}</div>@endisset
                        </div>
                    @endforeach
                </div>
                <div class="cb-card-body border-top d-flex justify-content-end gap-2"><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i> Save {{ strtolower($gTitle) }}</button></div>
            </form>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Where things come from" icon="ri-information-line">
                <ul class="small mb-0 ps-3">
                    <li class="mb-2">The hotel's <b>name, logo and tab icon</b> are under <a href="{{ route('settings.hotel.index', ['tab' => 'branding']) }}">Hotel Settings → Branding</a>.</li>
                    <li class="mb-2"><b>Phone, e-mail, address, check-in times and policies</b> are under <a href="{{ route('settings.hotel.index', ['tab' => 'booking']) }}">Hotel Settings → Online Booking</a>.</li>
                    <li class="mb-2"><b>Rooms, photos and prices</b> come from <a href="{{ route('room-types.index') }}">Room Types</a>; promo codes from Rates & Seasons.</li>
                    <li class="mb-2"><b>Halls and ticketed events</b> come from Events & Halls.</li>
                    <li>The <b>map</b> uses the hotel's map position under Settings → Hotels.</li>
                </ul>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
<script>
// A file bigger than the server takes would empty the whole form, so stop it here with a clear message.
document.querySelectorAll('.ws-apk').forEach(function (input) {
    input.addEventListener('change', function () {
        var f = input.files[0], limit = +input.dataset.limit;
        input.setCustomValidity('');
        if (f && !/\.apk$/i.test(f.name)) input.setCustomValidity('Choose the Android app file, ending in .apk.');
        else if (f && limit && f.size > limit) input.setCustomValidity('This file is ' + Math.round(f.size / 1048576) + ' MB but the server takes at most ' + Math.round(limit / 1048576) + ' MB. Raise upload_max_filesize and post_max_size in php.ini, or use a store link instead.');
        input.reportValidity();
    });
});
document.querySelectorAll('form[enctype]').forEach(function (form) {
    form.addEventListener('submit', function () {
        if (form.querySelector('.ws-apk') && form.checkValidity()) {
            var b = form.querySelector('button.btn-primary');
            if (b && form.querySelector('.ws-apk').files.length) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading…'; }
        }
    });
});
</script>
@endsection
