@extends('layouts.guest', ['title' => 'Rate your stay', 'noindex' => true])

@section('content')
@php
    $given = $fb['given'];
    $starLabels = [1 => 'Poor', 2 => 'Disappointing', 3 => 'OK', 4 => 'Good', 5 => 'Excellent'];
@endphp
<div class="wrap fbk">
    <div class="head">
        <a href="{{ route('stay.show', $r->guest_token) }}" class="small back"><i class="ri-arrow-left-line"></i> Your booking</a>
        <h1 class="serif">{{ $given ? 'Thank you, ' . $r->guest?->first_name : 'How was your stay?' }}</h1>
        <p class="muted">{{ config('hotel.name') }} · {{ $r->check_in_date->format('j M') }} – {{ $r->check_out_date->format('j M Y') }} · ref {{ $r->reference }}</p>
    </div>
    @include('rooms.partials.flash-guest')

    @if ($given)
        <section class="panel">
            <div class="stars-show" aria-label="{{ $given['overall'] }} of 5 stars">
                @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $given['overall'] ? '' : 'off' }}" aria-hidden="true">★</span>@endfor
            </div>
            <p>{{ $fb['note'] }} Every answer is read by our managers{{ $given['overall'] <= (int) config('hotel.feedback.alert_below', 2) ? ', and someone will be in touch about what went wrong' : '' }}.</p>
            @if ($given['comment'])<blockquote class="muted">“{{ $given['comment'] }}”</blockquote>@endif
            @if ($fb['review_url'])
                <div class="notice ok"><i class="ri-heart-3-line"></i><div>We’re glad you enjoyed it. Would you share a few words where other travellers can see them?
                    <div style="margin-top:10px"><a class="btn" href="{{ $fb['review_url'] }}" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> Write a public review</a></div></div></div>
            @endif
        </section>
    @elseif ($blocker)
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $blocker }}</div></div>
    @else
        <form method="POST" action="{{ route('stay.feedback.store', $r->guest_token) }}" class="panel" id="fbForm">@csrf
            <fieldset class="q">
                <legend>Your stay overall</legend>
                <div class="stars big" role="radiogroup">
                    @for ($i = 5; $i >= 1; $i--)
                        <input type="radio" id="ov{{ $i }}" name="overall" value="{{ $i }}" @checked((int) old('overall', request('overall')) === $i) required>
                        <label for="ov{{ $i }}" title="{{ $starLabels[$i] }}"><span aria-hidden="true">★</span><span class="sr">{{ $i }} – {{ $starLabels[$i] }}</span></label>
                    @endfor
                </div>
                @error('overall')<div class="err">Choose how many stars.</div>@enderror
            </fieldset>

            <fieldset class="q">
                <legend>And in more detail <span class="muted small">(optional)</span></legend>
                @foreach ($fb['aspects'] as $a)
                    <div class="aspect">
                        <span>{{ $a['label'] }}</span>
                        <div class="stars" role="radiogroup" aria-label="{{ $a['label'] }}">
                            @for ($i = 5; $i >= 1; $i--)
                                <input type="radio" id="a{{ $a['key'] }}{{ $i }}" name="ratings[{{ $a['key'] }}]" value="{{ $i }}" @checked((int) old('ratings.' . $a['key']) === $i)>
                                <label for="a{{ $a['key'] }}{{ $i }}" title="{{ $starLabels[$i] }}"><span aria-hidden="true">★</span><span class="sr">{{ $i }}</span></label>
                            @endfor
                        </div>
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="q">
                <legend>How likely are you to recommend us to a friend? <span class="muted small">(optional)</span></legend>
                <div class="nps" role="radiogroup">
                    @for ($i = 0; $i <= 10; $i++)
                        <input type="radio" id="np{{ $i }}" name="recommend" value="{{ $i }}" @checked(old('recommend') !== null && (int) old('recommend') === $i)>
                        <label for="np{{ $i }}">{{ $i }}</label>
                    @endfor
                </div>
                <div class="nps-ends small muted"><span>Not likely</span><span>Very likely</span></div>
            </fieldset>

            <div class="field"><label for="comment">Anything you’d like to tell us? (optional)</label>
                <textarea id="comment" name="comment" maxlength="{{ \App\Services\Booking\FeedbackService::MAX_COMMENT }}" placeholder="What did you like? What should we do better?">{{ old('comment') }}</textarea></div>

            <label class="tick"><input type="checkbox" name="contact_ok" value="1" @checked(old('contact_ok'))> You may contact me about my feedback</label>
            <label class="tick"><input type="checkbox" name="may_quote" value="1" @checked(old('may_quote'))> You may show my comment and first name on your website</label>

            <button class="btn" type="submit"><i class="ri-send-plane-line"></i> Send my feedback</button>
        </form>
    @endif
</div>
@endsection

@push('head')
<style>
    .fbk { max-width: 680px; }
    .fbk .head { margin: 24px 0 14px; }
    .fbk .head h1 { margin: 6px 0 4px; font-size: 30px; }
    .fbk .back { text-decoration: none; }
    .fbk form.panel { display: grid; gap: 22px; margin-bottom: 40px; }
    .fbk fieldset { border: 0; margin: 0; padding: 0; }
    .fbk legend { font-weight: 700; margin-bottom: 10px; padding: 0; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .stars { display: inline-flex; flex-direction: row-reverse; gap: 2px; }
    .stars input { position: absolute; opacity: 0; pointer-events: none; }
    .stars label { cursor: pointer; font-size: 26px; color: #cfd8d5; line-height: 1; padding: 2px; }
    .stars.big label { font-size: 42px; }
    .stars label:hover, .stars label:hover ~ label, .stars input:checked ~ label { color: var(--brass); }
    .stars input:focus-visible + label { outline: 3px solid #f2b84b; border-radius: 6px; }
    .aspect { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--line); }
    .aspect:last-child { border-bottom: 0; }
    .nps { display: grid; grid-template-columns: repeat(11, 1fr); gap: 4px; }
    .nps input { position: absolute; opacity: 0; pointer-events: none; }
    .nps label { display: flex; align-items: center; justify-content: center; min-height: 42px; border: 1.5px solid var(--line); border-radius: 10px; cursor: pointer; font-weight: 600; background: #fff; }
    .nps input:checked + label { background: var(--lagoon); color: #fff; border-color: var(--lagoon); }
    .nps input:focus-visible + label { outline: 3px solid #f2b84b; }
    .nps-ends { display: flex; justify-content: space-between; margin-top: 4px; }
    .tick { display: flex; gap: 10px; align-items: center; font-size: 15px; }
    .tick input { width: 20px; height: 20px; accent-color: var(--lagoon); }
    .err { color: var(--bad); font-size: 14px; margin-top: 4px; }
    .stars-show { color: var(--brass); font-size: 34px; margin-bottom: 8px; letter-spacing: 2px; } .stars-show .off { color: #cfd8d5; }
    blockquote { margin: 12px 0; padding-left: 14px; border-left: 3px solid var(--line); }
    @media (max-width: 480px) { .nps { grid-template-columns: repeat(6, 1fr); } .stars.big label { font-size: 36px; } }
</style>
@endpush
