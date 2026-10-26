@extends('layouts.guest', ['title' => 'Your details', 'noindex' => true])

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $nights = $in->diffInDays($out);
    $online = app(\App\Services\Booking\PaystackService::class)->enabled();
@endphp
<div class="wrap two">
    <div>
        <a href="{{ route('book.search', $q) }}" class="back"><i class="ri-arrow-left-line"></i> Change room or dates</a>
        <h1 class="serif page-h">Your details</h1>
        @include('rooms.partials.flash-guest')

        <form method="POST" action="{{ route('book.store') }}" class="form" novalidate>
            @if (\App\Support\Tenancy\CurrentHotel::multi())<input type="hidden" name="hotel" value="{{ \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug }}">@endif
            @csrf
            @foreach (['check_in', 'check_out', 'adults', 'children', 'rooms', 'promo'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $q[$k] }}">@endforeach
            <input type="hidden" name="room_type_id" value="{{ $option->type->id }}">
            <input type="hidden" name="rate_plan_id" value="{{ $rate->plan?->id }}">
            <div class="hp" aria-hidden="true"><label for="website">Leave empty</label><input type="text" name="website" id="website" tabindex="-1" autocomplete="off"></div>

            <div class="grid2">
                <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" value="{{ old('first_name') }}" required maxlength="60" autocomplete="given-name"></div>
                <div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" value="{{ old('last_name') }}" required maxlength="60" autocomplete="family-name"></div>
                <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email"><div class="hint">We send your confirmation and booking link here.</div></div>
                <div class="field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel" placeholder="080…"></div>
                <div class="field"><label for="arrival_time">Arriving around (optional)</label><input type="time" id="arrival_time" name="arrival_time" value="{{ old('arrival_time') }}"></div>
            </div>
            <div class="field"><label for="special_requests">Anything we should know? (optional)</label><textarea id="special_requests" name="special_requests" maxlength="1000" placeholder="e.g. late arrival, high floor, airport pickup">{{ old('special_requests') }}</textarea></div>

            @if ($deposit > 0)
                <fieldset class="pay">
                    <legend>Deposit to confirm: <strong>{{ $sym }}{{ number_format($deposit, 2) }}</strong> <span class="muted">({{ rtrim(rtrim(number_format($depositPct, 1), '0'), '.') }}% of the stay)</span></legend>
                    @if ($online)
                        <label class="choice"><input type="radio" name="pay" value="online" @checked(old('pay', 'online') === 'online')> <span><strong>Pay now by card, bank or USSD</strong><br><span class="muted small">Secure payment with Paystack. Your booking is confirmed straight away.</span></span></label>
                    @endif
                    <label class="choice"><input type="radio" name="pay" value="later" @checked(old('pay') === 'later' || ! $online)> <span><strong>Pay by bank transfer</strong><br><span class="muted small">We hold the room for {{ config('hotel.booking.hold_hours') }} hours while you transfer. Bank details are on the next page.</span></span></label>
                </fieldset>
            @endif

            <label class="agree"><input type="checkbox" name="accept" value="1" required @checked(old('accept'))> <span>I accept the hotel policies{{ (int) config('hotel.booking.cancel_hours') > 0 ? ', including free cancellation until ' . config('hotel.booking.cancel_hours') . ' hours before check-in' : '' }}.</span></label>
            @if (config('hotel.booking.policies'))<ul class="policies small muted">@foreach (config('hotel.booking.policies') as $p)<li>{{ $p }}</li>@endforeach</ul>@endif

            <button type="submit" class="btn wide">{{ $deposit > 0 ? 'Book and pay deposit' : 'Confirm booking' }}</button>
            <p class="small muted">Card details are entered on Paystack’s secure page and never stored by the hotel.</p>
        </form>
    </div>

    <aside class="summary">
        @include('booking._keytag', ['in' => $in, 'out' => $out, 'title' => ($rooms > 1 ? $rooms . ' × ' : '') . $option->type->name,
            'sub' => $adults . ' adult' . ($adults == 1 ? '' : 's') . ($children ? ', ' . $children . ' child' . ($children == 1 ? '' : 'ren') : '')])
        <div class="panel totals">
            <div class="row"><span><strong>{{ $rate->name }}</strong>@if ($rate->inclusions)<br><span class="muted small">{{ $rate->inclusions }}</span>@endif</span><span></span></div>
            @foreach ($rate->nights as $night => $nightRate)
                @if ($loop->index < 7)<div class="row"><span>{{ \Illuminate\Support\Carbon::parse($night)->format('D j M') }}</span><span>{{ $sym }}{{ number_format($nightRate * $rooms) }}</span></div>@endif
            @endforeach
            @if (count($rate->nights) > 7)<div class="row muted"><span>{{ count($rate->nights) - 7 }} more night(s)</span><span></span></div>@endif
            <div class="row total"><span>Total{{ config('hotel.prices_include_tax') ? ' (taxes included)' : '' }}</span><span>{{ $sym }}{{ number_format($rate->total, 2) }}</span></div>
            @if ($deposit > 0)<div class="row"><span>Due now</span><span>{{ $sym }}{{ number_format($deposit, 2) }}</span></div><div class="row muted"><span>At the hotel</span><span>{{ $sym }}{{ number_format($rate->total - $deposit, 2) }}</span></div>@endif
            @if ($rate->non_refundable)<div class="row muted small"><span>Non-refundable: what you pay is kept if you cancel.</span><span></span></div>@endif
        </div>
        @php $silver = (float) config('hotel.loyalty.silver_discount'); $gold = (float) config('hotel.loyalty.gold_discount'); @endphp
        @if (config('hotel.loyalty.enabled') && ($silver > 0 || $gold > 0))
            <p class="small muted" style="margin-top:10px">Stayed with us before? Silver members get {{ rtrim(rtrim(number_format($silver, 1), '0'), '.') }}% and Gold members {{ rtrim(rtrim(number_format($gold, 1), '0'), '.') }}% off the room rate, taken off automatically when you book with the e-mail you used before.</p>
        @endif
    </aside>
</div>
@endsection

@push('head')
<style>
    .two { display: grid; grid-template-columns: 1fr 380px; gap: 40px; align-items: start; padding-top: 26px; }
    .back { font-weight: 600; text-decoration: none; font-size: 15px; }
    .page-h { font-size: 34px; margin: 10px 0 18px; }
    .form .field { margin-bottom: 14px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
    .hint { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .pay { border: 1.5px solid var(--line); border-radius: var(--r); padding: 14px 16px 6px; margin: 6px 0 16px; background: #fff; }
    .pay legend { padding: 0 6px; font-size: 15px; }
    .choice { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-top: 1px solid var(--line); cursor: pointer; }
    .choice:first-of-type { border-top: 0; }
    .choice input, .agree input { width: 20px; height: 20px; margin-top: 3px; accent-color: var(--lagoon); flex: 0 0 auto; }
    .agree { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; margin: 6px 0; cursor: pointer; }
    .policies { margin: 4px 0 18px 30px; padding: 0; }
    .btn.wide { width: 100%; min-height: 56px; font-size: 17px; margin-top: 6px; }
    .summary { position: sticky; top: 16px; display: grid; gap: 14px; }
    .totals .row { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; font-size: 15px; }
    .totals .row.total { border-top: 1px solid var(--line); margin-top: 6px; padding-top: 10px; font-weight: 700; font-size: 17px; }
    @media (max-width: 900px) { .two { grid-template-columns: 1fr; gap: 22px; } .summary { position: static; order: -1; } .grid2 { grid-template-columns: 1fr; } }
</style>
@endpush
