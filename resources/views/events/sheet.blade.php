@extends('billing.print-layout')
@php
    use App\Models\Events\EventItem;
    use App\Models\Events\Hall;
    $e = $event;
@endphp
@section('title', 'Event sheet ' . $e->number)
@section('heading', 'Event sheet')
@section('back', route('events.show', $e))

@section('body')
    @if ($e->status !== 'confirmed')<div class="note" style="margin:0 0 14px;background:#fef3c7;color:#92400e"><strong>{{ strtoupper($e->status_label) }}</strong> — not a confirmed event.</div>@endif
    <div class="cols">
        <div><div class="lbl">Event</div><strong>{{ $e->title }}</strong><div>{{ $e->type_label }}</div><div class="ref">{{ $e->number }}</div></div>
        <div><div class="lbl">When</div><strong>{{ $e->when }}</strong><div class="lbl" style="margin-top:8px">Where</div><strong>{{ $e->hall->name }}</strong></div>
        <div style="text-align:right"><div class="lbl">Guests</div><strong style="font-size:22px">{{ $e->guests_expected }}</strong><div>{{ $e->setup ? Hall::SETUPS[$e->setup][0] : '' }}</div></div>
    </div>
    <table>
        <tbody>
            <tr><td class="muted" style="width:160px">Organiser</td><td><strong>{{ $e->organiser_name }}</strong>{{ $e->organiser_phone ? ' · ' . $e->organiser_phone : '' }}{{ $e->company ? ' · ' . $e->company->name : '' }}</td></tr>
            @if ($e->package)<tr><td class="muted">Package</td><td><strong>{{ $e->package->name }}</strong> × {{ $e->guests_expected }}@if ($e->package->description)<div>{{ $e->package->description }}</div>@endif</td></tr>@endif
            @foreach ($e->items->groupBy('kind') as $kind => $items)
                <tr><td class="muted">{{ EventItem::KINDS[$kind] ?? $kind }}</td><td>@foreach ($items as $it)<div>{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }} × {{ $it->description }}</div>@endforeach</td></tr>
            @endforeach
        </tbody>
    </table>
    @if ($e->requirements)<div class="note"><strong>The client's requirements</strong><div style="white-space:pre-line;margin-top:6px">{{ $e->requirements }}</div></div>@endif
    @if ($e->internal_notes)<div class="note"><strong>Notes for staff</strong><div style="white-space:pre-line;margin-top:6px">{{ $e->internal_notes }}</div></div>@endif
    <table style="margin-top:22px"><tbody><tr>
        @foreach (['Kitchen', 'Setup / housekeeping', 'Front desk', 'Security'] as $dept)<td style="border:1px solid #e2e8f0;height:58px;vertical-align:bottom" class="muted">{{ $dept }} — signed</td>@endforeach
    </tr></tbody></table>
@endsection
