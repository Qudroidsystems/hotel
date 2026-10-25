@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>@media print { .vertical-menu, #page-topbar, .footer, .cb-hero .cb-hero-btn, .cb-back { display: none !important; } .main-content { margin: 0 !important; } .page-content { padding: 0 !important; } }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Daily report: ' . $audit->business_date->format('l j F Y')" icon="ri-moon-clear-line" :back="route('night-audit.index')" back-label="Night Audit"
               :subtitle="'Closed ' . ($audit->automatic ? 'automatically' : 'by ' . ($audit->closer?->name ?? '—')) . ', ' . $audit->closed_at->format('D j M Y g:i A')">
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line"></i> Print</button></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($audit->notes)<div class="cb-banner info"><i class="ri-sticky-note-line"></i><div>{{ $audit->notes }}</div></div>@endif
    @if (! empty($audit->actions['no_shows']) || ($audit->actions['nights_posted'] ?? 0))
        <div class="small text-muted mb-3">At closing: {{ $audit->actions['nights_posted'] ?? 0 }} room night(s) charged{{ ! empty($audit->actions['no_shows']) ? '; no-shows: ' . implode(', ', $audit->actions['no_shows']) : '' }}.</div>
    @endif

    @include('night-audit._report', ['r' => $r])

</div>
</div>
</div>
@endsection
