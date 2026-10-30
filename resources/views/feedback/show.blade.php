@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $g = $f->guest; $r = $f->reservation; @endphp
<style>.fb-stars { color: #b7832f; } .fb-big { font-size: 30px; }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="($g?->full_name ?? 'Guest') . ' · ' . $f->overall . ' / 5'" icon="ri-star-smile-line" :back="route('feedback.index')" back-label="Guest Feedback"
               :subtitle="($r ? $r->reference . ' · ' . $r->check_in_date->format('j M') . ' – ' . $r->check_out_date->format('j M Y') . ' · ' : '') . 'sent ' . $f->created_at->format('D j M, g:i A')">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $f->status_label }}</span>
            @if ($f->nps_group)<span class="cb-meta-pill">{{ ucfirst($f->nps_group) }}</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($f->status === 'follow_up')<div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>This guest was unhappy. Call them back{{ $f->contact_ok ? ' (they said that is fine)' : '' }}, then note what was done below.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="What they said" icon="ri-chat-quote-line">
                <div class="fb-stars fb-big mb-2">@for ($i = 1; $i <= 5; $i++)<i class="{{ $i <= $f->overall ? 'ri-star-fill' : 'ri-star-line' }}"></i>@endfor</div>
                @if ($f->comment)<p style="white-space: pre-line" class="mb-3">{{ $f->comment }}</p>@else<p class="text-muted">No comment.</p>@endif
                <div class="row g-2 small">
                    @foreach (\App\Models\Booking\GuestFeedback::ASPECTS as $k => [$label, $icon])
                        <div class="col-6 col-md-4"><span class="text-muted"><i class="{{ $icon }}"></i> {{ $label }}</span><div>{{ isset($f->ratings[$k]) ? $f->ratings[$k] . ' / 5' : '—' }}</div></div>
                    @endforeach
                    <div class="col-6 col-md-4"><span class="text-muted"><i class="ri-thumb-up-line"></i> Would recommend</span><div>{{ $f->recommend !== null ? $f->recommend . ' / 10' : '—' }}</div></div>
                </div>
                <hr>
                <div class="small text-muted">
                    {{ $f->contact_ok ? 'Happy to be contacted.' : 'Did not ask to be contacted.' }}
                    {{ $f->may_quote ? 'Agreed to have the comment shown on the website.' : '' }}
                    Sent from the {{ ['web' => 'booking page', 'app' => 'guest app', 'desk' => 'front desk'][$f->source] ?? $f->source }}.
                </div>
            </x-cb.card>
            @if ($earlier->isNotEmpty())
                <x-cb.card title="Earlier stays" icon="ri-history-line" class="mt-3">
                    @foreach ($earlier as $e)
                        <div class="d-flex justify-content-between small py-1"><a href="{{ route('feedback.show', $e) }}">{{ $e->reservation?->reference }} · {{ $e->reservation?->check_in_date?->format('M Y') }}</a><span class="fb-stars">{{ $e->overall }} / 5</span></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
        <div class="col-xl-5">
            <x-cb.card title="Guest" icon="ri-user-line">
                <div class="small">
                    <div class="fw-semibold">{{ $g?->full_name ?? '—' }}</div>
                    @if ($g?->phone)<div><a href="tel:{{ $g->phone }}">{{ $g->phone }}</a></div>@endif
                    @if ($g?->email)<div><a href="mailto:{{ $g->email }}">{{ $g->email }}</a></div>@endif
                    @if ($r)<div class="mt-2">Room {{ $r->rooms->pluck('room.number')->filter()->implode(', ') ?: '—' }} · <a href="{{ route('reservations.show', $r) }}">Open the booking</a></div>@endif
                </div>
            </x-cb.card>
            @can('Handle guest feedback')
                <x-cb.card title="Follow-up" icon="ri-customer-service-2-line" class="mt-3">
                    @if ($f->follow_up_note)<p class="small" style="white-space: pre-line">{{ $f->follow_up_note }}</p>@endif
                    @if ($f->handler)<p class="small text-muted">Last updated by {{ $f->handler->name }}, {{ $f->handled_at?->diffForHumans() }}.</p>@endif
                    <form method="POST" action="{{ route('feedback.handle', $f) }}" class="row g-2" data-once>@csrf
                        <div class="col-12"><label for="fbNote" class="form-label small">What was done</label>
                            <textarea name="note" id="fbNote" class="form-control" rows="3" maxlength="2000" placeholder="e.g. Called the guest, apologised, refunded one night; AC in 204 serviced."></textarea></div>
                        <div class="col-7"><select name="status" class="form-select" aria-label="Status">
                            @foreach (\App\Models\Booking\GuestFeedback::STATUSES as $k => [$label])<option value="{{ $k }}" @selected($k === 'resolved')>{{ $label }}</option>@endforeach
                        </select></div>
                        <div class="col-5"><button class="action-btn btn-open w-100" type="submit"><i class="ri-save-line"></i> Save</button></div>
                    </form>
                    @if ($f->may_quote && $f->comment)
                        <hr>
                        @if ($f->website_item_id)
                            <p class="small mb-0"><i class="ri-check-line text-success"></i> Shown on the website with the guest reviews.</p>
                        @elsecan('Manage website')
                            <form method="POST" action="{{ route('feedback.publish', $f) }}" data-once>@csrf
                                <button class="action-btn btn-more" type="submit"><i class="ri-global-line"></i> Show on the website</button>
                                <div class="form-text">The guest agreed. Shown with their first name and initial.</div>
                            </form>
                        @endcan
                    @endif
                </x-cb.card>
            @endcan
        </div>
    </div>

</div>
</div>
</div>
@endsection
