@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-shield-keyhole-line" subtitle="Who signs in with a code from their phone as well as a password">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('two-factor.show') }}"><i class="ri-user-settings-line"></i> My own set-up</a>
        </x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-4"><x-cb.stat label="Using it" :value="$counts['on'] . ' of ' . $counts['all']" icon="ri-shield-check-line" accent="green" /></div>
        <div class="col-6 col-lg-4"><x-cb.stat label="Must set it up" :value="$counts['missing']" icon="ri-alarm-warning-line" :accent="$counts['missing'] ? 'rose' : 'teal'" hint="asked at their next sign-in" /></div>
        <div class="col-12 col-lg-4"><x-cb.stat label="Required for" :value="count($roles) ?: 'Nobody'" icon="ri-team-line" accent="violet" :hint="$roles ? implode(', ', $roles) : 'Set HOTEL_2FA_ROLES in .env'" /></div>
    </div>

    <div class="rs-tabs">
        @foreach (['' => 'Everyone', 'on' => 'Using it', 'off' => 'Not using it', 'missing' => 'Must set it up'] as $k => $label)
            <a href="{{ route('security.two-factor', array_filter(['show' => $k])) }}" class="rs-tab {{ (string) $filter === $k ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <x-cb.card title="Staff accounts" icon="ri-team-line" :count="$users->count()" flush>
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Name</th><th>Role</th><th>Two-step sign-in</th><th>Recovery codes</th><th>Last sign-in</th><th></th></tr></thead>
                <tbody>
                    @forelse ($users as $r)
                        <tr>
                            <td data-label="Name" class="fw-semibold">{{ $r->user->name }}<div class="small text-muted">{{ $r->user->email }}</div></td>
                            <td data-label="Role">{{ $r->user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                            <td data-label="Two-step sign-in">
                                @if ($r->enabled)
                                    <span class="status-pill st-success">On since {{ $r->user->two_factor_confirmed_at->format('j M Y') }}</span>
                                @elseif ($r->required)
                                    <span class="status-pill st-danger">Required, not set up</span>
                                @else
                                    <span class="status-pill st-muted">Off</span>
                                @endif
                            </td>
                            <td data-label="Recovery codes" class="{{ $r->left !== null && $r->left <= 2 ? 'text-danger fw-semibold' : '' }}">{{ $r->left ?? '—' }}</td>
                            <td data-label="Last sign-in">{{ $r->user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="text-end">
                                @if ($r->enabled && $r->user->id !== auth()->id())
                                    <form method="POST" action="{{ route('security.two-factor.reset', $r->user) }}" class="d-flex gap-2 justify-content-end rm-form">
                                        @csrf
                                        <input name="reason" class="form-control form-control-sm" style="max-width:200px" placeholder="Why? e.g. lost phone" required aria-label="Reason">
                                        <button class="action-btn btn-more text-nowrap" type="submit"><i class="ri-restart-line"></i> Reset</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Nobody here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

    <div class="cb-banner info mt-3"><i class="ri-information-line"></i>
        <div>Resetting turns two-step sign-in off for that person so they can set it up again on a new phone. Check who is asking before you reset: it's how someone could take over an account. If an administrator is locked out and nobody can reset them, run <code>php artisan hotel:2fa-reset their@email</code> on the server.</div>
    </div>

</div>
</div>
</div>
@endsection
