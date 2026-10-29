@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-mail-star-line" subtitle="People who asked for the hotel's news on the website or in the guest app. Download the list for your e-mail tool." :back="route('website.index')" back-label="Website">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('website.subscribers.export') }}"><i class="ri-download-2-line"></i> Download (CSV)</a></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('website.admin._nav', ['active' => 'subscribers'])

    <div class="cb-card">
        @if ($list->isEmpty())
            <div class="empty-state py-5"><i class="ri-mail-star-line"></i><h6>No sign-ups yet</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>E-mail</th><th>Name</th><th>From</th><th>Signed up</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($list as $s)
                            <tr>
                                <td data-label="E-mail"><a href="mailto:{{ $s->email }}">{{ $s->email }}</a></td>
                                <td data-label="Name">{{ $s->name ?: '—' }}</td>
                                <td data-label="From">{{ $s->source === 'app' ? 'Guest app' : 'Website' }}</td>
                                <td data-label="Signed up">{{ $s->created_at->format('j M Y') }}</td>
                                <td class="text-end">@can('Manage website')<form method="POST" action="{{ route('website.subscribers.remove', $s) }}" onsubmit="return confirm('Take {{ $s->email }} off the list?')">@csrf<button class="btn btn-sm btn-soft-danger">Take off the list</button></form>@endcan</td>
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
