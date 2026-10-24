{{-- Tabs across the attendance pages. $active: board|timesheet|leave|setup --}}
<div class="rs-tabs" role="tablist">
    @can('View attendance')
        <a href="{{ route('attendance.board') }}" class="rs-tab {{ $active === 'board' ? 'active' : '' }}"><i class="ri-user-follow-line"></i> Today</a>
        <a href="{{ route('attendance.timesheet') }}" class="rs-tab {{ $active === 'timesheet' ? 'active' : '' }}"><i class="ri-calendar-2-line"></i> Timesheet</a>
        <a href="{{ route('attendance.leave') }}" class="rs-tab {{ $active === 'leave' ? 'active' : '' }}"><i class="ri-suitcase-line"></i> Leave</a>
    @endcan
    @can('Manage staff & shifts')
        <a href="{{ route('attendance.setup') }}" class="rs-tab {{ $active === 'setup' ? 'active' : '' }}"><i class="ri-settings-3-line"></i> Staff, shifts &amp; kiosks</a>
    @endcan
    @can('Run attendance kiosk')
        <a href="{{ route('attendance.kiosk') }}" class="rs-tab"><i class="ri-qr-scan-2-line"></i> Open kiosk</a>
    @endcan
</div>
