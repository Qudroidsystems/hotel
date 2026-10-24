{{-- Emergency contact fields. $c = the contact being edited, or null. --}}
<div class="col-6"><input type="text" name="name" class="form-control form-control-sm" maxlength="120" required value="{{ $c?->name }}" placeholder="Name" aria-label="Name"></div>
<div class="col-6"><input type="text" name="role" class="form-control form-control-sm" maxlength="120" value="{{ $c?->role }}" placeholder="e.g. Fire service" aria-label="Role"></div>
<div class="col-6"><input type="tel" name="phone" class="form-control form-control-sm" maxlength="40" required value="{{ $c?->phone }}" placeholder="Phone" aria-label="Phone"></div>
<div class="col-6"><input type="tel" name="alt_phone" class="form-control form-control-sm" maxlength="40" value="{{ $c?->alt_phone }}" placeholder="Other phone" aria-label="Other phone"></div>
<div class="col-9"><input type="text" name="notes" class="form-control form-control-sm" maxlength="255" value="{{ $c?->notes }}" placeholder="Notes" aria-label="Notes"></div>
<div class="col-3"><input type="number" name="sort_order" class="form-control form-control-sm" min="0" max="999" value="{{ $c?->sort_order ?? 0 }}" title="Order in the list" aria-label="Order"></div>
