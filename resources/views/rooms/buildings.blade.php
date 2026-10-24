@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Buildings & Floors" icon="ri-building-2-line"
               subtitle="The physical layout of the property. Rooms are placed on a floor inside a building or block."
               :back="route('rooms.index')" back-label="Room Board">
        @can('Manage buildings')
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#addBuildingModal"><i class="ri-add-line"></i> Add building</button>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($buildings->isEmpty())
        <x-cb.card>
            <div class="empty-state">
                <i class="ri-building-2-line"></i>
                <h6>No buildings yet</h6>
                <p>Even a single-building hotel needs one entry here, e.g. “Main Building”.</p>
                @can('Manage buildings')
                    <button type="button" class="action-btn btn-primary-cb mt-3" data-bs-toggle="modal" data-bs-target="#addBuildingModal"><i class="ri-add-line"></i> Add building</button>
                @endcan
            </div>
        </x-cb.card>
    @endif

    <div class="row g-3">
        @foreach ($buildings as $b)
            <div class="col-xl-6">
                <x-cb.card :title="$b->name" icon="ri-building-2-line" :count="$b->rooms_count . ' rooms'" :flush="true">
                    <x-slot:tools>
                        <span class="subject-code">{{ $b->code }}</span>
                        @unless ($b->is_active)<span class="status-pill st-muted">Inactive</span>@endunless
                        @can('Manage buildings')
                            <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#editBuilding{{ $b->id }}" title="Edit building"><i class="ri-pencil-line"></i></button>
                            <form method="POST" action="{{ route('buildings.destroy', $b) }}" onsubmit="return confirm('Delete {{ $b->name }} and its floors?');">
                                @csrf @method('DELETE')
                                <button class="action-btn btn-danger-soft" type="submit" title="Delete building" @disabled($b->rooms_count > 0)><i class="ri-delete-bin-line"></i></button>
                            </form>
                        @endcan
                    </x-slot:tools>

                    @if ($b->description)<div class="px-4 pt-3 small text-muted">{{ $b->description }}</div>@endif

                    <table class="cb-table">
                        <thead><tr><th>Level</th><th>Floor</th><th class="num">Rooms</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($b->floors as $f)
                                <tr>
                                    <td class="text-muted" style="width:70px">{{ $f->level }}</td>
                                    <td class="fw-semibold">{{ $f->name }}</td>
                                    <td class="num"><a href="{{ route('rooms.index', ['floor' => $f->id]) }}" class="text-reset">{{ $f->rooms_count }}</a></td>
                                    <td class="text-end text-nowrap">
                                        @can('Create rooms')
                                            <a href="{{ route('rooms.create', ['building' => $b->id, 'floor' => $f->id]) }}" class="action-btn btn-go" title="Add a room on this floor"><i class="ri-add-line"></i></a>
                                        @endcan
                                        @can('Manage buildings')
                                            <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#editFloor{{ $f->id }}" title="Rename floor"><i class="ri-pencil-line"></i></button>
                                            <form method="POST" action="{{ route('floors.destroy', $f) }}" class="d-inline" onsubmit="return confirm('Delete {{ $f->name }}?');">
                                                @csrf @method('DELETE')
                                                <button class="action-btn btn-danger-soft" type="submit" title="Delete floor" @disabled($f->rooms_count > 0)><i class="ri-delete-bin-line"></i></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No floors yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    @can('Manage buildings')
                        <form method="POST" action="{{ route('floors.store', $b) }}" class="cb-toolbar border-top border-bottom-0">
                            @csrf
                            <input type="number" name="level" class="cb-select" style="width:110px" min="-5" max="100" required placeholder="Level" aria-label="Floor level"
                                   value="{{ ($b->floors->max('level') ?? -1) + 1 }}">
                            <input type="text" name="name" class="cb-select flex-grow-1" maxlength="100" placeholder="Name (optional, e.g. Penthouse)" aria-label="Floor name">
                            <button type="submit" class="action-btn btn-primary-cb"><i class="ri-add-line"></i> Add floor</button>
                        </form>
                    @endcan
                </x-cb.card>
            </div>

            @can('Manage buildings')
                {{-- Edit building --}}
                <div class="modal fade" id="editBuilding{{ $b->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content rm-form" method="POST" action="{{ route('buildings.update', $b) }}">
                            @csrf @method('PUT')
                            <div class="modal-header"><h5 class="modal-title">Edit {{ $b->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body row g-3">
                                <div class="col-8"><label>Name</label><input type="text" name="name" class="form-control" required maxlength="100" value="{{ $b->name }}"></div>
                                <div class="col-4"><label>Code</label><input type="text" name="code" class="form-control text-uppercase" required maxlength="20" value="{{ $b->code }}"></div>
                                <div class="col-12"><label>Description</label><input type="text" name="description" class="form-control" maxlength="500" value="{{ $b->description }}"></div>
                                <div class="col-6"><label>Display order</label><input type="number" name="sort_order" class="form-control" min="0" max="999" value="{{ $b->sort_order }}"></div>
                                <div class="col-6 d-flex align-items-end">
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="bActive{{ $b->id }}" @checked($b->is_active)><label class="form-check-label" for="bActive{{ $b->id }}">Active</label></div>
                                </div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                        </form>
                    </div>
                </div>
                {{-- Edit floors --}}
                @foreach ($b->floors as $f)
                    <div class="modal fade" id="editFloor{{ $f->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <form class="modal-content rm-form" method="POST" action="{{ route('floors.update', $f) }}">
                                @csrf @method('PUT')
                                <div class="modal-header"><h5 class="modal-title">Edit floor</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    <label>Level</label><input type="number" name="level" class="form-control mb-2" min="-5" max="100" required value="{{ $f->level }}">
                                    <label>Name</label><input type="text" name="name" class="form-control" required maxlength="100" value="{{ $f->name }}">
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endcan
        @endforeach
    </div>

</div>
</div>
</div>

@can('Manage buildings')
<div class="modal fade" id="addBuildingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('buildings.store') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-building-2-line me-1"></i> Add building</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-8"><label>Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required maxlength="100" value="{{ old('name') }}" placeholder="Main Building"></div>
                <div class="col-4"><label>Code <span class="text-danger">*</span></label><input type="text" name="code" class="form-control text-uppercase" required maxlength="20" value="{{ old('code') }}" placeholder="MAIN"></div>
                <div class="col-12"><label>Description</label><input type="text" name="description" class="form-control" maxlength="500" value="{{ old('description') }}"></div>
                <div class="col-12">
                    <label>Create floors</label>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted">from level</span>
                        <input type="number" name="floors_from" class="form-control" style="width:90px" min="-5" max="100" value="{{ old('floors_from', 0) }}">
                        <span class="small text-muted">to</span>
                        <input type="number" name="floors_to" class="form-control" style="width:90px" min="-5" max="100" value="{{ old('floors_to', 3) }}">
                    </div>
                    <div class="form-text">0 = Ground Floor. Clear both boxes to add floors later.</div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add building</button></div>
        </form>
    </div>
</div>
@if ($errors->any() && old('code') !== null && old('floors_from') !== null)
    <script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('addBuildingModal')).show());</script>
@endif
@endcan
@endsection
