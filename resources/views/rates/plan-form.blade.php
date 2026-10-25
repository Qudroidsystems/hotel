@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Rates\RatePlan;
    $sym = config('hotel.currency_symbol');
    $p = $plan;
    $days = old('arrival_days', $p->arrival_days ?? []);
    $typeIds = old('room_type_ids', $p->room_type_ids ?? []);
    $fixed = old('fixed_prices', $p->fixed_prices ?? []);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$p->exists ? $p->name : 'New rate plan'" icon="ri-price-tag-3-line" :back="route('rates.index', ['tab' => 'plans'])" back-label="Rates & Seasons"
               subtitle="A way of selling rooms: a discount, an extra such as breakfast, or a fixed price, with the rules for when it can be used." />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ $p->exists ? route('rates.plans.update', $p) : route('rates.plans.store') }}" class="rm-form">
        @csrf @if ($p->exists) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="The rate" icon="ri-file-text-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label for="name">Name <span class="text-danger">*</span></label><input name="name" id="name" class="form-control" maxlength="100" required value="{{ old('name', $p->name) }}" placeholder="e.g. Bed & breakfast"></div>
                        <div class="col-md-4"><label for="code">Short code <span class="text-danger">*</span></label><input name="code" id="code" class="form-control text-uppercase" maxlength="20" required value="{{ old('code', $p->code) }}" placeholder="BB"></div>
                        <div class="col-12"><label for="inclusions">What's included</label><input name="inclusions" id="inclusions" class="form-control" maxlength="255" value="{{ old('inclusions', $p->inclusions) }}" placeholder="e.g. Breakfast for two, late check-out until 2 pm"><div class="small text-muted">Guests see this when choosing the rate.</div></div>
                        <div class="col-12"><label for="description">Notes for staff</label><textarea name="description" id="description" class="form-control" rows="2" maxlength="500">{{ old('description', $p->description) }}</textarea></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Price" icon="ri-money-dollar-circle-line">
                    <div class="row g-3">
                        <div class="col-md-6"><label for="discount_percent">Discount off the night's rate</label><div class="input-group"><input type="number" step="0.01" min="0" max="100" name="discount_percent" id="discount_percent" class="form-control" value="{{ old('discount_percent', (float) $p->discount_percent) }}"><span class="input-group-text">%</span></div></div>
                        <div class="col-md-6"><label for="nightly_extra">Add per room per night</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" name="nightly_extra" id="nightly_extra" class="form-control" value="{{ old('nightly_extra', (float) $p->nightly_extra) }}"></div><div class="small text-muted">e.g. breakfast. Negative for money off.</div></div>
                        <div class="col-12">
                            <label>Or a fixed price per night</label>
                            <div class="row g-2">
                                @foreach ($types as $t)
                                    <div class="col-sm-6 col-lg-4"><div class="input-group input-group-sm"><span class="input-group-text" style="min-width: 120px">{{ $t->name }}</span><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="fixed_prices[{{ $t->id }}]" class="form-control" value="{{ $fixed[$t->id] ?? '' }}" placeholder="{{ number_format((float) $t->base_rate) }}"></div></div>
                                @endforeach
                            </div>
                            <div class="small text-muted mt-1">Leave empty to work from the room type's rate (with seasons). A fixed price ignores seasons; the discount and extra still apply.</div>
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="When it can be used" icon="ri-calendar-check-line">
                    <div class="row g-3">
                        <div class="col-md-6"><label for="valid_from">Arrivals from</label><input type="date" name="valid_from" id="valid_from" class="form-control" value="{{ old('valid_from', $p->valid_from?->toDateString()) }}"></div>
                        <div class="col-md-6"><label for="valid_to">Arrivals until</label><input type="date" name="valid_to" id="valid_to" class="form-control" value="{{ old('valid_to', $p->valid_to?->toDateString()) }}"></div>
                        <div class="col-md-4"><label for="min_nights">Shortest stay (nights)</label><input type="number" min="1" max="365" name="min_nights" id="min_nights" class="form-control" value="{{ old('min_nights', $p->min_nights) }}"></div>
                        <div class="col-md-4"><label for="max_nights">Longest stay (nights)</label><input type="number" min="1" max="365" name="max_nights" id="max_nights" class="form-control" value="{{ old('max_nights', $p->max_nights) }}"></div>
                        <div class="col-md-4"><label for="min_advance_days">Book at least (days ahead)</label><input type="number" min="1" max="730" name="min_advance_days" id="min_advance_days" class="form-control" value="{{ old('min_advance_days', $p->min_advance_days) }}" placeholder="Early bird"></div>
                        <div class="col-12"><label>Arrival days</label><div class="d-flex flex-wrap gap-3">@foreach (RatePlan::DAYS as $n => $label)<label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="arrival_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', $days)))> <span class="form-check-label">{{ $label }}</span></label>@endforeach</div><div class="small text-muted">None ticked = any day.</div></div>
                        <div class="col-12"><label>Room types</label><div class="d-flex flex-wrap gap-3">@foreach ($types as $t)<label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="room_type_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, array_map('intval', $typeIds)))> <span class="form-check-label">{{ $t->name }}</span></label>@endforeach</div><div class="small text-muted">None ticked = every room type.</div></div>
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Where it is sold" icon="ri-store-2-line">
                    <label class="form-check form-switch mb-2"><input type="hidden" name="front_desk" value="0"><input type="checkbox" class="form-check-input" role="switch" name="front_desk" value="1" @checked(old('front_desk', $p->front_desk))> <span class="form-check-label">At the front desk</span></label>
                    <label class="form-check form-switch mb-2"><input type="hidden" name="online" value="0"><input type="checkbox" class="form-check-input" role="switch" name="online" value="1" id="online" @checked(old('online', $p->online))> <span class="form-check-label">On the booking website and guest app</span></label>
                    <div class="mt-2"><label for="promo_code">Promo code (online)</label><input name="promo_code" id="promo_code" class="form-control text-uppercase" maxlength="30" value="{{ old('promo_code', $p->promo_code) }}" placeholder="e.g. BLUX20"><div class="small text-muted">With a code, guests only see this rate after typing it.</div></div>
                    @if ($companies->isNotEmpty())
                        <div class="mt-3"><label for="company_id">Negotiated rate for</label><select name="company_id" id="company_id" class="form-select"><option value="">Any guest</option>@foreach ($companies as $c)<option value="{{ $c->id }}" @selected((string) old('company_id', $p->company_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach</select><div class="small text-muted">Only for that company's bookings, at the front desk.</div></div>
                    @endif
                </x-cb.card>
                <x-cb.card title="Other" icon="ri-settings-3-line">
                    <label class="form-check form-switch mb-2"><input type="hidden" name="non_refundable" value="0"><input type="checkbox" class="form-check-input" role="switch" name="non_refundable" value="1" @checked(old('non_refundable', $p->non_refundable))> <span class="form-check-label">Non-refundable (shown to guests)</span></label>
                    <label class="form-check form-switch mb-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" role="switch" name="is_active" value="1" @checked(old('is_active', $p->is_active))> <span class="form-check-label">In use</span></label>
                    <label for="sort_order">Order in lists</label><input type="number" min="0" max="999" name="sort_order" id="sort_order" class="form-control" value="{{ old('sort_order', $p->sort_order ?? 0) }}">
                </x-cb.card>
                <button class="btn btn-primary w-100 py-2 fw-bold"><i class="ri-save-3-line me-1"></i> Save rate plan</button>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
