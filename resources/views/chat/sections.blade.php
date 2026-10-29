@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $form = $edit; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-chat-settings-line" subtitle="The parts of the hotel guests can message and call from the guest app and their booking page, who answers each, and when.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('chat.index') }}"><i class="ri-chat-smile-3-line"></i> Guest Chat</a>
            <a class="cb-hero-btn" href="{{ route('chat.sections', ['new' => 1]) }}"><i class="ri-add-line"></i> Add a section</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Sections" icon="ri-list-check" :flush="true">
                <table class="cb-table stack">
                    <thead><tr><th>Section</th><th>Hours</th><th>Who answers</th><th>Calls</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($sections as $s)
                            <tr style="{{ $s->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Section"><span class="fw-semibold"><i class="{{ $s->icon }}"></i> {{ $s->name }}</span>
                                    @unless ($s->is_active)<span class="status-pill st-muted">Off</span>@endunless
                                    <div class="small text-muted">{{ $s->description }}</div></td>
                                <td data-label="Hours" class="small">{{ $s->hours_label ?? 'Always' }}
                                    <div><span class="status-pill {{ $s->isOpen() ? 'st-success' : 'st-muted' }}">{{ $s->isOpen() ? 'Open now' : 'Closed now' }}</span></div></td>
                                <td data-label="Who answers" class="small">{{ collect($s->roles)->implode(', ') ?: '—' }}@if (count((array) $s->users)) <span class="text-muted">+ {{ count($s->users) }} {{ count($s->users) === 1 ? 'person' : 'people' }}</span>@endif
                                    <div class="text-muted">{{ $s->staff()->count() }} can answer</div></td>
                                <td data-label="Calls" class="small">{{ $s->calls ? 'In the app' : 'Messages only' }}@if ($s->phone)<div class="text-muted">{{ $s->phone }}</div>@endif</td>
                                <td class="text-end"><a class="action-btn btn-more" href="{{ route('chat.sections', ['edit' => $s->id]) }}"><i class="ri-pencil-line"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-cb.card>
            <p class="small text-muted">Managers (“Manage guest chat”) see every section. Others see the sections their role — or they by name — are added to, and need “Chat with guests”.</p>
        </div>

        <div class="col-xl-5">
            @if ($form || $creating)
                <x-cb.card :title="$form ? 'Edit ' . $form->name : 'Add a section'" icon="ri-pencil-line">
                    <form method="POST" action="{{ $form ? route('chat.sections.update', $form) : route('chat.sections.store') }}">
                        @csrf @if ($form) @method('PUT') @endif
                        <div class="row g-2 mb-2">
                            <div class="col-8"><label class="form-label small">Name</label><input name="name" class="form-control" required maxlength="60" value="{{ old('name', $form?->name) }}" placeholder="e.g. Spa"></div>
                            <div class="col-4"><label class="form-label small">Icon</label><input name="icon" class="form-control" maxlength="40" value="{{ old('icon', $form?->icon ?? 'ri-chat-3-line') }}" placeholder="ri-spa-line"></div>
                        </div>
                        <div class="mb-2"><label class="form-label small">What guests contact it for</label><input name="description" class="form-control" maxlength="160" value="{{ old('description', $form?->description) }}"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-4"><label class="form-label small">Opens</label><input type="time" name="opens" class="form-control" value="{{ old('opens', $form?->opens) }}"></div>
                            <div class="col-4"><label class="form-label small">Closes</label><input type="time" name="closes" class="form-control" value="{{ old('closes', $form?->closes) }}"></div>
                            <div class="col-4"><label class="form-label small">Order</label><input type="number" name="sort_order" min="0" class="form-control" value="{{ old('sort_order', $form?->sort_order ?? 0) }}"></div>
                            <div class="col-12 form-text mt-0">Leave both empty for always open. Hours may run past midnight (e.g. 18:00 – 02:00).</div>
                        </div>
                        <div class="mb-2"><label class="form-label small">Phone number <span class="text-muted">(offered too, for a normal call)</span></label><input name="phone" class="form-control" maxlength="40" value="{{ old('phone', $form?->phone) }}"></div>
                        <div class="mb-2"><label class="form-label small">Message when closed <span class="text-muted">(optional)</span></label><input name="away_message" class="form-control" maxlength="255" value="{{ old('away_message', $form?->away_message) }}" placeholder="We’re closed now — we open at 7 AM. For anything urgent, message Reception."></div>
                        <label class="form-label small">Roles that answer</label>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <input type="hidden" name="roles[]" value="">
                            @foreach ($roles as $role)
                                <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="roles[]" value="{{ $role }}" @checked(in_array($role, old('roles', (array) $form?->roles), true))> <span class="form-check-label small">{{ $role }}</span></label>
                            @endforeach
                        </div>
                        <label class="form-label small">And these people</label>
                        <select name="users[]" class="form-select mb-2" multiple size="5">
                            @foreach ($users as $u)<option value="{{ $u->id }}" @selected(in_array($u->id, (array) $form?->users, true))>{{ $u->name }}</option>@endforeach
                        </select>
                        <div class="form-check mb-1"><input type="hidden" name="calls" value="0"><input class="form-check-input" type="checkbox" name="calls" value="1" id="cs-calls" @checked(old('calls', $form?->calls ?? true))><label class="form-check-label small" for="cs-calls">Guests can call it in the app</label></div>
                        <div class="form-check mb-3"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="cs-on" @checked(old('is_active', $form?->is_active ?? true))><label class="form-check-label small" for="cs-on">Guests can reach it</label></div>
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-save-3-line"></i> Save</button>
                    </form>
                </x-cb.card>
            @else
                <x-cb.card title="How it works" icon="ri-information-line">
                    <p class="small">Guests pick a section and write or call. Everyone who answers that section gets it on the website (Guest Chat rings for calls) and in the staff app, with a push notification when the app is closed. The first to answer a call takes it.</p>
                    <p class="small mb-0">Calls go over the internet (no call charges). For calls to work between mobile networks the server needs a TURN relay — see Hotel Settings → Live Chat &amp; Calls.</p>
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
