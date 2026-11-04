@extends('layouts.master')

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<style>
    .ws-venue { margin-bottom: 18px; }
    .ws-sub { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 16px; }
    @media (max-width: 1000px) { .ws-sub { grid-template-columns: 1fr; } }
    .ws-line { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 8px 0; border-top: 1px dashed var(--cb-border); }
    .ws-line:first-of-type { border-top: 0; }
    .ws-line input.form-control, .ws-line select { width: auto; }
    .ws-line .w-name { flex: 1 1 180px; }
    .ws-line .w-num { width: 96px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-settings-3-line" subtitle="The spa, gym and pool: hours, how many people at once and session prices; the spa's treatments; and the therapists (each gives one treatment at a time, so they decide how many can be booked together).">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('wellness.index') }}"><i class="ri-arrow-left-line"></i> Today's bookings</a></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    @foreach ($venues->push(null) as $v)
        @php $kind = $v?->kind ?? 'spa'; @endphp
        <x-cb.card :title="$v ? $v->name : 'Add a place'" :icon="$v ? $v->icon : 'ri-add-circle-line'" class="ws-venue">
            <form method="POST" action="{{ $v ? route('wellness.venues.update', $v) : route('wellness.venues.store') }}" class="row g-2 align-items-end">@csrf @if ($v) @method('PUT') @endif
                <div class="col-md-3"><label class="form-label small">Name</label><input name="name" class="form-control" maxlength="80" required value="{{ $v?->name }}" placeholder="e.g. Serenity Spa"></div>
                <div class="col-md-2"><label class="form-label small">Kind</label><select name="kind" class="form-select">@foreach (\App\Models\Wellness\WellnessVenue::KINDS as $k => $kv)<option value="{{ $k }}" @selected($kind === $k)>{{ $kv[0] }}</option>@endforeach</select></div>
                <div class="col-md-1 col-6"><label class="form-label small">Opens</label><input type="time" name="opens" class="form-control" required value="{{ $v?->opens ?? '07:00' }}"></div>
                <div class="col-md-1 col-6"><label class="form-label small">Closes</label><input type="time" name="closes" class="form-control" required value="{{ $v?->closes ?? '21:00' }}"></div>
                <div class="col-md-1 col-6"><label class="form-label small" title="Gym/pool: people at once. Spa with no therapists: treatment rooms.">At once</label><input type="number" name="capacity" min="1" class="form-control" value="{{ $v?->capacity ?? 10 }}"></div>
                <div class="col-md-1 col-6"><label class="form-label small">Session (min)</label><input type="number" name="session_minutes" min="15" class="form-control" value="{{ $v?->session_minutes ?? 60 }}"></div>
                <div class="col-md-1 col-6"><label class="form-label small">Guest {{ $sym }}</label><input type="number" step="0.01" min="0" name="guest_price" class="form-control" value="{{ $v ? (float) $v->guest_price : 0 }}"></div>
                <div class="col-md-1 col-6"><label class="form-label small">Visitor {{ $sym }}</label><input type="number" step="0.01" min="0" name="visitor_price" class="form-control" value="{{ $v?->visitor_price !== null ? (float) $v->visitor_price : '' }}"></div>
                <div class="col-md-1"><button class="btn btn-primary w-100">{{ $v ? 'Save' : 'Add' }}</button></div>
                <div class="col-md-8"><input name="description" class="form-control" maxlength="500" value="{{ $v?->description }}" placeholder="What guests see, e.g. Massages and facials in a quiet garden room"></div>
                <div class="col-md-4 d-flex gap-3">
                    <input type="hidden" name="visitors" value="0"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="visitors" value="1" @checked($v?->visitors ?? true)> Visitors can book</label>
                    @if ($v)<input type="hidden" name="is_active" value="0"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($v->is_active)> Open for bookings</label>@endif
                </div>
                <div class="col-12 small text-muted">Spa: guests book treatments below. Gym, pool and others: guests book a session of the set length; “Guest” and “Visitor” are per person per session (0 = free; empty visitor price = the guest price).</div>
            </form>

            @if ($v)
                <div class="ws-sub mt-3">
                    @if ($v->hasTreatments())
                    <div>
                        <h6 class="mb-2">Treatments</h6>
                        @foreach ($v->services->push(null) as $s)
                            <form method="POST" action="{{ $s ? route('wellness.services.update', [$v, $s]) : route('wellness.services.store', $v) }}" class="ws-line">@csrf @if ($s) @method('PUT') @endif
                                <input name="name" class="form-control form-control-sm w-name" maxlength="100" required value="{{ $s?->name }}" placeholder="{{ $s ? '' : 'New treatment, e.g. Deep tissue massage' }}">
                                <input type="number" name="minutes" class="form-control form-control-sm w-num" min="15" value="{{ $s?->minutes ?? 60 }}" title="Minutes" aria-label="Minutes">
                                <input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm w-num" required value="{{ $s ? (float) $s->price : '' }}" placeholder="Guest {{ $sym }}" aria-label="Guest price">
                                <input type="number" step="0.01" min="0" name="visitor_price" class="form-control form-control-sm w-num" value="{{ $s?->visitor_price !== null ? (float) $s?->visitor_price : '' }}" placeholder="Visitor {{ $sym }}" aria-label="Visitor price">
                                @if ($s)<input type="hidden" name="is_active" value="0"><label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($s->is_active)> On</label>@endif
                                <input type="hidden" name="description" value="{{ $s?->description }}">
                                <button class="btn btn-sm {{ $s ? 'btn-outline-primary' : 'btn-primary' }}">{{ $s ? 'Save' : 'Add' }}</button>
                            </form>
                        @endforeach
                    </div>
                    <div>
                        <h6 class="mb-2">Therapists</h6>
                        @if ($v->therapists->isEmpty())<p class="small text-muted mb-1">With no therapists, up to {{ $v->capacity }} treatments can be booked at once (“At once”).</p>@endif
                        @foreach ($v->therapists->push(null) as $t)
                            <form method="POST" action="{{ $t ? route('wellness.therapists.update', [$v, $t]) : route('wellness.therapists.store', $v) }}" class="ws-line">@csrf @if ($t) @method('PUT') @endif
                                <input name="name" class="form-control form-control-sm w-name" maxlength="80" required value="{{ $t?->name }}" placeholder="{{ $t ? '' : 'New therapist' }}">
                                <select name="user_id" class="form-select form-select-sm" style="max-width:170px" aria-label="Staff login"><option value="">No staff login</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected($t?->user_id === $u->id)>{{ $u->name }}</option>@endforeach</select>
                                @if ($t)<input type="hidden" name="is_active" value="0"><label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($t->is_active)> Working</label>@endif
                                <button class="btn btn-sm {{ $t ? 'btn-outline-primary' : 'btn-primary' }}">{{ $t ? 'Save' : 'Add' }}</button>
                            </form>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endif
        </x-cb.card>
    @endforeach
</div>
</div>
</div>
@endsection
