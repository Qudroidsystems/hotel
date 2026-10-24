@php $r = request()->route()?->getName(); @endphp
<div class="rs-tabs">
    @canany(['View IT control room', 'Manage IT monitoring', 'Manage IT tickets'])
        <a href="{{ route('it.index') }}" class="rs-tab {{ $r === 'it.index' ? 'active' : '' }}"><i class="ri-dashboard-3-line"></i> Control room</a>
        <a href="{{ route('it.alerts.index') }}" class="rs-tab {{ str_starts_with($r, 'it.alerts') ? 'active' : '' }}"><i class="ri-alarm-warning-line"></i> Alerts</a>
    @endcanany
    <a href="{{ route('it.tickets.index') }}" class="rs-tab {{ str_starts_with($r, 'it.tickets') ? 'active' : '' }}"><i class="ri-customer-service-2-line"></i> Service desk</a>
    @canany(['View IT control room', 'Manage IT monitoring'])
        <a href="{{ route('it.monitors.index') }}" class="rs-tab {{ str_starts_with($r, 'it.monitors') ? 'active' : '' }}"><i class="ri-pulse-line"></i> Monitors</a>
        <a href="{{ route('it.assets.index') }}" class="rs-tab {{ str_starts_with($r, 'it.assets') ? 'active' : '' }}"><i class="ri-router-line"></i> Equipment</a>
        <a href="{{ route('it.maintenance.index') }}" class="rs-tab {{ str_starts_with($r, 'it.maintenance') ? 'active' : '' }}"><i class="ri-tools-line"></i> Maintenance</a>
    @endcanany
    @canany(['View IT control room', 'Manage IT monitoring', 'Manage IT tickets'])
        <a href="{{ route('it.report') }}" class="rs-tab {{ $r === 'it.report' ? 'active' : '' }}"><i class="ri-file-chart-line"></i> Monthly report</a>
    @endcanany
</div>
