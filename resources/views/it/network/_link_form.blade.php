{{-- Add / edit an internet line. $link is null when adding. --}}
@php use App\Models\Network\NetworkLink; $id = $link ? 'linkModal' . $link->id : 'linkModal'; $p = $link ? 'l' . $link->id . '_' : 'l_'; @endphp
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ $link ? route('it.network.links.update', $link) : route('it.network.links.store') }}">@csrf @if ($link) @method('PUT') @endif
            <div class="modal-header"><h5 class="modal-title"><i class="ri-rocket-2-line me-1"></i> {{ $link ? 'Edit ' . $link->name : 'Add an internet line' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label for="{{ $p }}name">Name <span class="text-danger">*</span></label><input id="{{ $p }}name" name="name" class="form-control" maxlength="120" required value="{{ $link->name ?? '' }}" placeholder="e.g. Starlink 1 – Main roof"></div>
                <div class="col-sm-3"><label for="{{ $p }}provider">Type</label><select id="{{ $p }}provider" name="provider" class="form-select">@foreach (NetworkLink::PROVIDERS as $k => [$label])<option value="{{ $k }}" @selected(($link->provider ?? 'starlink') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-3"><label for="{{ $p }}role">Carries</label><select id="{{ $p }}role" name="role" class="form-select">@foreach (NetworkLink::ROLES as $k => [$label, $hint])<option value="{{ $k }}" title="{{ $hint }}" @selected(($link->role ?? 'shared') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="{{ $p }}identifier">Dish serial / account no.</label><input id="{{ $p }}identifier" name="identifier" class="form-control" maxlength="120" value="{{ $link->identifier ?? '' }}"></div>
                <div class="col-sm-4"><label for="{{ $p }}address">Dish / modem address</label><input id="{{ $p }}address" name="address" class="form-control" maxlength="120" value="{{ $link->address ?? '' }}" placeholder="192.168.100.1"></div>
                <div class="col-sm-4"><label for="{{ $p }}port">Router port</label><input id="{{ $p }}port" name="router_port" class="form-control" maxlength="60" value="{{ $link->router_port ?? '' }}" placeholder="e.g. ether1"></div>
                <div class="col-sm-3"><label for="{{ $p }}weight">Share of traffic</label><input id="{{ $p }}weight" type="number" name="weight" min="1" max="10" class="form-control" value="{{ $link->weight ?? 1 }}"><div class="form-text">1–10, higher gets more</div></div>
                <div class="col-sm-3"><label for="{{ $p }}cap">Monthly data (GB)</label><input id="{{ $p }}cap" type="number" step="0.01" min="0" name="data_cap_gb" class="form-control" value="{{ $link->data_cap_gb ?? '' }}" placeholder="Unlimited"></div>
                <div class="col-sm-3"><label for="{{ $p }}day">Resets on day</label><input id="{{ $p }}day" type="number" min="1" max="28" name="billing_day" class="form-control" value="{{ $link->billing_day ?? 1 }}"></div>
                <div class="col-sm-3"><label for="{{ $p }}cost">Monthly cost</label><input id="{{ $p }}cost" type="number" step="0.01" min="0" name="monthly_cost" class="form-control" value="{{ $link->monthly_cost ?? '' }}"></div>
                <div class="col-sm-6"><label for="{{ $p }}loc">Where it is</label><input id="{{ $p }}loc" name="location" class="form-control" maxlength="120" value="{{ $link->location ?? '' }}" placeholder="e.g. Main building roof"></div>
                <div class="col-sm-6 d-flex align-items-end"><label class="form-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($link->is_active ?? true)> <span class="form-check-label">In use (read every minute)</span></label></div>
                <div class="col-12"><label for="{{ $p }}notes">Notes</label><textarea id="{{ $p }}notes" name="notes" class="form-control" rows="2" maxlength="1000">{{ $link->notes ?? '' }}</textarea></div>
            </div>
            <div class="modal-footer">
                @if ($link)
                    <button type="submit" form="{{ $id }}Del" class="btn btn-outline-danger me-auto">Remove</button>
                @endif
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
        @if ($link)<form id="{{ $id }}Del" method="POST" action="{{ route('it.network.links.destroy', $link) }}" onsubmit="return confirm('Remove {{ addslashes($link->name) }} and its history?');">@csrf @method('DELETE')</form>@endif
    </div>
</div>
