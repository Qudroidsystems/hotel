{{-- Contact / event enquiry / table booking form. $kind (contact|event|dining), optional $venues (names), $halls (names), $anchor --}}
@php $kind = $kind ?? 'contact'; $anchor = $anchor ?? 'form'; @endphp
@if (session('sent'))
    <div class="notice"><i class="ri-checkbox-circle-line"></i><div>{{ session('sent') }}</div></div>
@endif
@if ($errors->any() && old('kind', 'contact') === $kind)
    <div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>
@endif
<form method="POST" action="{{ route('site.send') }}" class="form" style="position:relative">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <input type="hidden" name="return_to" value="{{ $anchor }}">
    <span class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></span>
    <div class="row2">
        <div class="f"><label for="{{ $kind }}_name">Your name</label><input id="{{ $kind }}_name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></div>
        <div class="f"><label for="{{ $kind }}_email">E-mail</label><input id="{{ $kind }}_email" type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email"></div>
    </div>
    @if ($kind === 'event')
        <div class="row3">
            <div class="f"><label for="ev_type">Type of event</label><select id="ev_type" name="event_type">@foreach (['Wedding', 'Conference or meeting', 'Birthday or party', 'Product launch', 'Training', 'Other'] as $o)<option @selected(old('event_type') === $o)>{{ $o }}</option>@endforeach</select></div>
            <div class="f"><label for="ev_date">Date</label><input id="ev_date" type="date" name="date" value="{{ old('date') }}" min="{{ today()->toDateString() }}" required></div>
            <div class="f"><label for="ev_guests">Guests</label><input id="ev_guests" type="number" name="guests" value="{{ old('guests', 50) }}" min="1" max="5000" required></div>
        </div>
        @if (! empty($halls) && count($halls))
            <div class="f"><label for="ev_hall">Preferred hall</label><select id="ev_hall" name="hall"><option value="">No preference</option>@foreach ($halls as $h)<option @selected(old('hall') === $h)>{{ $h }}</option>@endforeach</select></div>
        @endif
    @elseif ($kind === 'dining')
        <div class="row3">
            <div class="f"><label for="dn_date">Date</label><input id="dn_date" type="date" name="date" value="{{ old('date', today()->toDateString()) }}" min="{{ today()->toDateString() }}" required></div>
            <div class="f"><label for="dn_time">Time</label><input id="dn_time" type="time" name="time" value="{{ old('time', '19:30') }}" required></div>
            <div class="f"><label for="dn_guests">Guests</label><input id="dn_guests" type="number" name="guests" value="{{ old('guests', 2) }}" min="1" max="200" required></div>
        </div>
        @if (! empty($venues) && count($venues))
            <div class="f"><label for="dn_venue">Where</label><select id="dn_venue" name="venue">@foreach ($venues as $v)<option @selected(old('venue') === $v)>{{ $v }}</option>@endforeach</select></div>
        @endif
    @endif
    <div class="row2">
        <div class="f"><label for="{{ $kind }}_phone">Phone <span style="text-transform:none;letter-spacing:0">(optional)</span></label><input id="{{ $kind }}_phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" autocomplete="tel"></div>
        @if ($kind === 'contact')
            <div class="f"><label for="ct_subject">About</label><select id="ct_subject" name="subject">@foreach (['A question about a stay', 'A booking I have made', 'Dining', 'Meetings & events', 'Something else'] as $o)<option @selected(old('subject') === $o)>{{ $o }}</option>@endforeach</select></div>
        @endif
    </div>
    <div class="f"><label for="{{ $kind }}_msg">{{ $kind === 'contact' ? 'Message' : 'Anything we should know?' }}</label><textarea id="{{ $kind }}_msg" name="message" maxlength="4000" @if ($kind === 'contact') required @endif>{{ old('message') }}</textarea></div>
    <div><button class="btn" type="submit">{{ ['event' => 'Send enquiry', 'dining' => 'Ask for a table'][$kind] ?? 'Send message' }} <i class="ri-send-plane-line"></i></button></div>
    <p class="muted" style="font-size:13.5px;margin:0">We only use your details to answer you. <a href="{{ route('legal.privacy') }}" style="border-bottom:1px solid var(--accent)">Privacy</a></p>
</form>
