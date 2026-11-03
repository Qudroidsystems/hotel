<?php

namespace App\Http\Controllers\Energy;

use App\Http\Controllers\Controller;
use App\Models\Energy\EnergyAction;
use App\Models\Energy\EnergyEvent;
use App\Models\Energy\EnergyFuelDelivery;
use App\Models\Energy\EnergySource;
use App\Models\Energy\EnergyZone;
use App\Models\Events\Hall;
use App\Models\Floor;
use App\Models\Pos\PosOutlet;
use App\Services\Energy\EnergyAdvisor;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The control room's Energy Saver: what to switch off and on, the power plan, and setting up places and generators. */
class EnergyController extends Controller
{
    public function __construct(private EnergyAdvisor $energy)
    {
        $this->middleware('permission:View energy saver|Manage energy saver');
        $this->middleware('permission:Manage energy saver')->only(['setup', 'saveZone', 'deleteZone', 'saveSource', 'deleteSource', 'storeDelivery']);
    }

    public function index(Request $request)
    {
        $this->ensureGrid();
        $this->energy->sync(alert: false);
        $snap = $this->energy->snapshot();
        $actions = EnergyAction::where('status', EnergyAction::OPEN)->orderByRaw("CASE target WHEN 'source' THEN 0 WHEN 'room' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE action WHEN 'precool' THEN 0 WHEN 'on' THEN 0 ELSE 1 END")->orderByDesc('watts')->get();
        $data = [
            'pagetitle' => 'Energy Saver', 'snap' => $snap, 'actions' => $actions,
            'off' => EnergyAction::where('status', EnergyAction::DONE)->whereNull('closed_at')->with('doneBy:id,name')->latest('done_at')->get(),
            'today' => $this->energy->savings(today()), 'week' => $this->energy->savings(today()->subDays(6)),
            'runHours' => $this->energy->runHours(today()->startOfDay()),
            'log' => EnergyEvent::with('source:id,name', 'user:id,name')->latest('created_at')->limit(12)->get(),
            'setUp' => EnergySource::where('kind', '!=', 'grid')->exists() || EnergyZone::exists(),
        ];
        if ($request->boolean('partial')) {
            return view('energy.partials.board', $data);
        }

        return view('energy.index', $data);
    }

    public function sync()
    {
        $r = $this->energy->sync();

        return back()->with('success', $r['new'] ? "{$r['new']} new suggestion(s)." : 'Up to date: nothing new to switch.');
    }

    // ── Ticking things off ─────────────────────────────────────────────

    public function done(Request $request, EnergyAction $action)
    {
        $this->energy->done($action, $request->user(), $request->input('note'));

        return $this->answer($request, 'Done: ' . $action->title);
    }

    public function skip(Request $request, EnergyAction $action)
    {
        $this->energy->skip($action, $request->user(), $request->input('note'));

        return $this->answer($request, 'Left as it is: ' . $action->title);
    }

    /** "Done" for several at once (all the empty rooms on a floor …). */
    public function doneMany(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
        $n = 0;
        foreach (EnergyAction::whereIn('id', $ids)->where('status', EnergyAction::OPEN)->get() as $a) {
            $this->energy->done($a, $request->user());
            $n++;
        }

        return $this->answer($request, "{$n} marked done.");
    }

    // ── Power ──────────────────────────────────────────────────────────

    public function grid(Request $request)
    {
        $on = $request->validate(['on' => 'required|boolean'])['on'];
        $this->energy->setGrid((bool) $on, $request->user());
        Audit::record('energy.grid', 'Grid power ' . ($on ? 'back on' : 'off'));

        return $this->answer($request, $on ? 'Grid power is back on.' : 'Grid power is off: see which generator to run.');
    }

    public function running(Request $request, EnergySource $source)
    {
        $on = (bool) $request->validate(['on' => 'required|boolean'])['on'];
        $source->kind === 'grid' ? $this->energy->setGrid($on, $request->user()) : $this->energy->setRunning($source, $on, $request->user());
        $this->energy->sync();

        return $this->answer($request, $source->name . ($on ? ' is running.' : ' is off.'));
    }

    public function fuel(Request $request, EnergySource $source)
    {
        $litres = (float) $request->validate(['litres' => 'required|numeric|min:0|max:100000'])['litres'];
        $check = $this->energy->fuel($source, $litres, $request->user());

        return $this->answer($request, "{$source->name}: {$litres} L recorded." . ($check && $check['flagged'] ? " More diesel is gone than the generator should have burned ({$check['used']} L against about {$check['expected']} L): check the tank." : ''));
    }

    // ── Fuel & energy report, diesel deliveries ───────────────────────────

    /** GET /energy/report?from=&to=  (default: this month so far) — ?csv=1 for the days as a spreadsheet. */
    public function report(Request $request, \App\Services\Energy\EnergyReportService $reports)
    {
        $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->query('from')) : today()->startOfMonth();
        $to = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->query('to')) : today();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $from = $from->max($to->copy()->subDays(366));
        $r = $reports->build($from, $to);
        if ($request->boolean('csv')) {
            return response($reports->csv($r), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="energy-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv"']);
        }

        return view('energy.report', ['pagetitle' => 'Fuel & energy report', 'r' => $r]);
    }

    /** GET /energy/fuel — diesel deliveries, and the form to record one. */
    public function fuelLog(Request $request)
    {
        return view('energy.fuel', [
            'pagetitle' => 'Diesel deliveries',
            'deliveries' => EnergyFuelDelivery::with('source:id,name', 'receiver:id,name', 'expense:id,number,status')->latest('delivered_at')->paginate(30),
            'tanks' => EnergySource::where('kind', 'generator')->where('is_active', true)->orderBy('capacity_kw')->get(),
            'suppliers' => \Illuminate\Support\Facades\Schema::hasTable('suppliers') ? \App\Models\Inventory\Supplier::orderBy('name')->get(['id', 'name']) : collect(),
            'price' => $this->energy->settings()['diesel_price'],
            'canExpense' => $request->user()->can('Record expenses'),
        ]);
    }

    public function storeDelivery(Request $request)
    {
        $d = $request->validate([
            'source_id' => 'required|integer|exists:energy_sources,id', 'litres' => 'required|numeric|min:1|max:200000',
            'price_per_litre' => 'nullable|numeric|min:0|max:100000', 'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:120', 'invoice_ref' => 'nullable|string|max:60', 'level_after' => 'nullable|numeric|min:0|max:200000',
            'delivered_at' => 'nullable|date|before_or_equal:now', 'notes' => 'nullable|string|max:255', 'expense' => 'nullable|boolean',
        ]);
        $d['expense'] = $request->boolean('expense') && $request->user()->can('Record expenses');
        try {
            $x = $this->energy->delivery(EnergySource::findOrFail($d['source_id']), $d, $request->user());
        } catch (\App\Exceptions\BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', number_format($x->litres) . ' L recorded' . ($x->expense_id ? ' and added to Expenses (' . $x->expense?->number . ').' : '.'));
    }

    // ── Setting up ─────────────────────────────────────────────────────

    public function setup()
    {
        $this->ensureGrid();

        return view('energy.setup', [
            'pagetitle' => 'Energy Saver — places & generators',
            'zones' => EnergyZone::with('floor', 'hall', 'outlet')->orderBy('sort_order')->orderBy('name')->get(),
            'sources' => EnergySource::orderByRaw("kind = 'grid' DESC")->orderBy('capacity_kw')->get(),
            'floors' => Floor::with('building')->orderBy('level')->get(),
            'halls' => Hall::orderBy('name')->get(['id', 'name']),
            'outlets' => PosOutlet::orderBy('name')->get(['id', 'name', 'guest_hours']),
            'settings' => $this->energy->settings(),
            // Board switches a generator or the grid can be read from (a hub reports them).
            'switches' => \App\Models\Facility\FacilityDevice::active()->whereNull('room_id')->whereIn('kind', ['generator', 'inverter', 'solar', 'other'])->orderBy('area')->orderBy('name')->get(['id', 'name', 'area', 'kind']),
            'boardCount' => \App\Models\Facility\FacilityDevice::active()->whereNotNull('room_id')->count(),
        ]);
    }

    public function saveZone(Request $request, ?EnergyZone $zone = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(array_keys(EnergyZone::KINDS))],
            'floor_id' => 'nullable|integer|exists:floors,id', 'hall_id' => 'nullable|integer|exists:halls,id', 'outlet_id' => 'nullable|integer|exists:pos_outlets,id',
            'watts' => 'required|integer|min:0|max:2000000', 'low_watts' => 'nullable|integer|min:0|max:2000000',
            'opens' => 'nullable|date_format:H:i', 'closes' => 'nullable|date_format:H:i|required_with:opens',
            'always_on' => 'boolean', 'is_active' => 'boolean', 'notes' => 'nullable|string|max:255', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $d['low_watts'] = min((int) ($d['low_watts'] ?? 0), (int) $d['watts']);
        $d['always_on'] = $request->boolean('always_on');
        $d['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $zone ? $zone->update($d) : $zone = EnergyZone::create($d);

        return back()->with('success', "Saved {$zone->name}.");
    }

    public function deleteZone(EnergyZone $zone)
    {
        $zone->delete();

        return back()->with('success', "Removed {$zone->name}.");
    }

    public function saveSource(Request $request, ?EnergySource $source = null)
    {
        if ($source?->kind === 'grid') {
            // The grid: only its switch on the equipment board (a changeover-switch sensor reports it).
            $source->update($request->validate(['device_id' => 'nullable|integer|exists:facility_devices,id']));

            return back()->with('success', 'Saved the grid.');
        }
        $d = $request->validate([
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(['generator', 'solar', 'inverter'])],
            'capacity_kw' => 'required|integer|min:1|max:100000', 'litres_per_hour' => 'nullable|numeric|min:0|max:10000',
            'tank_litres' => 'nullable|integer|min:0|max:1000000', 'is_active' => 'boolean',
            'device_id' => 'nullable|integer|exists:facility_devices,id',
        ]);
        $d['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $source ? $source->update($d) : $source = EnergySource::create($d);

        return back()->with('success', "Saved {$source->name}.");
    }

    public function deleteSource(EnergySource $source)
    {
        abort_if($source->kind === 'grid', 422, 'The grid stays: switch it off instead.');
        $source->delete();

        return back()->with('success', "Removed {$source->name}.");
    }

    // ------------------------------------------------------------------

    private function ensureGrid(): void
    {
        if (! EnergySource::where('kind', 'grid')->exists()) {
            EnergySource::create(['name' => 'Grid (public power)', 'kind' => 'grid', 'capacity_kw' => 0, 'running' => true, 'running_since' => now()]);
        }
    }

    private function answer(Request $request, string $message)
    {
        return $request->expectsJson() ? response()->json(['success' => true, 'message' => $message]) : back()->with('success', $message);
    }
}
