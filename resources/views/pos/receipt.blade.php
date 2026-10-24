@php use App\Models\Pos\PosOrder; $o = $order; $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.'); @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $o->number }} | {{ config('hotel.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; font: 13px/1.35 ui-monospace, 'SF Mono', Menlo, Consolas, monospace; color: #000; }
        .bar { display: flex; gap: 8px; justify-content: center; padding: 12px; }
        .bar button, .bar a { border: 0; background: #0f2342; color: #fff; padding: 8px 14px; border-radius: 8px; font: 600 13px system-ui, sans-serif; cursor: pointer; text-decoration: none; }
        .bar a { background: #64748b; }
        .slip { width: 80mm; max-width: 100%; margin: 0 auto 20px; background: #fff; padding: 5mm 4mm; }
        .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
        .big { font-size: 16px; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .void { border: 2px solid #000; padding: 4px; margin: 6px 0; text-align: center; font-weight: 800; letter-spacing: 2px; }
        @media print { body { background: #fff; } .bar { display: none; } .slip { margin: 0; width: auto; padding: 0; } @page { size: 80mm auto; margin: 3mm; } }
    </style>
</head>
<body>
    <div class="bar"><a href="{{ route('pos.orders') }}">Back</a><button type="button" onclick="window.print()">Print</button></div>
    <div class="slip">
        <div class="c b big">{{ config('hotel.name') }}</div>
        <div class="c">{{ $o->outlet->name }}</div>
        <hr>
        <div>Order <span class="b">{{ $o->number }}</span></div>
        <div>{{ $o->ordered_at->format('d/m/Y H:i') }} · {{ $o->cashier?->name }}</div>
        @if ($o->table_label)<div>Table: {{ $o->table_label }}</div>@endif
        @if ($o->isVoided())<div class="void">VOID</div>@endif
        <hr>
        <table>
            @foreach ($o->items as $i)
                <tr><td colspan="2">{{ $i->name }}</td></tr>
                <tr><td>&nbsp; {{ $qty($i->quantity) }} × {{ number_format((float) $i->unit_price, 2) }}</td><td class="r">{{ number_format((float) $i->total, 2) }}</td></tr>
                @if ($i->note)<tr><td colspan="2">&nbsp; ({{ $i->note }})</td></tr>@endif
            @endforeach
        </table>
        <hr>
        <table>
            @if ($o->discount_amount > 0 || $o->tax_amount > 0)<tr><td>Subtotal</td><td class="r">{{ number_format((float) $o->subtotal, 2) }}</td></tr>@endif
            @if ($o->discount_amount > 0)<tr><td>Discount</td><td class="r">-{{ number_format((float) $o->discount_amount, 2) }}</td></tr>@endif
            @if ($o->tax_amount > 0)
                @foreach ($o->tax_breakdown ?? [] as $t)<tr><td>{{ $t['name'] }} {{ $t['rate'] }}%</td><td class="r">{{ number_format((float) $t['amount'], 2) }}</td></tr>@endforeach
            @endif
            <tr class="b big"><td>TOTAL {{ config('hotel.currency_symbol') }}</td><td class="r">{{ number_format((float) $o->total, 2) }}</td></tr>
            @if ($o->method === PosOrder::ROOM)
                <tr><td colspan="2">Charged to room {{ $o->room_number }}</td></tr>
                <tr><td colspan="2">{{ $o->guest_name }}</td></tr>
            @else
                <tr><td>Paid by {{ $o->method_label }}</td><td class="r">{{ number_format((float) ($o->tendered ?? $o->total), 2) }}</td></tr>
                @if ($o->change_due > 0)<tr><td>Change</td><td class="r">{{ number_format((float) $o->change_due, 2) }}</td></tr>@endif
                @if ($o->reference)<tr><td colspan="2">Ref: {{ $o->reference }}</td></tr>@endif
            @endif
        </table>
        @if ($o->tax_amount <= 0 && $o->tax_breakdown)
            <div style="font-size:11px;margin-top:4px">Prices include {{ collect($o->tax_breakdown)->map(fn ($t) => $t['name'] . ' ' . number_format((float) $t['amount'], 2))->join(', ') }}</div>
        @endif
        @if ($o->method === PosOrder::ROOM)
            <div style="margin-top:22px">Guest signature: ______________________</div>
        @endif
        <hr>
        <div class="c">{{ $o->outlet->receipt_footer ?: 'Thank you!' }}</div>
        <div class="c" style="font-size:10px;margin-top:4px">Reprinted {{ now()->format('d/m/Y H:i') }}</div>
    </div>
</body>
</html>
