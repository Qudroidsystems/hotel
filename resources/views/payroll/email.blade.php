@php $sym = config('hotel.currency_symbol'); $first = \Illuminate\Support\Str::before(trim($s->name), ' ') ?: $s->name; @endphp
<!doctype html>
<html><body style="margin:0;background:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#14213d">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d6e0dc">
<tr><td style="background:#14213d;color:#ffffff;padding:22px 26px;font-family:Georgia,serif;font-size:22px;font-weight:bold">{{ config('hotel.name') }}</td></tr>
<tr><td style="padding:26px">
<p style="font-size:17px;margin:0 0 12px">Hello {{ $first }},</p>
<p style="margin:0 0 16px;line-height:1.5">Your payslip for <strong>{{ $run->month_label }}</strong> is ready.</p>
<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="background:#f4f7f6;border-radius:10px;font-size:15px">
<tr><td style="color:#66748a">Gross pay</td><td align="right">{{ $sym }}{{ number_format((float) $s->gross, 2) }}</td></tr>
<tr><td style="color:#66748a">Deductions</td><td align="right">{{ $sym }}{{ number_format((float) $s->total_deductions, 2) }}</td></tr>
<tr><td style="font-weight:bold">Net pay</td><td align="right" style="font-weight:bold">{{ $sym }}{{ number_format((float) $s->net, 2) }}</td></tr>
</table>
<p style="margin:22px 0"><a href="{{ route('payroll.my.show', $s) }}" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold;display:inline-block">See my payslip</a></p>
<p style="margin:0;font-size:13px;color:#66748a">Sign in to see it. It's also in the staff app under Me → My payslips.</p>
</td></tr></table></td></tr></table>
</body></html>
