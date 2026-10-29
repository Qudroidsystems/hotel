@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-inbox-line" subtitle="Messages, event enquiries and table requests sent from the website. Each one is also e-mailed to the address under Website → Settings." :back="route('website.index')" back-label="Website" />

    @include('rooms.partials.flash')
    @include('website.admin._nav', ['active' => 'enquiries'])

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="status" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                <option value="open" @selected($status === 'open')>Still open</option>
                @foreach (\App\Models\Website\WebsiteMessage::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($status === $k)>{{ $l }} ({{ $counts[$k] ?? 0 }})</option>@endforeach
                <option value="all" @selected($status === 'all')>All</option>
            </select>
            <select name="kind" class="cb-select" aria-label="Kind" onchange="this.form.submit()">
                <option value="">Every kind</option>
                @foreach (\App\Models\Website\WebsiteMessage::KINDS as $k => [$l])<option value="{{ $k }}" @selected($kind === $k)>{{ $l }}</option>@endforeach
            </select>
            <input type="search" name="q" value="{{ $search }}" class="form-control" style="max-width:260px" placeholder="Name, e-mail, phone…">
        </form>
        @if ($list->isEmpty())
            <div class="empty-state py-5"><i class="ri-inbox-line"></i><h6>Nothing here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>From</th><th>About</th><th>Kind</th><th>Status</th><th>Received</th></tr></thead>
                    <tbody>
                        @foreach ($list as $m)
                            <tr>
                                <td data-label="From"><a href="{{ route('website.enquiries.show', $m) }}" class="fw-semibold text-reset">{{ $m->name }}</a>@if (! $m->read_at) <span class="badge bg-danger">new</span>@endif
                                    <div class="small text-muted">{{ $m->email }}{{ $m->phone ? ' · ' . $m->phone : '' }}</div></td>
                                <td data-label="About">{{ $m->subject }}<div class="small text-muted">{{ collect($m->detail_lines)->except('Sent from')->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}</div></td>
                                <td data-label="Kind"><i class="{{ \App\Models\Website\WebsiteMessage::KINDS[$m->kind][1] ?? 'ri-mail-line' }}"></i> {{ $m->kind_label }}</td>
                                <td data-label="Status"><span class="status-pill {{ $m->status_pill }}">{{ $m->status_label }}</span>@if ($m->handler)<div class="small text-muted">{{ $m->handler->name }}</div>@endif</td>
                                <td data-label="Received">{{ $m->created_at->format('D j M, g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $list->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
