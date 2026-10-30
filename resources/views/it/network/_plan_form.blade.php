{{-- Add / edit a guest Wi-Fi plan. $plan is null when adding. --}}
@php $id = $plan ? 'planModal' . $plan->id : 'planModal'; $p = $plan ? 'p' . $plan->id . '_' : 'p_'; @endphp
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ $plan ? route('it.network.plans.update', $plan) : route('it.network.plans.store') }}">@csrf @if ($plan) @method('PUT') @endif
            <div class="modal-header"><h5 class="modal-title"><i class="ri-speed-up-line me-1"></i> {{ $plan ? 'Edit ' . $plan->name : 'Add a Wi-Fi plan' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="{{ $p }}name">Name <span class="text-danger">*</span></label><input id="{{ $p }}name" name="name" class="form-control" maxlength="80" required value="{{ $plan->name ?? '' }}" placeholder="e.g. Standard, Premium, Business"></div>
                <div class="col-12"><label for="{{ $p }}desc">Description</label><input id="{{ $p }}desc" name="description" class="form-control" maxlength="255" value="{{ $plan->description ?? '' }}" placeholder="e.g. Browsing, e-mail and social media"></div>
                <div class="col-6"><label for="{{ $p }}down">Download (Mbps) <span class="text-danger">*</span></label><input id="{{ $p }}down" type="number" step="0.5" min="0.5" name="down_mbps" class="form-control" required value="{{ $plan->down_mbps ?? 10 }}"></div>
                <div class="col-6"><label for="{{ $p }}up">Upload (Mbps) <span class="text-danger">*</span></label><input id="{{ $p }}up" type="number" step="0.25" min="0.25" name="up_mbps" class="form-control" required value="{{ $plan->up_mbps ?? 3 }}"></div>
                <div class="col-4"><label for="{{ $p }}dev">Devices</label><input id="{{ $p }}dev" type="number" min="1" max="50" name="devices" class="form-control" required value="{{ $plan->devices ?? 3 }}"></div>
                <div class="col-4"><label for="{{ $p }}cap">Data a day (GB)</label><input id="{{ $p }}cap" type="number" step="0.1" min="0.1" name="data_cap_gb" class="form-control" value="{{ $plan && $plan->data_cap_mb ? round($plan->data_cap_mb / 1024, 1) : '' }}" placeholder="Unlimited"></div>
                <div class="col-4"><label for="{{ $p }}price">Price a day</label><input id="{{ $p }}price" type="number" step="0.01" min="0" name="price" class="form-control" value="{{ $plan->price ?? 0 }}"><div class="form-text">0 = free</div></div>
                <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_default" value="1" @checked($plan->is_default ?? false)> <span class="form-check-label">Give this plan to every guest at check-in</span></label>
                    <label class="form-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($plan->is_active ?? true)> <span class="form-check-label">Available</span></label></div>
            </div>
            <div class="modal-footer">
                @if ($plan)<button type="submit" form="{{ $id }}Del" class="btn btn-outline-danger me-auto">Delete</button>@endif
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
        @if ($plan)<form id="{{ $id }}Del" method="POST" action="{{ route('it.network.plans.destroy', $plan) }}" onsubmit="return confirm('Delete this plan?');">@csrf @method('DELETE')</form>@endif
    </div>
</div>
