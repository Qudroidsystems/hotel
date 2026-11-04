@extends('layouts.master')

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-money-dollar-circle-line" subtitle="Monthly pay worked out from attendance: days not worked, overtime, pension, NHF and PAYE. A payroll is sent for approval, approved by someone else, then paid from the bank schedule.">
        <x-slot:actions>
            @if ($canManage)<a class="cb-hero-btn" href="{{ route('payroll.staff') }}"><i class="ri-team-line"></i> Staff pay</a>@endif
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'payroll']) }}"><i class="ri-settings-3-line"></i> Tax &amp; rules</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($withPay === 0)
        <div class="cb-banner info"><i class="ri-information-line"></i><div>No one has pay set up yet. @if ($canManage)<a href="{{ route('payroll.staff') }}">Set up staff pay</a> first.@endif</div></div>
    @elseif ($withPay < $staff)
        <div class="cb-banner warning"><i class="ri-information-line"></i><div>{{ $staff - $withPay }} of {{ $staff }} staff have no pay set up, so they won't be on the payroll. @if ($canManage)<a href="{{ route('payroll.staff') }}">See who</a>.@endif</div></div>
    @endif

    <div class="row g-3">
        @if ($canManage)
        <div class="col-xl-4">
            <x-cb.card title="Run payroll" icon="ri-play-circle-line">
                <form method="POST" action="{{ route('payroll.start') }}" class="d-flex gap-2">@csrf
                    <input type="month" name="period" class="form-control" value="{{ $suggest }}" max="{{ today()->format('Y-m') }}" required aria-label="Month">
                    <button class="btn btn-primary text-nowrap">Start</button>
                </form>
                <p class="small text-muted mt-2 mb-0">Works out everyone's pay from the month's attendance. Opening a month that already has a payroll takes you to it.</p>
            </x-cb.card>
        </div>
        @endif
        <div class="{{ $canManage ? 'col-xl-8' : 'col-12' }}">
            <x-cb.card title="Payrolls" icon="ri-file-list-3-line" :flush="true">
                <table class="cb-table stack">
                    <thead><tr><th>Month</th><th class="num">Staff</th><th class="num">Gross</th><th class="num">Net pay</th><th class="num">PAYE</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($runs as $r)
                            <tr>
                                <td data-label="Month"><a href="{{ route('payroll.show', $r) }}" class="fw-semibold">{{ $r->month_label }}</a><div class="small text-muted">{{ $r->number }}</div></td>
                                <td data-label="Staff" class="num">{{ $r->staff_count }}</td>
                                <td data-label="Gross" class="num">{{ $sym }}{{ number_format((float) $r->gross, 2) }}</td>
                                <td data-label="Net pay" class="num fw-semibold">{{ $sym }}{{ number_format((float) $r->net, 2) }}</td>
                                <td data-label="PAYE" class="num">{{ $sym }}{{ number_format((float) $r->paye, 2) }}</td>
                                <td data-label="Status"><span class="status-pill {{ $r->status_pill }}">{{ $r->status_label }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="small text-muted">No payroll yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>
@endsection
