@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $q = request()->only(['period', 'show', 'stars', 'comments']);
    $diff = function ($now, $before, $suffix = '') {
        if ($now === null || $before === null) return null;
        $d = round($now - $before, 1);
        return ($d > 0 ? '+' : '') . $d . $suffix . ' vs the ' . 'period before';
    };
    $max = max(1, max($summary['stars']));
@endphp
<style>
    .fb-bars { display: grid; gap: 6px; }
    .fb-bar { display: grid; grid-template-columns: 54px 1fr 36px; gap: 10px; align-items: center; font-size: 13px; }
    .fb-bar .track { height: 10px; background: var(--cb-border, #e5e9ef); border-radius: 99px; overflow: hidden; }
    .fb-bar .fill { height: 100%; background: #b7832f; border-radius: 99px; }
    .fb-stars { color: #b7832f; white-space: nowrap; }
    .fb-aspect { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--cb-border, #e5e9ef); font-size: 14px; }
    .fb-aspect:last-child { border-bottom: 0; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-star-smile-line" subtitle="What guests said after they checked out. Low scores are flagged so someone calls the guest back.">
        <x-slot:actions>
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'feedback']) }}"><i class="ri-settings-3-line"></i> Survey settings</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Average score" :value="$summary['average'] !== null ? $summary['average'] . ' / 5' : '—'" icon="ri-star-line" accent="amber" :hint="$diff($summary['average'], $previous['average'])" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Net promoter score" :value="$summary['nps'] ?? '—'" icon="ri-thumb-up-line" accent="teal" :hint="$diff($summary['nps'], $previous['nps']) ?? 'From −100 to +100'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Answers" :value="$summary['count']" icon="ri-chat-check-line" accent="green" :hint="$summary['response'] !== null ? $summary['response'] . '% of ' . $summary['checkouts'] . ' check-outs' : 'No check-outs in this period'" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('feedback.index', ['show' => 'follow_up'] + $q) }}"><x-cb.stat label="Need follow-up" :value="$summary['open']" icon="ri-alarm-warning-line" accent="rose" :hint="$summary['low'] . ' low score(s) in this period'" /></a></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <select name="period" class="cb-select" aria-label="Period" onchange="this.form.submit()">
                        @foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months'] as $k => $label)<option value="{{ $k }}" @selected($period == $k)>{{ $label }}</option>@endforeach
                    </select>
                    <select name="show" class="cb-select" aria-label="Status" onchange="this.form.submit()">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Booking\GuestFeedback::STATUSES as $k => [$label])<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <select name="stars" class="cb-select" aria-label="Score" onchange="this.form.submit()">
                        <option value="">Any score</option>
                        <option value="low" @selected($stars === 'low')>Low scores</option>
                        <option value="high" @selected($stars === 'high')>4 and 5 stars</option>
                    </select>
                    <label class="form-check mb-0 ms-1"><input class="form-check-input" type="checkbox" name="comments" value="1" @checked(request()->boolean('comments')) onchange="this.form.submit()"> <span class="form-check-label">With a comment</span></label>
                </form>
                @if ($rows->isEmpty())
                    <div class="empty-state py-5"><i class="ri-star-smile-line"></i><h6>No feedback here yet</h6><p class="text-muted small mb-0">Guests are asked {{ (int) config('hotel.feedback.invite_after_hours', 3) }} hours after they check out.</p></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Guest</th><th>Score</th><th>Comment</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($rows as $f)
                                    <tr>
                                        <td data-label="Guest"><a href="{{ route('feedback.show', $f) }}" class="fw-semibold text-reset">{{ $f->guest?->full_name ?? 'Guest' }}</a>
                                            <div class="small text-muted">{{ $f->reservation?->reference }} · {{ $f->created_at->format('j M') }} · {{ ['web' => 'booking page', 'app' => 'app', 'desk' => 'front desk'][$f->source] ?? $f->source }}</div></td>
                                        <td data-label="Score"><span class="fb-stars">@for ($i = 1; $i <= 5; $i++)<i class="{{ $i <= $f->overall ? 'ri-star-fill' : 'ri-star-line' }}"></i>@endfor</span>
                                            @if ($f->recommend !== null)<div class="small text-muted">recommend {{ $f->recommend }}/10</div>@endif</td>
                                        <td data-label="Comment" class="small">{{ $f->comment ? str($f->comment)->limit(140) : '—' }}</td>
                                        <td data-label="Status"><span class="status-pill {{ $f->status_pill }}">{{ $f->status_label }}</span>@if ($f->contact_ok && $f->status === 'follow_up')<div class="small text-muted">happy to be called</div>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $rows->links() }}</div>
                @endif
            </div>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Stars" icon="ri-bar-chart-horizontal-line">
                <div class="fb-bars">
                    @foreach ($summary['stars'] as $s => $n)
                        <div class="fb-bar"><span>{{ $s }} star{{ $s > 1 ? 's' : '' }}</span><div class="track"><div class="fill" style="width: {{ round($n / $max * 100) }}%"></div></div><span class="text-end">{{ $n }}</span></div>
                    @endforeach
                </div>
            </x-cb.card>
            <x-cb.card title="By part of the stay" icon="ri-list-check-2" class="mt-3">
                @foreach ($summary['aspects'] as $a)
                    <div class="fb-aspect"><span><i class="{{ $a['icon'] }} text-muted"></i> {{ $a['label'] }}</span>
                        <strong>{{ $a['average'] !== null ? $a['average'] . ' / 5' : '—' }}</strong></div>
                @endforeach
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
