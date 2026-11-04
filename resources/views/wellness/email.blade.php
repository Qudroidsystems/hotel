@php $sym = config('hotel.currency_symbol'); $first = \Illuminate\Support\Str::before(trim($b->guest_name), ' ') ?: $b->guest_name; @endphp
<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $first }},</p>
<p style="margin:0 0 16px;line-height:1.5">You're booked at <strong>{{ $b->venue->name }}</strong>. Please arrive 10 minutes early.</p>
<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="background:#f4f7f6;border-radius:10px;font-size:15px">
<tr><td style="color:#66748a">What</td><td style="font-weight:bold">{{ $b->what }}</td></tr>
<tr><td style="color:#66748a">When</td><td style="font-weight:bold">{{ $b->starts_at->format('l j F, g:i A') }} – {{ $b->ends_at->format('g:i A') }}</td></tr>
<tr><td style="color:#66748a">Booking</td><td>{{ $b->number }}</td></tr>
<tr><td style="color:#66748a">Price</td><td>{{ (float) $b->total > 0 ? $sym . number_format((float) $b->total, 2) . ' · ' . ($b->paid_at ? 'paid online' : 'pay when you arrive') : 'Free' }}</td></tr>
</table>
<p style="margin:22px 0"><a href="{{ route('wellness.mine', $b->token) }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">See my booking</a></p>
<p style="margin:0;font-size:13px;color:#66748a;line-height:1.5">Need to change it? Call us{{ config('hotel.booking.contact_phone') ? ' on ' . config('hotel.booking.contact_phone') : '' }}.</p>
</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #d6e0dc;font-size:13px;color:#66748a">{{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}</td></tr>
</table></td></tr></table>
</body></html>
