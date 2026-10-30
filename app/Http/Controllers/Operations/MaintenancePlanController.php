<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Room;
use App\Models\User;
use App\Services\Operations\MaintenancePlanService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Housekeeping & Repairs → Servicing Plans (preventive maintenance). */
class MaintenancePlanController extends Controller
{
    public function __construct(private MaintenancePlanService $plans)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['View maintenance', 'Manage maintenance']), 403);

        $plans = MaintenancePlan::with(['room:id,number', 'assignee:id,name'])
            ->withCount(['orders as open_count' => fn ($q) => $q->active()])
            ->orderByDesc('active')->orderBy('next_due_on')->get();
        $open = MaintenanceOrder::active()->whereNotNull('maintenance_plan_id')->get(['id', 'maintenance_plan_id', 'number', 'due_at', 'status'])->groupBy('maintenance_plan_id');
        $active = $plans->where('active', true);
        $stats = [
            'plans'   => $active->count(),
            'month'   => $active->filter(fn ($p) => $p->next_due_on->lte(today()->endOfMonth()))->count(),
            'overdue' => $active->filter(fn ($p) => $p->isOverdue())->count(),
            'open'    => $open->flatten()->count(),
            'done'    => MaintenanceOrder::whereNotNull('maintenance_plan_id')->where('status', MaintenanceOrder::COMPLETED)->where('completed_at', '>=', today()->startOfMonth())->count(),
        ];
        $rooms = Room::active()->orderBy('number')->get(['id', 'number']);
        $technicians = User::permission(['View maintenance', 'Manage maintenance'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']);
        $edit = $request->integer('edit') ? $plans->firstWhere('id', $request->integer('edit')) : null;
        $pagetitle = 'Servicing Plans';

        return view('maintenance.plans', compact('pagetitle', 'plans', 'open', 'stats', 'rooms', 'technicians', 'edit'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);

        return $this->attempt(fn () => $this->plans->save(null, $this->data($request), $request->user()), 'Servicing plan added. Its job opens on its own before each due date.');
    }

    public function update(Request $request, MaintenancePlan $plan)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);

        return $this->attempt(fn () => $this->plans->save($plan, $this->data($request), $request->user()), 'Servicing plan saved.', route('maintenance.plans.index'));
    }

    public function toggle(Request $request, MaintenancePlan $plan)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);
        $this->plans->toggle($plan, $request->user());

        return back()->with('success', $plan->active ? 'Plan resumed.' : 'Plan paused: no more jobs open until you resume it.');
    }

    public function now(Request $request, MaintenancePlan $plan)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);

        return $this->attempt(function () use ($plan, $request) {
            $n = $this->plans->open($plan, $request->user());
            session()->flash('success', $n === 1 ? 'The job is open in Maintenance Jobs.' : "{$n} jobs are open in Maintenance Jobs (one per room).");
        }, null);
    }

    public function destroy(Request $request, MaintenancePlan $plan)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);
        Audit::record('maintenance.plan_removed', "Removed servicing plan “{$plan->title}”", $plan);
        $plan->delete();   // past jobs stay, without their plan

        return redirect()->route('maintenance.plans.index')->with('success', 'Plan removed. Jobs already done or open are kept.');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:150',
            'category'    => ['required', Rule::in(array_keys(MaintenanceOrder::CATEGORIES))],
            'scope'       => ['required', Rule::in(['one', 'rooms'])],
            'room_id'     => ['nullable', Rule::exists('rooms', 'id')],
            'location'    => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'every'       => 'required|integer|min:1|max:120',
            'unit'        => ['required', Rule::in(array_keys(MaintenancePlan::UNITS))],
            'next_due_on' => 'required|date|after_or_equal:' . today()->subYear()->toDateString(),
            'lead_days'   => 'required|integer|min:0|max:60',
            'priority'    => ['required', Rule::in(array_keys(MaintenanceOrder::PRIORITIES))],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')],
        ]);
    }

    private function attempt(callable $action, ?string $success, ?string $to = null)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $r = $to ? redirect($to) : back();

        return $success ? $r->with('success', $success) : $r;
    }
}
