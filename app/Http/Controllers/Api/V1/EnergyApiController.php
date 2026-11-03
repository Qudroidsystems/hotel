<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Energy\EnergyAction;
use App\Models\Energy\EnergySource;
use App\Models\Room;
use App\Services\Energy\EnergyAdvisor;
use Illuminate\Http\Request;

/**
 * Energy Saver in the staff app (GET /energy, tick off, grid and generator changes), and for devices: a room
 * sensor or key-card switch, the changeover switch or a fuel gauge can report to POST /energy/device with the
 * hotel's device key (X-Device-Key, DEVICE_API_KEY in .env).
 */
class EnergyApiController extends Controller
{
    public function __construct(private EnergyAdvisor $energy)
    {
    }

    /** GET /energy */
    public function index(Request $request)
    {
        $this->can($request);
        if (! EnergySource::where('kind', 'grid')->exists()) {
            EnergySource::create(['name' => 'Grid (public power)', 'kind' => 'grid', 'capacity_kw' => 0, 'running' => true, 'running_since' => now()]);
        }
        $this->energy->sync(alert: false);
        $snap = $this->energy->snapshot();
        $p = $snap['power'];
        $sym = config('hotel.currency_symbol');
        $week = $this->energy->savings(today()->subDays(6));
        $hours = $this->energy->runHours(today()->startOfDay());

        return $this->ok([
            'at' => $snap['at']->format('g:i A'),
            'now_kw' => $snap['now_kw'], 'all_on_kw' => $snap['all_on_kw'], 'peak24_kw' => $p['peak24_kw'],
            'counts' => $snap['counts'],
            'power' => [
                'state' => $p['state'], 'grid_on' => $p['grid_on'], 'line' => $p['line'], 'urgent' => $p['urgent'],
                'litres_per_hour' => $p['litres_per_hour'], 'choice' => $p['choice']?->name,
                'plan_litres' => $p['plan_litres'], 'plan_saves_litres' => $p['plan_saves_litres'],
                'plan' => collect($p['plan'])->map(fn ($s) => ['from' => $s['from']->format('g A'), 'to' => $s['to']->format('g A'), 'source' => $s['source'], 'peak_kw' => $s['peak_kw'], 'hours' => $s['hours']])->values(),
                'sources' => $p['sources']->map(fn (EnergySource $g) => [
                    'id' => $g->id, 'name' => $g->name, 'kind' => $g->kind, 'capacity_kw' => $g->capacity_kw, 'running' => $g->running,
                    'since' => $g->running_since?->diffForHumans(short: true), 'fuel_litres' => $g->fuel_litres, 'tank_litres' => $g->tank_litres,
                    'hours_left' => $g->running ? $g->hoursLeft($p['now_kw']) : null, 'run_hours_today' => $hours[$g->id] ?? null,
                ])->values(),
            ],
            'forecast' => collect($snap['forecast'])->map(fn ($f) => ['label' => $f['label'], 'kw' => $f['kw']])->values(),
            'actions' => EnergyAction::where('status', EnergyAction::OPEN)->orderByRaw("CASE target WHEN 'source' THEN 0 WHEN 'room' THEN 1 ELSE 2 END")->orderByDesc('watts')->get()->map(fn ($a) => $this->actionJson($a))->values(),
            'switched_off' => EnergyAction::where('status', EnergyAction::DONE)->whereNull('closed_at')->latest('done_at')->limit(100)->get()->map(fn ($a) => $this->actionJson($a))->values(),
            'week' => $week + ['label' => number_format($week['kwh'], 1) . ' kWh · ' . $sym . number_format($week['grid_value']) . ' on grid, or ' . number_format($week['litres'], 1) . ' L of diesel'],
            'can_manage' => $request->user()->can('Manage energy saver'),
        ]);
    }

    public function done(Request $request, EnergyAction $action)
    {
        $this->can($request);
        $this->energy->done($action, $request->user(), $request->input('note'));

        return $this->ok($this->actionJson($action->fresh()), 'Done.');
    }

    public function skip(Request $request, EnergyAction $action)
    {
        $this->can($request);
        $this->energy->skip($action, $request->user(), $request->input('note'));

        return $this->ok($this->actionJson($action->fresh()), 'Left as it is.');
    }

    public function grid(Request $request)
    {
        $this->can($request);
        $on = (bool) $request->validate(['on' => 'required|boolean'])['on'];
        $this->energy->setGrid($on, $request->user());

        return $this->ok(null, $on ? 'Grid power is back on.' : 'Grid power is off.');
    }

    public function running(Request $request, EnergySource $source)
    {
        $this->can($request);
        $on = (bool) $request->validate(['on' => 'required|boolean'])['on'];
        $source->kind === 'grid' ? $this->energy->setGrid($on, $request->user()) : $this->energy->setRunning($source, $on, $request->user());
        $this->energy->sync();

        return $this->ok(null, $source->name . ($on ? ' is running.' : ' is off.'));
    }

    public function fuel(Request $request, EnergySource $source)
    {
        $this->can($request);
        $litres = (float) $request->validate(['litres' => 'required|numeric|min:0|max:100000'])['litres'];
        $check = $this->energy->fuel($source, $litres, $request->user());

        return $this->ok($check, "{$litres} L recorded." . ($check && $check['flagged'] ? " More diesel is gone than {$source->name} should have burned ({$check['used']} L against about {$check['expected']} L)." : ''));
    }

    /** POST /energy/sources/{source}/delivery {litres, price_per_litre, supplier_name, level_after} — diesel delivered. */
    public function delivery(Request $request, EnergySource $source)
    {
        abort_unless($request->user()?->can('Manage energy saver'), 403, 'Only the people who set up Energy Saver record deliveries.');
        $d = $request->validate(['litres' => 'required|numeric|min:1|max:200000', 'price_per_litre' => 'nullable|numeric|min:0|max:100000',
            'supplier_name' => 'nullable|string|max:120', 'invoice_ref' => 'nullable|string|max:60', 'level_after' => 'nullable|numeric|min:0|max:200000', 'expense' => 'nullable|boolean']);
        $d['expense'] = $request->boolean('expense') && $request->user()->can('Record expenses');
        try {
            $x = $this->energy->delivery($source, $d, $request->user());
        } catch (\App\Exceptions\BookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->ok(['id' => $x->id, 'expense' => $x->expense_id !== null], number_format($x->litres) . ' L recorded' . ($x->expense_id ? ' and added to Expenses.' : '.'));
    }

    /**
     * POST /energy/device — from sensors and switches:
     *   {"grid": true|false, "rooms": {"104": "empty", "105": "occupied"}, "running": {"Gen 2": true}, "fuel": {"Gen 2": 340}}
     * Generators may be named or given by id. Only what is sent changes.
     */
    public function device(Request $request)
    {
        $key = (string) config('services.device.key');
        abort_unless($key !== '' && hash_equals($key, (string) $request->header('X-Device-Key')), 401, 'Wrong device key.');
        $d = $request->validate(['grid' => 'nullable|boolean', 'rooms' => 'nullable|array|max:2000', 'rooms.*' => 'in:occupied,empty,1,0',
            'running' => 'nullable|array|max:50', 'fuel' => 'nullable|array|max:50', 'fuel.*' => 'numeric|min:0']);
        $find = fn ($k) => EnergySource::where(is_numeric($k) ? 'id' : 'name', $k)->first();
        $done = ['rooms' => 0, 'sources' => 0];
        foreach ($d['rooms'] ?? [] as $number => $state) {
            if ($room = Room::where('number', (string) $number)->first()) {
                $this->energy->presence($room, in_array($state, ['occupied', '1', 1, true], true));
                $done['rooms']++;
            }
        }
        foreach ($d['running'] ?? [] as $k => $on) {
            if (($g = $find($k)) && $g->kind !== 'grid') {
                $this->energy->setRunning($g, (bool) $on, null, 'device');
                $done['sources']++;
            }
        }
        foreach ($d['fuel'] ?? [] as $k => $litres) {
            if ($g = $find($k)) {
                $this->energy->fuel($g, (float) $litres, null, 'device');
                $done['sources']++;
            }
        }
        if (array_key_exists('grid', $d) && $d['grid'] !== null) {
            $this->energy->setGrid((bool) $d['grid'], null, 'device');   // re-plans and tells the control room
        }

        return $this->ok($done, 'Recorded.');
    }

    // ------------------------------------------------------------------

    private function actionJson(EnergyAction $a): array
    {
        return [
            'id' => $a->id, 'target' => $a->target, 'action' => $a->action, 'title' => $a->title, 'reason' => $a->reason,
            'kw' => round($a->watts / 1000, 2), 'saves' => $a->saves(), 'status' => $a->status,
            'until' => $a->until ? $a->until->format($a->until->isToday() ? 'g:i A' : 'D g:i A') : null,
            'done_by' => $a->doneBy?->name, 'done_at' => $a->done_at?->format('g:i A'),
        ];
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()?->canAny(['View energy saver', 'Manage energy saver']), 403, 'Energy Saver isn’t part of your work.');
    }

    private function ok(mixed $data, ?string $message = null)
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
