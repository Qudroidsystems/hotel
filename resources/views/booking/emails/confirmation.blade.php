@php $sym = config('hotel.currency_symbol'); $types = $r->rooms->groupBy('room_type_id')->map(fn ($g) => ($g->count() > 1 ? $g->count() . ' × ' : '') . $g->first()->roomType?->name)->implode(', '); @endphp
<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $r->guest->first_name }},</p>
<p style="margin:0 0 16px;line-height:1.5">{{ $r->status->value === 'pending' ? 'We’ve received your booking and are holding your room.' : 'Your booking is confirmed. We look forward to welcoming you.' }}</p>
<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="background:#f4f7f6;border-radius:10px;font-size:15px">
<tr><td style="color:#66748a">Reference</td><td style="font-weight:bold">{{ $r->reference }}</td></tr>
<tr><td style="color:#66748a">Room</td><td>{{ $types }}</td></tr>
<tr><td style="color:#66748a">Check-in</td><td>{{ $r->check_in_date->format('l j F Y') }}</td></tr>
<tr><td style="color:#66748a">Check-out</td><td>{{ $r->check_out_date->format('l j F Y') }}</td></tr>
<tr><td style="color:#66748a">Total</td><td>{{ $sym }}{{ number_format((float) $r->total_amount, 2) }}</td></tr>
@if ($depositDue > 0)<tr><td style="color:#8a5a00">Deposit due</td><td style="color:#8a5a00;font-weight:bold">{{ $sym }}{{ number_format($depositDue, 2) }}@if ($r->hold_expires_at) by {{ $r->hold_expires_at->format('D j M, g:i A') }}@endif</td></tr>@endif
</table>
<p style="margin:22px 0"><a href="{{ $url }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">{{ $depositDue > 0 ? 'Pay deposit and view booking' : 'View my booking' }}</a></p>
<p style="margin:0 0 6px;font-size:14px;color:#66748a;line-height:1.5">Your booking page has your check-in QR code. You can pay, cancel within the policy, and send requests during your stay. Keep the link private.</p>
</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #d6e0dc;font-size:13px;color:#66748a">{{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}{{ config('hotel.booking.contact_phone') ? ' · ' . config('hotel.booking.contact_phone') : '' }}</td></tr>
</table></td></tr></table>
</body></html>
