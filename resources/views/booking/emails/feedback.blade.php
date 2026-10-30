<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $r->guest->first_name }},</p>
<p style="margin:0 0 16px;line-height:1.5">Thank you for staying with us from {{ $r->check_in_date->format('j M') }} to {{ $r->check_out_date->format('j M Y') }}. How was it? Our managers read every answer, and it takes about a minute.</p>
<p style="margin:0 0 6px;font-size:15px;color:#66748a">Your stay overall:</p>
<p style="margin:0 0 22px;font-size:30px;letter-spacing:4px">@for ($i = 1; $i <= 5; $i++)<a href="{{ $url }}?overall={{ $i }}" style="color:#b7832f;text-decoration:none" title="{{ $i }} star{{ $i > 1 ? 's' : '' }}">&#9733;</a>@endfor</p>
<p style="margin:0 0 22px"><a href="{{ $url }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">Rate my stay</a></p>
<p style="margin:0;font-size:14px;color:#66748a;line-height:1.5">If something wasn’t right, tell us there or reply to this e-mail. We’d like the chance to put it right.</p>
</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #d6e0dc;font-size:13px;color:#66748a">{{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}{{ config('hotel.booking.contact_phone') ? ' · ' . config('hotel.booking.contact_phone') : '' }}</td></tr>
</table></td></tr></table>
</body></html>
