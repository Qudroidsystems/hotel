@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>.lf-photo { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; border: 1px solid var(--cb-border); }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-archive-drawer-line" subtitle="Log anything left behind, keep track of where it's stored, and check ID before handing it back.">
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#foundModal"><i class="ri-add-line"></i> Log a found item</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="In storage" :value="$stats['stored']" icon="ri-archive-drawer-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Valuables in storage" :value="$stats['valuable']" icon="ri-safe-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat :label="'Unclaimed over ' . $keepDays . ' days'" :value="$stats['old']" icon="ri-time-line" accent="rose" hint="Can be donated or disposed" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Returned this month" :value="$stats['returned']" icon="ri-hand-heart-line" accent="green" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                @foreach (['stored' => 'In storage', 'claimed' => 'Returned', 'disposed' => 'Disposed', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
            </select>
            <select name="category" class="cb-select" aria-label="Category" onchange="this.form.submit()">
                <option value="">Any kind</option>
                @foreach (\App\Models\LostItem::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected($category === $k)>{{ $label }}</option>@endforeach
            </select>
            <input type="search" name="q" class="form-control" style="max-width: 260px" value="{{ $search }}" placeholder="Item, LF number, place, owner…" aria-label="Search">
        </form>
        @if ($items->isEmpty())
            <div class="empty-state py-5"><i class="ri-archive-drawer-line"></i><h6>Nothing here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th>Found</th><th>Stored at</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $it)
                            <tr>
                                <td data-label="Item"><div class="d-flex gap-2 align-items-center">
                                    @if ($url = $it->photoUrl())<a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="" class="lf-photo"></a>@endif
                                    <div><span class="fw-semibold">{{ $it->description }}</span>@if ($it->valuable) <span class="status-pill st-warning">Valuable</span>@endif
                                        <div class="small text-muted">{{ $it->number }} · {{ $it->category_label }}</div></div></div></td>
                                <td data-label="Found">{{ $it->found_location }}<div class="small text-muted">{{ $it->found_at->format('j M Y, g:i A') }}{{ $it->finder ? ' · ' . $it->finder->name : '' }}</div></td>
                                <td data-label="Stored at">{{ $it->storage_location ?? '—' }}</td>
                                <td data-label="Status"><span class="status-pill {{ $it->status_pill }}">{{ $it->status_label }}</span>
                                    @if ($it->status === 'claimed')<div class="small text-muted">to {{ $it->claimant_name }} ({{ $it->claimant_id }}) {{ $it->handed_over_at->format('j M') }}{{ $it->handedOverBy ? ' by ' . $it->handedOverBy->name : '' }}</div>
                                    @elseif ($it->status === 'disposed')<div class="small text-muted">{{ $it->disposal_note }} · {{ $it->handed_over_at->format('j M') }}</div>
                                    @elseif ($it->found_at->lt(now()->subDays($keepDays)))<div class="small text-danger">unclaimed {{ $it->found_at->diffForHumans(null, true) }}</div>@endif</td>
                                <td class="text-end text-nowrap">
                                    @if ($it->status === 'stored')
                                        <button type="button" class="action-btn btn-open" data-bs-toggle="collapse" data-bs-target="#ho{{ $it->id }}"><i class="ri-hand-heart-line"></i> Return</button>
                                        <button type="button" class="action-btn btn-more" data-bs-toggle="collapse" data-bs-target="#dp{{ $it->id }}" title="Dispose"><i class="ri-delete-bin-line"></i></button>
                                    @endif
                                </td>
                            </tr>
                            @if ($it->status === 'stored')
                                <tr class="collapse" id="ho{{ $it->id }}"><td colspan="5">
                                    <form method="POST" action="{{ route('lost-found.hand-over', $it) }}" class="rm-form row g-2" data-once>@csrf
                                        <div class="col-md-4"><input type="text" name="claimant_name" class="form-control" maxlength="150" required placeholder="Owner's name" aria-label="Owner's name"></div>
                                        <div class="col-md-3"><input type="text" name="claimant_contact" class="form-control" maxlength="60" placeholder="Phone / email" aria-label="Contact"></div>
                                        <div class="col-md-3"><input type="text" name="claimant_id" class="form-control" maxlength="80" required placeholder="ID checked, e.g. NIN 1234…" aria-label="ID checked"></div>
                                        <div class="col-md-2"><button class="action-btn btn-open w-100">Hand over</button></div>
                                    </form>
                                </td></tr>
                                <tr class="collapse" id="dp{{ $it->id }}"><td colspan="5">
                                    <form method="POST" action="{{ route('lost-found.dispose', $it) }}" class="rm-form d-flex gap-2" data-once>@csrf
                                        <input type="text" name="disposal_note" class="form-control" maxlength="255" required placeholder="e.g. Donated to charity, handed to police, destroyed (perishable)" aria-label="What happened to it">
                                        <button class="action-btn btn-more text-nowrap">Mark disposed</button>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $items->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>

<div class="modal fade" id="foundModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('lost-found.store') }}" enctype="multipart/form-data" data-once>
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-archive-drawer-line me-1"></i> Log a found item</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="lfDesc">What is it</label><input type="text" name="description" id="lfDesc" class="form-control" maxlength="255" required value="{{ old('description') }}" placeholder="e.g. Black Samsung phone in blue case"></div>
                <div class="col-7"><label for="lfCat">Kind</label>
                    <select name="category" id="lfCat" class="form-select" required>@foreach (\App\Models\LostItem::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-5 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="valuable" value="1" @checked(old('valuable'))> <span class="form-check-label fw-normal">Valuable</span></label></div>
                <div class="col-4"><label for="lfRoom">Room</label>
                    <select name="room_id" id="lfRoom" class="form-select"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected((int) old('room_id') === $r->id)>{{ $r->number }}</option>@endforeach</select></div>
                <div class="col-8"><label for="lfWhere">Or where found</label><input type="text" name="found_location" id="lfWhere" class="form-control" maxlength="120" value="{{ old('found_location') }}" placeholder="e.g. Restaurant, table 4"></div>
                <div class="col-6"><label for="lfWhen">When</label><input type="datetime-local" name="found_at" id="lfWhen" class="form-control" value="{{ old('found_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}"></div>
                <div class="col-6"><label for="lfStore">Stored at</label><input type="text" name="storage_location" id="lfStore" class="form-control" maxlength="120" value="{{ old('storage_location') }}" placeholder="e.g. Front desk safe"></div>
                <div class="col-12"><label for="lfPhoto">Photo</label><input type="file" name="photo" id="lfPhoto" class="form-control" accept="image/*" capture="environment"></div>
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open"><i class="ri-save-line"></i> Log item</button></div>
        </form>
    </div>
</div>
@if ($errors->any() && old('description'))
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('foundModal')).show(); });</script>
@endif
@endsection
