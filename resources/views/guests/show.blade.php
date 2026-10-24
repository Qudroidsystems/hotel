@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Enums\ReservationStatus; $stays = $guest->reservations()->where('status', ReservationStatus::CheckedOut->value)->count(); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$guest->full_name" icon="ri-user-star-line"
               :subtitle="$stays ? 'Returning guest — ' . $stays . ' completed stay' . ($stays === 1 ? '' : 's') . '.' : 'No completed stays yet.'"
               :back="route('guests.index')" back-label="Guests">
        <x-slot:pills>
            @if ($guest->is_vip)<span class="cb-meta-pill"><i class="ri-vip-crown-line"></i> VIP</span>@endif
            @if ($guest->company)<span class="cb-meta-pill"><i class="ri-building-line"></i> {{ $guest->company }}</span>@endif
            <span class="cb-meta-pill"><i class="ri-calendar-line"></i> Guest since {{ $guest->created_at->format('M Y') }}</span>
        </x-slot:pills>
        <x-slot:actions>
            @can('Create reservations')
                <a class="cb-hero-btn" href="{{ route('reservations.create', ['guest' => $guest->id]) }}"><i class="ri-add-line"></i> New booking</a>
            @endcan
            @can('Manage guests')
                <a class="cb-hero-btn" href="{{ route('guests.edit', $guest) }}"><i class="ri-pencil-line"></i> Edit</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-lg-4">
            <x-cb.card title="Profile" icon="ri-user-3-line">
                <dl class="rm-meta mb-0" style="grid-template-columns: 110px 1fr">
                    <dt>Phone</dt><dd>{{ $guest->phone ?: '—' }}</dd>
                    <dt>Email</dt><dd class="text-break">{{ $guest->email ?: '—' }}</dd>
                    <dt>Gender</dt><dd>{{ $guest->gender ? ucfirst($guest->gender) : '—' }}</dd>
                    <dt>Nationality</dt><dd>{{ $guest->nationality ?: '—' }}</dd>
                    <dt>ID</dt><dd>{{ $guest->id_type ?: '—' }}@can('Manage guests'){{ $guest->id_number ? ' · ' . $guest->id_number : '' }}@endcan</dd>
                    <dt>Address</dt><dd>{{ $guest->address ?: '—' }}</dd>
                </dl>
                @if ($guest->preferences)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Preferences</div><div class="small">{{ $guest->preferences }}</div>
                @endif
                @if ($guest->notes)
                    <div class="small fw-bold text-muted text-uppercase mt-3 mb-1">Internal notes</div><div class="small">{{ $guest->notes }}</div>
                @endif
            </x-cb.card>
        </div>
        <div class="col-lg-8">
            <x-cb.card title="Bookings" icon="ri-calendar-check-line" :count="$reservations->total()" :flush="true">
                @if ($reservations->isEmpty())
                    <div class="empty-state py-4"><i class="ri-calendar-line"></i><h6>No bookings</h6></div>
                @else
                    <table class="cb-table stack">
                        <thead><tr><th>Reference</th><th>Stay</th><th>Rooms</th><th>Status</th><th class="num">Total</th></tr></thead>
                        <tbody>
                            @foreach ($reservations as $r)
                                <tr>
                                    <td data-label="Reference"><a href="{{ route('reservations.show', $r) }}" class="rs-ref text-decoration-none">{{ $r->reference }}</a></td>
                                    <td data-label="Stay" class="small">{{ $r->check_in_date->format('j M Y') }} → {{ $r->check_out_date->format('j M') }}<div class="text-muted">{{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }}</div></td>
                                    <td data-label="Rooms">@include('reservations.partials.rooms-cell', ['reservation' => $r])</td>
                                    <td data-label="Status">@include('reservations.partials.status-pill', ['status' => $r->status])</td>
                                    <td data-label="Total" class="num">@money($r->total_amount)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3">{{ $reservations->links() }}</div>
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
