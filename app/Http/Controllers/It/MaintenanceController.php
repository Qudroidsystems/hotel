<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\It\ItMaintenanceWindow;
use App\Models\It\ItMonitor;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Planned maintenance: announced to staff in advance; alerts for the affected monitors are held while it runs (spec §11.4). */
class MaintenanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View IT control room|Manage IT monitoring');
        $this->middleware('permission:Manage IT monitoring')->except('index');
    }

    public function index()
    {
        $upcoming = ItMaintenanceWindow::upcoming()->with('createdBy:id,name')->orderBy('starts_at')->get();
        $past = ItMaintenanceWindow::where(fn ($q) => $q->where('ends_at', '<=', now())->orWhereNotNull('cancelled_at'))
            ->with('createdBy:id,name')->latest('starts_at')->limit(30)->get();
        $monitors = ItMonitor::ordered()->get(['id', 'name', 'category']);
        $pagetitle = 'Planned Maintenance';

        return view('it.maintenance', compact('pagetitle', 'upcoming', 'past', 'monitors'));
    }

    public function store(Request $request)
    {
        $w = ItMaintenanceWindow::create($this->validated($request) + ['created_by' => $request->user()->id]);
        Audit::record('it.maintenance_planned', "Planned maintenance \"{$w->title}\" " . $w->starts_at->format('D j M g:i A') . ' – ' . $w->ends_at->format('g:i A'), $w);

        return back()->with('success', 'Maintenance scheduled. It shows on the dashboard for all staff until it ends.');
    }

    public function update(Request $request, ItMaintenanceWindow $window)
    {
        abort_if($window->cancelled_at || $window->ends_at->isPast(), 422, 'This maintenance has finished or was cancelled.');
        $window->update($this->validated($request, $window));

        return back()->with('success', 'Maintenance updated.');
    }

    public function cancel(ItMaintenanceWindow $window)
    {
        if (! $window->cancelled_at) {
            // Cancelling one that is running ends it now; a future one is called off.
            $window->forceFill($window->isRunning() ? ['ends_at' => now()] : ['cancelled_at' => now()])->save();
            Audit::record('it.maintenance_cancelled', "Ended / cancelled maintenance \"{$window->title}\"", $window);
        }

        return back()->with('success', 'Done. Alerts are back on for the affected monitors.');
    }

    private function validated(Request $request, ?ItMaintenanceWindow $w = null): array
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'description'   => 'nullable|string|max:2000',
            'affects'       => 'nullable|string|max:255',
            'starts_at'     => ['required', 'date', $w ? 'nullable' : 'after_or_equal:' . now()->subMinutes(5)->toDateTimeString()],
            'ends_at'       => 'required|date|after:starts_at',
            'monitor_ids'   => 'nullable|array',
            'monitor_ids.*' => 'integer|exists:it_monitors,id',
        ], ['starts_at.after_or_equal' => 'The start cannot be in the past.']);
        $data['monitor_ids'] = array_values(array_map('intval', $data['monitor_ids'] ?? []));

        return $data;
    }
}
