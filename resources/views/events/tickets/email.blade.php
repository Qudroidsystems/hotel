@php $sym = config('hotel.currency_symbol'); $first = \Illuminate\Support\Str::before(trim($order->buyer_name), ' ') ?: $order->buyer_name; @endphp
<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $first }},</p>
<p style="margin:0 0 16px;line-height:1.5">Here {{ $order->quantity > 1 ? 'are your ' . $order->quantity . ' tickets' : 'is your ticket' }} for <strong>{{ $event->title }}</strong>. Show the QR code at the door — each ticket lets you in once.</p>
<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="background:#f4f7f6;border-radius:10px;font-size:15px">
<tr><td style="color:#66748a">When</td><td style="font-weight:bold">{{ $event->when }}</td></tr>
@if ($event->hall)<tr><td style="color:#66748a">Where</td><td>{{ $event->hall->name }}, {{ config('hotel.name') }}</td></tr>@endif
<tr><td style="color:#66748a">Order</td><td>{{ $order->number }}</td></tr>
<tr><td style="color:#66748a">Paid</td><td>{{ (float) $order->total > 0 ? $sym . number_format((float) $order->total, 2) : 'Free' }}@if ((float) $order->discount > 0) (saved {{ $sym }}{{ number_format((float) $order->discount, 2) }})@endif</td></tr>
</table>
<p style="margin:22px 0"><a href="{{ $order->url }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">Show my {{ $order->quantity > 1 ? 'tickets' : 'ticket' }}</a></p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
@foreach ($order->tickets as $i => $t)
<tr><td style="padding:10px 0;border-top:1px dashed #d6e0dc">{{ $t->type->name }}{{ $t->type->admits > 1 ? ' (admits ' . $t->type->admits . ')' : '' }}<br><span style="font-family:Menlo,Consolas,monospace;letter-spacing:2px;font-weight:bold">{{ $t->code }}</span></td>
<td align="right" style="padding:10px 0;border-top:1px dashed #d6e0dc"><a href="{{ route('tickets.public', $t->code) }}" style="color:#0f766e">Ticket {{ $i + 1 }}</a></td></tr>
@endforeach
</table>
@if ($event->ticket_info)<p style="margin:18px 0 0;font-size:14px;color:#66748a;line-height:1.5;white-space:pre-line">{{ $event->ticket_info }}</p>@endif
<p style="margin:14px 0 0;font-size:13px;color:#66748a;line-height:1.5">Keep this e-mail private: anyone with a ticket's code can use it once. Can't open the link at the door? Staff can type the code.</p>
</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #d6e0dc;font-size:13px;color:#66748a">{{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}{{ config('hotel.booking.contact_phone') ? ' · ' . config('hotel.booking.contact_phone') : '' }}</td></tr>
</table></td></tr></table>
</body></html>
