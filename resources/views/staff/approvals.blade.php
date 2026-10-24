@extends('layouts.staff')

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $labels = ['expense' => 'Expenses', 'po' => 'Purchase orders', 'pr' => 'Purchase requests', 'shift' => 'Cash-ups to sign off', 'count' => 'Stocktakes to post'];
@endphp

@if ($items->isEmpty())
    <div class="sa-empty"><i class="ri-checkbox-circle-line"></i><div class="fw-bold">Nothing to approve</div><div class="small">Requests from your team will appear here.</div></div>
@endif

@foreach ($labels as $kind => $title)
    @php $group = $items->where('kind', $kind); @endphp
    @continue($group->isEmpty())
    <div class="sa-sec">{{ $title }} · {{ $group->count() }}</div>

    @foreach ($group as $it)
        @php $m = $it->model; @endphp
        <div class="sa-item">
            @if ($kind === 'expense')
                <div class="d-flex justify-content-between gap-2"><div class="t">{{ $m->description }}</div><div class="t text-nowrap">{{ $fmt($m->amount) }}</div></div>
                <div class="m">{{ $m->category?->name }}{{ $m->department ? ' · ' . $m->department : '' }} · {{ $m->payee_name ?: 'payee not given' }}</div>
                <div class="m">{{ $m->number }} · by {{ $m->recorder?->name }} · {{ $m->expense_date->format('j M') }} · {{ count($m->attachments ?? []) ? count($m->attachments) . ' receipt(s)' : 'no receipt' }}</div>
                <div class="sa-btns">
                    <form method="POST" action="{{ route('expenses.approve', $m) }}" data-once>@csrf<button class="sa-btn go"><i class="ri-check-line"></i> Approve</button></form>
                    <button type="button" class="sa-btn bad" style="flex:0 0 auto" data-bs-toggle="collapse" data-bs-target="#rej-e{{ $m->id }}">Reject</button>
                    <a href="{{ route('expenses.show', $m) }}" class="sa-btn" style="flex:0 0 auto">View</a>
                </div>
                <form method="POST" action="{{ route('expenses.reject', $m) }}" class="collapse mt-2" id="rej-e{{ $m->id }}" data-once>@csrf
                    <input name="reason" class="form-control mb-2" maxlength="255" required placeholder="Why it's rejected">
                    <button class="sa-btn bad w-100">Reject {{ $m->number }}</button></form>

            @elseif ($kind === 'po')
                <div class="d-flex justify-content-between gap-2"><div class="t">{{ $m->supplier?->name }}</div><div class="t text-nowrap">{{ $fmt($m->total) }}</div></div>
                <div class="m">{{ $m->number }} · {{ $m->lines_count }} item(s) · sent by {{ $m->submittedBy?->name ?? '—' }} {{ $it->at?->diffForHumans() }}</div>
                <div class="sa-btns">
                    <form method="POST" action="{{ route('inventory.po.approve', $m) }}" data-once>@csrf<button class="sa-btn go"><i class="ri-check-line"></i> Approve</button></form>
                    <button type="button" class="sa-btn bad" style="flex:0 0 auto" data-bs-toggle="collapse" data-bs-target="#rej-po{{ $m->id }}">Send back</button>
                    <a href="{{ route('inventory.po.show', $m) }}" class="sa-btn" style="flex:0 0 auto">Items</a>
                </div>
                <form method="POST" action="{{ route('inventory.po.reject', $m) }}" class="collapse mt-2" id="rej-po{{ $m->id }}" data-once>@csrf
                    <input name="reason" class="form-control mb-2" maxlength="255" required placeholder="What needs changing">
                    <button class="sa-btn bad w-100">Send {{ $m->number }} back</button></form>

            @elseif ($kind === 'pr')
                <div class="d-flex justify-content-between gap-2"><div class="t">{{ $m->department }} request</div><div class="t text-nowrap">≈ {{ $fmt($m->estimated_total) }}</div></div>
                <div class="m">{{ $m->number }} · {{ $m->lines_count }} item(s) · {{ $m->requester?->name }}{{ $m->needed_by ? ' · needed by ' . $m->needed_by->format('j M') : '' }}</div>
                @if ($m->reason)<div class="small mt-1">{{ \Illuminate\Support\Str::limit($m->reason, 140) }}</div>@endif
                <div class="sa-btns">
                    <form method="POST" action="{{ route('inventory.requests.decide', $m) }}" data-once>@csrf<input type="hidden" name="decision" value="approve"><button class="sa-btn go"><i class="ri-check-line"></i> Approve</button></form>
                    <form method="POST" action="{{ route('inventory.requests.decide', $m) }}" data-once style="flex:0 0 auto">@csrf<input type="hidden" name="decision" value="reject"><button class="sa-btn bad">Reject</button></form>
                    <a href="{{ route('inventory.requests.show', $m) }}" class="sa-btn" style="flex:0 0 auto">Items</a>
                </div>

            @elseif ($kind === 'shift')
                @php $v = (float) $m->variance; $tol = (float) config('hotel.cash.variance_tolerance'); @endphp
                <div class="d-flex justify-content-between gap-2"><div class="t">{{ $m->user?->name }} · {{ $m->station }}</div>
                    <div class="t text-nowrap {{ abs($v) > $tol ? 'text-danger' : 'text-success' }}">{{ $v < 0 ? 'short ' : ($v > 0 ? 'over ' : '') }}{{ $fmt(abs($v)) }}</div></div>
                <div class="m">{{ $m->number }} · closed {{ $m->closed_at?->format('D j M, g:i A') }} · counted {{ $fmt($m->counted_cash) }} of {{ $fmt($m->expected_cash) }} expected</div>
                @if ($m->close_note)<div class="small mt-1"><i class="ri-chat-quote-line text-muted"></i> {{ $m->close_note }}</div>@endif
                <form method="POST" action="{{ route('cash-shifts.review', $m) }}" class="mt-2" data-once>@csrf
                    @if (abs($v) > $tol)<input name="review_note" class="form-control mb-2" maxlength="500" placeholder="Note about the difference (optional)">@endif
                    <div class="sa-btns mt-0"><button class="sa-btn go"><i class="ri-check-line"></i> Sign off</button><a href="{{ route('cash-shifts.show', $m) }}" class="sa-btn" style="flex:0 0 auto">Details</a></div>
                </form>

            @else
                <div class="t">Stocktake {{ $m->number }}</div>
                <div class="m">{{ $m->location?->name }} · {{ $m->lines_count }} item(s) counted · ready to post</div>
                <div class="sa-btns"><a href="{{ route('inventory.counts.show', $m) }}" class="sa-btn go"><i class="ri-eye-line"></i> Review differences &amp; post</a></div>
            @endif
        </div>
    @endforeach
@endforeach
@endsection
