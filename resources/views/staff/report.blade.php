@extends('layouts.staff')

@section('content')
@php
    $me = auth()->user();
    $room = request()->integer('room') ?: null;
    $task = request()->integer('task') ?: null;
@endphp

<div class="sa-grid mb-2" style="grid-template-columns: repeat(2, 1fr)">
    <a href="{{ route('it.tickets.index') }}#new" class="sa-tile" style="--tile:#6366f1"><i class="ri-customer-service-2-line"></i>IT problem<span class="small text-muted fw-normal">computer, Wi-Fi, till, printer</span></a>
    @can('Record expenses')<a href="{{ route('expenses.index') }}#new" class="sa-tile" style="--tile:#0d9488"><i class="ri-wallet-3-line"></i>Expense<span class="small text-muted fw-normal">with a photo of the receipt</span></a>@endcan
</div>

@canany(['Report maintenance issues', 'Manage maintenance'])
    <div class="sa-card" id="fault">
        <h2><i class="ri-tools-line text-warning"></i> Report a fault</h2>
        <form method="POST" action="{{ route('maintenance.store') }}" enctype="multipart/form-data" data-once>@csrf
            <input type="hidden" name="back" value="staff">
            @if ($task)<input type="hidden" name="housekeeping_task_id" value="{{ $task }}">@endif
            <div class="row g-2">
                <div class="col-6"><label for="f_room">Room</label><select name="room_id" id="f_room" class="form-select"><option value="">Not a room</option>@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected(old('room_id', $room) == $r->id)>{{ $r->number }}</option>@endforeach</select></div>
                <div class="col-6"><label for="f_loc">…or where</label><input name="location" id="f_loc" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="Lobby, kitchen…"></div>
                <div class="col-12"><label for="f_title">What's wrong? <span class="text-danger">*</span></label><input name="title" id="f_title" class="form-control" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. AC not cooling"></div>
                <div class="col-6"><label for="f_cat">Kind</label><select name="category" id="f_cat" class="form-select">@foreach (\App\Models\MaintenanceOrder::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-6"><label for="f_pri">How urgent</label><select name="priority" id="f_pri" class="form-select">@foreach (\App\Models\MaintenanceOrder::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected(old('priority', 'normal') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-12"><label for="f_desc">Details</label><textarea name="description" id="f_desc" class="form-control" rows="2" maxlength="2000">{{ old('description') }}</textarea></div>
                <div class="col-12"><label for="f_photo">Photo</label><input type="file" name="photos[]" id="f_photo" class="form-control" accept="image/*" capture="environment"></div>
                <div class="col-12"><label class="form-check mt-1"><input type="checkbox" class="form-check-input" name="blocks_room" value="1" @checked(old('blocks_room'))> <span class="form-check-label" style="font-weight:500">Room can't be sold until fixed</span></label></div>
            </div>
            <button class="sa-btn go w-100 mt-3"><i class="ri-send-plane-fill"></i> Send to maintenance</button>
        </form>
    </div>
@endcanany

@canany(['Report incidents', 'Manage incidents'])
    <div class="sa-card" id="incident">
        <h2><i class="ri-alarm-warning-line text-danger"></i> Report an incident</h2>
        <form method="POST" action="{{ route('incidents.store') }}" enctype="multipart/form-data" data-once>@csrf
            <div class="row g-2">
                <div class="col-12"><label for="i_title">What happened? <span class="text-danger">*</span></label><input name="title" id="i_title" class="form-control" maxlength="150" required placeholder="e.g. Guest's phone missing from room"></div>
                <div class="col-6"><label for="i_type">Kind</label><select name="type" id="i_type" class="form-select">@foreach (\App\Models\Incident::TYPES as $k => [$label])<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                <div class="col-6"><label for="i_sev">Severity</label><select name="severity" id="i_sev" class="form-select">@foreach (\App\Models\Incident::SEVERITIES as $k => [$label])<option value="{{ $k }}" @selected($k === 'medium')>{{ $label }}</option>@endforeach</select></div>
                <div class="col-6"><label for="i_room">Room</label><select name="room_id" id="i_room" class="form-select"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->number }}</option>@endforeach</select></div>
                <div class="col-6"><label for="i_loc">…or where</label><input name="location" id="i_loc" class="form-control" maxlength="120" placeholder="Car park, bar…"></div>
                <div class="col-12"><label for="i_desc">Details <span class="text-danger">*</span></label><textarea name="description" id="i_desc" class="form-control" rows="3" maxlength="5000" required placeholder="Who, what, when — facts only"></textarea></div>
                <div class="col-12"><label for="i_photo">Photo</label><input type="file" name="photos[]" id="i_photo" class="form-control" accept="image/*" capture="environment"></div>
            </div>
            <button class="sa-btn bad w-100 mt-3" style="background:#dc2626;color:#fff"><i class="ri-alarm-warning-fill"></i> Report incident</button>
            <div class="small text-muted mt-2">In an emergency, call first — then report.</div>
        </form>
    </div>
@endcanany
@endsection
