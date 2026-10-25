@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Support\HotelSettings;
    $group = $groups[$tab];
    $sym = config('hotel.currency_symbol');
    $isBranding = $tab === 'branding';
@endphp
<style>
    .hs-field { padding: 16px 0; border-bottom: 1px solid var(--cb-border, #e9ebec); }
    .hs-field:last-of-type { border-bottom: 0; }
    .hs-field label.hs-label { font-weight: 600; color: var(--cb-heading); margin-bottom: 4px; display: block; }
    .hs-help { font-size: 12.5px; color: var(--bs-secondary-color, #6c757d); margin-top: 4px; }
    .hs-meta { font-size: 12px; color: var(--bs-secondary-color, #6c757d); display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
    .hs-custom { background: rgba(13, 148, 136, .12); color: #0f766e; padding: 1px 8px; border-radius: 20px; font-weight: 600; }
    .hs-logo { max-height: 56px; max-width: 220px; padding: 6px; border: 1px dashed var(--cb-border, #ced4da); border-radius: 8px; background: #fff; }
    .hs-logo.dark { background: #1f2937; }
    .hs-input-money { max-width: 260px; }
    .hs-input-num { max-width: 180px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Hotel Settings" icon="ri-settings-4-line" subtitle="Change how the system works for this hotel. Values you have not changed use the installation defaults.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('settings.bank-accounts.index') }}"><i class="ri-bank-line"></i> Bank accounts</a>
            @can('View audit log')<a class="cb-hero-btn" href="{{ route('audit.index', ['module' => 'settings']) }}"><i class="ri-history-line"></i> Change history</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="rs-tabs">
        @foreach ($groups as $key => $g)
            @php $changed = collect(array_keys($g['fields']))->filter(fn ($k) => $saved->has($k))->count(); @endphp
            <a href="{{ route('settings.hotel.index', ['tab' => $key]) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}"><i class="{{ $g['icon'] }}"></i> {{ $g['title'] }}@if ($changed) <span class="cb-count">{{ $changed }}</span>@endif</a>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('settings.hotel.update', $tab) }}" class="cb-card rm-form" @if ($isBranding) enctype="multipart/form-data" @endif>
                @csrf @method('PUT')
                <div class="cb-card-header"><h5><i class="{{ $group['icon'] }}"></i>{{ $group['title'] }}</h5></div>
                <div class="cb-card-body">
                    <p class="small text-muted mb-1">{{ $group['intro'] }}</p>

                    @foreach ($group['fields'] as $key => $f)
                        @php
                            $name = HotelSettings::input($key);
                            $value = config('hotel.' . $key);
                            $default = HotelSettings::default($key);
                            $row = $saved->get($key);
                            $id = 'hs_' . $name;
                            $listValue = fn ($v) => implode("\n", (array) $v);
                        @endphp
                        <div class="hs-field">
                            @if ($f['type'] === 'bool')
                                <input type="hidden" name="{{ $name }}" value="0">
                                <label class="form-check form-switch mb-0">
                                    <input type="checkbox" class="form-check-input" role="switch" name="{{ $name }}" id="{{ $id }}" value="1" @checked(old($name, $value))>
                                    <span class="form-check-label fw-semibold" style="color: var(--cb-heading)">{{ $f['label'] }}</span>
                                </label>
                            @else
                                <label class="hs-label" for="{{ $id }}">{{ $f['label'] }}</label>
                            @endif

                            @switch($f['type'])
                                @case('money')
                                    <div class="input-group hs-input-money">
                                        <span class="input-group-text">{{ $sym }}</span>
                                        <input type="number" class="form-control @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $id }}" min="0" step="0.01" required value="{{ old($name, $value) }}">
                                    </div>
                                    @break
                                @case('percent')
                                    <div class="input-group hs-input-num">
                                        <input type="number" class="form-control @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $id }}" min="0" max="{{ $f['max'] ?? 100 }}" step="0.01" required value="{{ old($name, $value) }}">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    @break
                                @case('int')
                                    <input type="number" class="form-control hs-input-num @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $id }}" min="{{ $f['min'] ?? 0 }}" max="{{ $f['max'] ?? 100000 }}" step="1" required value="{{ old($name, $value) }}">
                                    @break
                                @case('list')
                                @case('int_list')
                                @case('email_list')
                                    <textarea class="form-control @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $id }}" rows="{{ $f['type'] === 'list' && count((array) $value) > 8 ? 10 : 5 }}">{{ old($name, $listValue($value)) }}</textarea>
                                    @break
                                @case('image')
                                    <div class="d-flex align-items-center gap-3 flex-wrap">
                                        @if ($value)<img src="{{ asset($value) }}" alt="" class="hs-logo {{ $key === 'logo_light' ? 'dark' : '' }}">@endif
                                        <input type="file" class="form-control @error($name) is-invalid @enderror" style="max-width: 320px" name="{{ $name }}" id="{{ $id }}" accept="image/*">
                                        @if ($row)
                                            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="{{ $name }}__remove" value="1"> <span class="form-check-label small">Remove and use the default</span></label>
                                        @endif
                                    </div>
                                    @break
                                @case('select')
                                    <select class="form-select @error($name) is-invalid @enderror" style="max-width: 360px" name="{{ $name }}" id="{{ $id }}">
                                        @foreach ($f['options'] as $optValue => $optLabel)<option value="{{ $optValue }}" @selected((string) old($name, $value) === (string) $optValue)>{{ $optLabel }}</option>@endforeach
                                    </select>
                                    @break
                                @case('textarea')
                                    <textarea class="form-control @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $id }}" rows="3">{{ old($name, $value) }}</textarea>
                                    @break
                                @case('bool')
                                    @break
                                @default
                                    <input type="text" class="form-control @error($name) is-invalid @enderror {{ $f['type'] === 'currency' ? 'text-uppercase hs-input-num' : '' }}" name="{{ $name }}" id="{{ $id }}" value="{{ old($name, $value) }}" @if ($f['type'] === 'currency') maxlength="3" @endif>
                            @endswitch

                            @error($name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @if (! empty($f['help']))<div class="hs-help">{{ $f['help'] }}</div>@endif

                            <div class="hs-meta">
                                @if ($row)
                                    <span class="hs-custom">Changed</span>
                                    <span>by {{ $row->updatedBy?->name ?? 'someone' }}, {{ $row->updated_at?->diffForHumans() }}</span>
                                    @if ($f['type'] !== 'image')<span>· default {{ HotelSettings::display($f, $default) ?: '—' }}</span>@endif
                                    <button type="submit" form="resetForm" name="key" value="{{ $key }}" class="btn btn-link btn-sm p-0" onclick="return confirm('Put “{{ $f['label'] }}” back to the default?')">Use default</button>
                                @else
                                    <span>Using the default</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="cb-card-body border-top d-flex justify-content-end gap-2">
                    <a href="{{ route('settings.hotel.index', ['tab' => $tab]) }}" class="btn btn-light">Undo changes</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-3-line me-1"></i> Save {{ strtolower($group['title']) }}</button>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="How settings work" icon="ri-information-line">
                <p class="small mb-2">Each setting starts with the value chosen when the system was installed. When you change one, your value is used from the next page you open, on every device.</p>
                <p class="small mb-2"><strong>Use default</strong> removes your value and goes back to the installed one.</p>
                <p class="small mb-0">Every change is recorded on the audit trail with the old and new value and who made it.</p>
            </x-cb.card>
            @if ($tab === 'money')
                <x-cb.card title="Taxes on new bills" icon="ri-receipt-line">
                    @forelse (config('hotel.taxes') as $t)
                        <div class="d-flex justify-content-between py-1 border-bottom small"><span>{{ $t['name'] }}</span><strong>{{ rtrim(rtrim(number_format($t['rate'], 2), '0'), '.') }}%</strong></div>
                    @empty
                        <div class="small text-muted">No taxes are charged.</div>
                    @endforelse
                    <div class="small text-muted mt-2">{{ config('hotel.prices_include_tax') ? 'Included in prices.' : 'Added on top of prices.' }}</div>
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
<form method="POST" action="{{ route('settings.hotel.reset') }}" id="resetForm" class="d-none">@csrf</form>
@endsection
