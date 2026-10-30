<?php

namespace App\Http\Controllers\It;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Network\NetworkLink;
use App\Models\Network\NetworkPass;
use App\Models\Network\NetworkPlan;
use App\Models\Reservation;
use App\Services\Network\NetworkService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Internet & guest Wi-Fi: the hotel's internet lines (Starlink dishes…), speed plans and guest Wi-Fi codes. */
class NetworkController extends Controller
{
    public function __construct(private NetworkService $network)
    {
        $this->middleware('permission:View network|Manage network|Give Wi-Fi codes');
        $this->middleware('permission:Manage network')->only('refresh', 'storeLink', 'updateLink', 'destroyLink', 'storePlan', 'updatePlan', 'destroyPlan');
        $this->middleware('permission:Give Wi-Fi codes')->only('storePass', 'updatePass', 'revokePass');
    }

    public function index(Request $request)
    {
        $links = NetworkLink::ordered()->get();
        $active = $links->where('is_active', true);
        $history = $this->network->history($active);
        $usage = $this->network->usage($links);
        $summary = $this->network->consoleSummary();
        $plans = NetworkPlan::ordered()->withCount(['passes as active_count' => fn ($q) => $q->where('status', 'active')])->get();

        $q = trim((string) $request->query('q'));
        $passes = NetworkPass::active()->with(['plan:id,name', 'issuedBy:id,name'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhere('holder', 'like', "%{$q}%")->orWhere('room_number', 'like', "%{$q}%")))
            ->orderBy('room_number')->orderBy('holder')->get();
        $ended = NetworkPass::where('status', '!=', 'active')->with(['plan:id,name', 'revokedBy:id,name'])->latest('updated_at')->limit(15)->get();
        $checkedIn = Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::CheckedIn)->orderBy('check_out_date')->get();
        $covered = NetworkPass::active()->whereNotNull('reservation_id')->pluck('reservation_id')->all();
        $inHouse = $checkedIn->whereNotIn('id', $covered)->values();

        $stats = [
            'connected' => (int) $passes->sum('devices_online'),
            'codes'     => $passes->count(),
            'today_gb'  => round(collect($usage)->sum('today_gb'), 1),
            'month_gb'  => round(collect($usage)->sum('month_gb'), 1),
        ];
        $gateway = $this->network->gateway();
        $pagetitle = 'Internet & Wi-Fi';

        return view('it.network.index', compact('pagetitle', 'links', 'history', 'usage', 'summary', 'plans', 'passes', 'ended', 'checkedIn', 'inHouse', 'stats', 'gateway', 'q'));
    }

    /** Lines' state as JSON: the page refreshes from it, and the IT console reads it. */
    public function summary()
    {
        return response()->json($this->network->consoleSummary());
    }

    public function refresh()
    {
        $n = $this->network->poll();

        return back()->with('success', "{$n} internet line(s) read.");
    }

    // ── Internet lines ───────────────────────────────────────────────

    public function storeLink(Request $request)
    {
        $link = NetworkLink::create($this->linkData($request));
        Audit::record('network.link_added', "Added internet line {$link->name} ({$link->provider_label})", $link);

        return back()->with('success', "{$link->name} added. Readings start within a minute.");
    }

    public function updateLink(Request $request, NetworkLink $link)
    {
        $link->update($this->linkData($request));

        return back()->with('success', "{$link->name} saved.");
    }

    public function destroyLink(NetworkLink $link)
    {
        Audit::record('network.link_removed', "Removed internet line {$link->name}", $link);
        $link->delete();

        return back()->with('success', 'Internet line removed with its history.');
    }

    private function linkData(Request $request): array
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'provider'     => ['required', Rule::in(array_keys(NetworkLink::PROVIDERS))],
            'role'         => ['required', Rule::in(array_keys(NetworkLink::ROLES))],
            'identifier'   => 'nullable|string|max:120',
            'address'      => 'nullable|string|max:120',
            'router_port'  => 'nullable|string|max:60',
            'weight'       => 'nullable|integer|min:1|max:10',
            'data_cap_gb'  => 'nullable|numeric|min:0|max:1000000',
            'billing_day'  => 'nullable|integer|min:1|max:28',
            'monthly_cost' => 'nullable|numeric|min:0',
            'location'     => 'nullable|string|max:120',
            'notes'        => 'nullable|string|max:1000',
        ]);

        return $data + ['weight' => $data['weight'] ?? 1, 'billing_day' => $data['billing_day'] ?? 1, 'is_active' => $request->boolean('is_active', true)];
    }

    // ── Speed plans ──────────────────────────────────────────────────

    public function storePlan(Request $request)
    {
        $plan = NetworkPlan::create($this->planData($request));
        $this->onlyOneDefault($plan);

        return back()->with('success', "Plan {$plan->name} added.");
    }

    public function updatePlan(Request $request, NetworkPlan $plan)
    {
        $plan->update($this->planData($request));
        $this->onlyOneDefault($plan);

        return back()->with('success', "Plan {$plan->name} saved. Codes already given keep their speed until changed.");
    }

    public function destroyPlan(NetworkPlan $plan)
    {
        if ($plan->passes()->exists()) {
            $plan->update(['is_active' => false, 'is_default' => false]);

            return back()->with('success', "{$plan->name} has been used, so it was switched off instead of deleted.");
        }
        $plan->delete();

        return back()->with('success', 'Plan deleted.');
    }

    private function planData(Request $request): array
    {
        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'down_mbps'   => 'required|numeric|min:0.5|max:10000',
            'up_mbps'     => 'required|numeric|min:0.25|max:10000',
            'devices'     => 'required|integer|min:1|max:50',
            'data_cap_gb' => 'nullable|numeric|min:0.1|max:4000',
            'price'       => 'nullable|numeric|min:0',
        ]);
        $data['data_cap_mb'] = isset($data['data_cap_gb']) ? (int) round($data['data_cap_gb'] * 1024) : null;
        unset($data['data_cap_gb']);

        return $data + ['price' => $data['price'] ?? 0, 'is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_active', true)];
    }

    private function onlyOneDefault(NetworkPlan $plan): void
    {
        if ($plan->is_default) {
            NetworkPlan::where('id', '!=', $plan->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }

    // ── Wi-Fi codes ──────────────────────────────────────────────────

    public function storePass(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => 'nullable|integer|exists:reservations,id',
            'kind'           => ['nullable', Rule::in(array_keys(NetworkPass::KINDS))],
            'plan_id'        => 'required|integer|exists:network_plans,id',
            'holder'         => 'nullable|required_without:reservation_id|string|max:120',
            'room_number'    => 'nullable|string|max:20',
            'devices'        => 'nullable|integer|min:1|max:50',
            'expires_at'     => 'nullable|date|after:now',
            'charge'         => 'nullable|boolean',
        ]);
        if (! empty($data['reservation_id'])) {
            $data['reservation'] = Reservation::with(['guest', 'rooms.room'])->findOrFail($data['reservation_id']);
            abort_unless($data['reservation']->status === ReservationStatus::CheckedIn, 422, 'Only guests who are checked in can be given a stay code.');
        }
        $data['charge'] = $request->boolean('charge', true);
        $pass = $this->network->issue($data, $request->user());

        return back()->with('success', "Wi-Fi code {$pass->code} is ready for " . ($pass->holder ?: 'the guest') . '.')->with('wifi_pass', $pass->id);
    }

    public function updatePass(Request $request, NetworkPass $pass)
    {
        $data = $request->validate([
            'plan_id'    => 'nullable|integer|exists:network_plans,id',
            'devices'    => 'nullable|integer|min:1|max:50',
            'expires_at' => 'nullable|date|after:now',
        ]);
        $this->network->change($pass, $data, $request->user());

        return back()->with('success', "Wi-Fi code {$pass->code} updated.");
    }

    public function revokePass(Request $request, NetworkPass $pass)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->network->revoke($pass, $data['reason'], $request->user());

        return back()->with('success', "Wi-Fi code {$pass->code} cut off. Its devices are disconnected.");
    }

    /** A slip to hand to the guest. */
    public function printPass(NetworkPass $pass)
    {
        $pass->load('plan');

        return view('it.network.slip', ['pass' => $pass, 'ssid' => config('hotel.network.guest_ssid', 'Hotel Guest'), 'pagetitle' => 'Wi-Fi code ' . $pass->code]);
    }
}
