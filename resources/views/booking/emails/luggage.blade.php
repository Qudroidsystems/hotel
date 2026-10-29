<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $r->guest->first_name }},</p>
<p style="margin:0 0 16px;line-height:1.5">You check out today by {{ $time }}. Before you leave the room, go through the luggage list you made so nothing is left behind. {{ $left->count() === 1 ? 'This item is' : 'These ' . $left->count() . ' items are' }} not ticked off yet:</p>
<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="background:#f4f7f6;border-radius:10px;font-size:15px">
@foreach ($left->take(30) as $i)
<tr><td style="width:22px;color:#0f766e">&#9744;</td><td>{{ $i->label }}@if ($i->bag)<span style="color:#66748a"> · {{ $i->bag }}</span>@endif</td></tr>
@endforeach
@if ($left->count() > 30)<tr><td></td><td style="color:#66748a">and {{ $left->count() - 30 }} more</td></tr>@endif
</table>
<p style="margin:22px 0"><a href="{{ $url }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">Tick off my luggage</a></p>
<p style="margin:0 0 6px;font-size:14px;color:#66748a;line-height:1.5">Also check the safe, the wardrobe, the bathroom and chargers by the bed. If you do leave something, call us and we’ll keep it safe for you.</p>
</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #d6e0dc;font-size:13px;color:#66748a">{{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}{{ config('hotel.booking.contact_phone') ? ' · ' . config('hotel.booking.contact_phone') : '' }}</td></tr>
</table></td></tr></table>
</body></html>
