@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Edit ' . $guest->full_name" icon="ri-user-settings-line"
               subtitle="Guest details are kept for future stays. ID details are only visible to staff who manage guests."
               :back="route('guests.show', $guest)" back-label="Guest profile" />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('guests.update', $guest) }}" class="rm-form">
        @csrf @method('PUT')
        <x-cb.card title="Details" icon="ri-user-3-line">
            <div class="row g-3">
                <div class="col-md-2"><label>Title</label><input type="text" name="title" class="form-control" maxlength="20" list="titles" value="{{ old('title', $guest->title) }}">
                    <datalist id="titles"><option value="Mr"><option value="Mrs"><option value="Ms"><option value="Dr"><option value="Chief"><option value="Prof"></datalist></div>
                <div class="col-md-5"><label>First name <span class="text-danger">*</span></label><input type="text" name="first_name" class="form-control" maxlength="100" required value="{{ old('first_name', $guest->first_name) }}"></div>
                <div class="col-md-5"><label>Last name <span class="text-danger">*</span></label><input type="text" name="last_name" class="form-control" maxlength="100" required value="{{ old('last_name', $guest->last_name) }}"></div>
                <div class="col-md-4"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="30" value="{{ old('phone', $guest->phone) }}"></div>
                <div class="col-md-4"><label>Email</label><input type="email" name="email" class="form-control" maxlength="255" value="{{ old('email', $guest->email) }}"></div>
                <div class="col-md-4"><label>Gender</label>
                    <select name="gender" class="form-select"><option value="">—</option>
                        @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}" @selected(old('gender', $guest->gender) === $v)>{{ $l }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label>Date of birth</label><input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth', $guest->date_of_birth?->toDateString()) }}"></div>
                <div class="col-md-4"><label>Nationality</label><input type="text" name="nationality" class="form-control" maxlength="100" value="{{ old('nationality', $guest->nationality) }}"></div>
                <div class="col-md-4"><label>Company</label><input type="text" name="company" class="form-control" maxlength="150" value="{{ old('company', $guest->company) }}"></div>
                <div class="col-md-4"><label>ID type</label>
                    <select name="id_type" class="form-select"><option value="">—</option>
                        @foreach ($idTypes as $t)<option @selected(old('id_type', $guest->id_type) === $t)>{{ $t }}</option>@endforeach
                    </select></div>
                <div class="col-md-4"><label>ID number</label><input type="text" name="id_number" class="form-control" maxlength="100" value="{{ old('id_number', $guest->id_number) }}"></div>
                <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="is_vip" id="is_vip" value="1" @checked(old('is_vip', $guest->is_vip))>
                    <label class="form-check-label" for="is_vip">VIP guest</label></div></div>
                <div class="col-12"><label>Address</label><input type="text" name="address" class="form-control" maxlength="500" value="{{ old('address', $guest->address) }}"></div>
                <div class="col-md-6"><label>Preferences</label><textarea name="preferences" class="form-control" rows="3" maxlength="2000" placeholder="e.g. High floor, no feather pillows">{{ old('preferences', $guest->preferences) }}</textarea></div>
                <div class="col-md-6"><label>Internal notes</label><textarea name="notes" class="form-control" rows="3" maxlength="2000">{{ old('notes', $guest->notes) }}</textarea></div>
            </div>
        </x-cb.card>
        <div class="d-flex justify-content-between gap-2 mb-4">
            <div>
                @if (! $guest->reservations()->exists())
                    <button type="submit" form="deleteGuest" class="btn btn-outline-danger"><i class="ri-delete-bin-line"></i> Delete guest</button>
                @endif
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('guests.show', $guest) }}" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save</button>
            </div>
        </div>
    </form>
    <form id="deleteGuest" method="POST" action="{{ route('guests.destroy', $guest) }}" onsubmit="return confirm('Delete this guest?');" class="d-none">@csrf @method('DELETE')</form>

</div>
</div>
</div>
@endsection
