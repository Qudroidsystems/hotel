@extends('layouts.master')

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero :title="$pagetitle" icon="ri-team-line" subtitle="What each person is paid. Only people with pay set up (and switched on) are on the payroll. Staff come from Attendance → Set-up.">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('payroll.index') }}"><i class="ri-arrow-left-line"></i> Payroll</a></x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    <x-cb.card title="Staff" icon="ri-user-line" :flush="true">
        <table class="cb-table stack">
            <thead><tr><th>Name</th><th>Department</th><th>Pay</th><th>Bank</th><th>Deducted</th><th></th></tr></thead>
            <tbody>
                @forelse ($profiles as $p)
                    @php $pay = $pays->get($p->user_id); @endphp
                    <tr>
                        <td data-label="Name"><strong>{{ $p->user->name }}</strong><div class="small text-muted">{{ $p->staff_no }}{{ $p->job_title ? ' · ' . $p->job_title : '' }}</div></td>
                        <td data-label="Department">{{ $p->department?->name ?? '—' }}</td>
                        <td data-label="Pay">@if (! $pay)<span class="text-danger small">Not set up</span>
                            @elseif ($pay->pay_type === 'daily'){{ $sym }}{{ number_format((float) $pay->daily_rate, 2) }} a day
                            @else{{ $sym }}{{ number_format($pay->monthly_gross, 2) }} a month @endif
                            @if ($pay && ! $pay->is_active)<span class="status-pill st-muted">Off payroll</span>@endif</td>
                        <td data-label="Bank" class="small">{{ $pay?->bank_name ?? '—' }} {{ $pay?->account_masked }}</td>
                        <td data-label="Deducted" class="small">{{ $pay ? collect(['PAYE' => $pay->paye, 'Pension' => $pay->pension, 'NHF' => $pay->nhf])->filter()->keys()->join(', ') ?: 'Nothing' : '—' }}</td>
                        <td><a href="{{ route('payroll.staff.edit', $p->user_id) }}" class="btn btn-sm {{ $pay ? 'btn-outline-primary' : 'btn-primary' }}">{{ $pay ? 'Change' : 'Set up pay' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="small text-muted">No staff yet. Add them in Attendance → Set-up.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-cb.card>
</div>
</div>
</div>
@endsection
