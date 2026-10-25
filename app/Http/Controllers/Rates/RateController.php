<?php

namespace App\Http\Controllers\Rates;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Rates\RateDay;
use App\Models\Rates\RatePlan;
use App\Models\Rates\RateSeason;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Rates\RateService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rates & seasons: rate plans (how rooms are sold), seasons (when prices move) and the rate calendar
 * (a room type's price, online closing and minimum stay on given nights). Every change is audited.
 */
class RateController extends Controller
{
    public function __construct(private RateService $rates)
    {
        $this->middleware('permission:Manage rates');
    }

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['plans', 'seasons', 'calendar'], true) ? $request->query('tab') : 'plans';
        $types = RoomType::where('is_active', true)->orderBy('base_rate')->get();
        $pagetitle = 'Rates & Seasons';
        $data = compact('pagetitle', 'tab', 'types');

        if ($tab === 'plans') {
            $data['plans'] = RatePlan::with('company')->ordered()->get();
            $data['used'] = Reservation::whereNotNull('rate_plan_id')->selectRaw('rate_plan_id, count(*) as n')->groupBy('rate_plan_id')->pluck('n', 'rate_plan_id');
        } elseif ($tab === 'seasons') {
            $data['seasons'] = RateSeason::orderByDesc('is_active')->orderBy('starts_on')->get();
        } else {
            $month = $this->month($request->query('month'));
            $days = collect(range(0, $month->daysInMonth - 1))->map(fn ($i) => $month->copy()->addDays($i));
            $end = $month->copy()->endOfMonth()->addDay();
            $grid = [];
            foreach ($types as $type) {
                $quote = $this->rates->quote($type, $month, $end);
                $entries = RateDay::where('room_type_id', $type->id)->where('date', '>=', $month->toDateString())->where('date', '<', $end->toDateString())->get()
                    ->keyBy(fn ($d) => $d->date->toDateString());
                foreach ($days as $d) {
                    $k = $d->toDateString();
                    $grid[$type->id][$k] = ['price' => $quote[$k] ?? 0, 'entry' => $entries[$k] ?? null, 'season' => $this->rates->seasonFor($type->id, $k)];
                }
            }
            $data += ['month' => $month, 'days' => $days, 'grid' => $grid];
        }

        return view('rates.index', $data);
    }

    // ── Plans ─────────────────────────────────────────────────────────

    public function createPlan()
    {
        return $this->planForm(new RatePlan(['front_desk' => true, 'is_active' => true]));
    }

    public function editPlan(RatePlan $plan)
    {
        return $this->planForm($plan);
    }

    public function storePlan(Request $request)
    {
        $plan = RatePlan::create($this->planData($request) + ['created_by' => auth()->id()]);
        Audit::record('rates.plan_added', "Added rate plan {$plan->name} ({$plan->summary})", $plan);

        return redirect()->route('rates.index', ['tab' => 'plans'])->with('success', "{$plan->name} added.");
    }

    public function updatePlan(Request $request, RatePlan $plan)
    {
        $plan->fill($this->planData($request, $plan));
        [$old, $new] = Audit::diff($plan);
        $plan->save();
        if ($new) {
            Audit::record('rates.plan_changed', "Changed rate plan {$plan->name}", $plan, $old, $new);
        }

        return redirect()->route('rates.index', ['tab' => 'plans'])->with('success', "{$plan->name} saved. Bookings already made keep their prices.");
    }

    public function destroyPlan(RatePlan $plan)
    {
        if (Reservation::where('rate_plan_id', $plan->id)->exists()) {
            $plan->update(['is_active' => false]);
            Audit::record('rates.plan_changed', "Switched off rate plan {$plan->name} (it has bookings, so it is kept)", $plan);

            return back()->with('success', "{$plan->name} has bookings, so it was switched off instead of deleted.");
        }
        Audit::record('rates.plan_deleted', "Deleted rate plan {$plan->name}", $plan);
        $plan->delete();

        return back()->with('success', "{$plan->name} deleted.");
    }

    private function planForm(RatePlan $plan)
    {
        return view('rates.plan-form', [
            'pagetitle' => $plan->exists ? 'Edit ' . $plan->name : 'New Rate Plan',
            'plan'      => $plan,
            'types'     => RoomType::where('is_active', true)->orderBy('base_rate')->get(),
            'companies' => Company::active()->orderBy('name')->get(),
        ]);
    }

    private function planData(Request $request, ?RatePlan $plan = null): array
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'code'             => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('rate_plans', 'code')->ignore($plan?->id)],
            'description'      => 'nullable|string|max:500',
            'inclusions'       => 'nullable|string|max:255',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'nightly_extra'    => 'nullable|numeric|min:-10000000|max:10000000',
            'fixed_prices'     => 'nullable|array',
            'fixed_prices.*'   => 'nullable|numeric|min:0|max:100000000',
            'min_nights'       => 'nullable|integer|min:1|max:365',
            'max_nights'       => 'nullable|integer|min:1|max:365|gte:min_nights',
            'min_advance_days' => 'nullable|integer|min:1|max:730',
            'valid_from'       => 'nullable|date',
            'valid_to'         => 'nullable|date|after_or_equal:valid_from',
            'arrival_days'     => 'nullable|array',
            'arrival_days.*'   => 'integer|between:0,6',
            'room_type_ids'    => 'nullable|array',
            'room_type_ids.*'  => 'integer|exists:room_types,id',
            'promo_code'       => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('rate_plans', 'promo_code')->ignore($plan?->id)],
            'company_id'       => 'nullable|integer|exists:companies,id',
            'sort_order'       => 'nullable|integer|min:0|max:999',
        ], ['max_nights.gte' => 'The longest stay can’t be shorter than the shortest.']);

        $fixed = collect($data['fixed_prices'] ?? [])->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => round((float) $v, 2));
        $online = $request->boolean('online');

        return [
            'name'             => $data['name'],
            'code'             => strtoupper($data['code']),
            'description'      => $data['description'] ?? null,
            'inclusions'       => $data['inclusions'] ?? null,
            'discount_percent' => (float) ($data['discount_percent'] ?? 0),
            'nightly_extra'    => (float) ($data['nightly_extra'] ?? 0),
            'fixed_prices'     => $fixed->isNotEmpty() ? $fixed->all() : null,
            'min_nights'       => $data['min_nights'] ?? null,
            'max_nights'       => $data['max_nights'] ?? null,
            'min_advance_days' => $data['min_advance_days'] ?? null,
            'valid_from'       => $data['valid_from'] ?? null,
            'valid_to'         => $data['valid_to'] ?? null,
            'arrival_days'     => ! empty($data['arrival_days']) && count($data['arrival_days']) < 7 ? array_map('intval', $data['arrival_days']) : null,
            'room_type_ids'    => ! empty($data['room_type_ids']) ? array_map('intval', $data['room_type_ids']) : null,
            'promo_code'       => filled($data['promo_code'] ?? null) ? strtoupper($data['promo_code']) : null,
            // A company's negotiated rate is never sold online.
            'company_id'       => $data['company_id'] ?? null,
            'online'           => empty($data['company_id']) && $online,
            'front_desk'       => $request->boolean('front_desk'),
            'non_refundable'   => $request->boolean('non_refundable'),
            'is_active'        => $request->boolean('is_active', true),
            'sort_order'       => (int) ($data['sort_order'] ?? 0),
        ];
    }

    // ── Seasons ───────────────────────────────────────────────────────

    public function storeSeason(Request $request)
    {
        $season = RateSeason::create($this->seasonData($request));
        Audit::record('rates.season_added', "Added season {$season->name}: " . $season->starts_on->format('j M Y') . ' – ' . $season->ends_on->format('j M Y') . " ({$season->change_label})", $season);

        return redirect()->route('rates.index', ['tab' => 'seasons'])->with('success', "{$season->name} added.");
    }

    public function updateSeason(Request $request, RateSeason $season)
    {
        $season->fill($this->seasonData($request) + ['is_active' => $request->boolean('is_active')]);
        [$old, $new] = Audit::diff($season);
        $season->save();
        if ($new) {
            Audit::record('rates.season_changed', "Changed season {$season->name}", $season, $old, $new);
        }

        return redirect()->route('rates.index', ['tab' => 'seasons'])->with('success', "{$season->name} saved.");
    }

    public function destroySeason(RateSeason $season)
    {
        Audit::record('rates.season_deleted', "Deleted season {$season->name}", $season);
        $season->delete();

        return back()->with('success', "{$season->name} deleted. Bookings already made keep their prices.");
    }

    private function seasonData(Request $request): array
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'starts_on'       => 'required|date',
            'ends_on'         => 'required|date|after_or_equal:starts_on',
            'change_percent'  => 'nullable|numeric|min:-90|max:500',
            'change_amount'   => 'nullable|numeric|min:-10000000|max:10000000',
            'room_type_ids'   => 'nullable|array',
            'room_type_ids.*' => 'integer|exists:room_types,id',
            'min_nights'      => 'nullable|integer|min:1|max:60',
            'priority'        => 'nullable|integer|min:-100|max:100',
            'color'           => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        return [
            'name'           => $data['name'],
            'starts_on'      => $data['starts_on'],
            'ends_on'        => $data['ends_on'],
            'change_percent' => (float) ($data['change_percent'] ?? 0),
            'change_amount'  => (float) ($data['change_amount'] ?? 0),
            'room_type_ids'  => ! empty($data['room_type_ids']) ? array_map('intval', $data['room_type_ids']) : null,
            'min_nights'     => $data['min_nights'] ?? null,
            'priority'       => (int) ($data['priority'] ?? 0),
            'color'          => $data['color'] ?? '#b7832f',
        ];
    }

    // ── Rate calendar ─────────────────────────────────────────────────

    /** Change many nights at once: a price, closing online, a minimum stay — or clear them. */
    public function updateCalendar(Request $request)
    {
        $data = $request->validate([
            'from'            => 'required|date',
            'to'              => 'required|date|after_or_equal:from|before_or_equal:' . Carbon::parse($request->input('from', today()))->addDays(366)->toDateString(),
            'room_type_ids'   => 'required|array|min:1',
            'room_type_ids.*' => 'integer|exists:room_types,id',
            'weekdays'        => 'nullable|array',
            'weekdays.*'      => 'integer|between:0,6',
            'action'          => 'required|in:price,clear_price,close,open,min_nights,clear_min,clear_all',
            'price'           => 'required_if:action,price|nullable|numeric|min:0|max:100000000',
            'min_nights'      => 'required_if:action,min_nights|nullable|integer|min:1|max:60',
            'note'            => 'nullable|string|max:150',
            'month'           => 'nullable|string',
        ], ['to.before_or_equal' => 'Change at most a year of nights at once.']);

        $weekdays = ! empty($data['weekdays']) ? array_map('intval', $data['weekdays']) : null;
        $changed = 0;
        DB::transaction(function () use ($data, $weekdays, &$changed) {
            for ($d = Carbon::parse($data['from']); $d->lte(Carbon::parse($data['to'])); $d->addDay()) {
                if ($weekdays !== null && ! in_array($d->dayOfWeek, $weekdays, true)) {
                    continue;
                }
                foreach ($data['room_type_ids'] as $typeId) {
                    $day = RateDay::firstOrNew(['room_type_id' => $typeId, 'date' => $d->toDateString()]);
                    match ($data['action']) {
                        'price'       => $day->price = $data['price'],
                        'clear_price' => $day->price = null,
                        'close'       => $day->closed = true,
                        'open'        => $day->closed = false,
                        'min_nights'  => $day->min_nights = $data['min_nights'],
                        'clear_min'   => $day->min_nights = null,
                        'clear_all'   => [$day->price, $day->closed, $day->min_nights] = [null, false, null],
                    };
                    if (filled($data['note'] ?? null)) {
                        $day->note = $data['note'];
                    }
                    if ($day->price === null && ! $day->closed && $day->min_nights === null) {
                        if ($day->exists) {
                            $day->delete();
                            $changed++;
                        }

                        continue;
                    }
                    $day->updated_by = auth()->id();
                    if ($day->isDirty()) {
                        $day->save();
                        $changed++;
                    }
                }
            }
        });

        $what = [
            'price' => 'set the price to ' . config('hotel.currency_symbol') . number_format((float) ($data['price'] ?? 0), 2),
            'clear_price' => 'went back to the normal price', 'close' => 'closed online', 'open' => 'opened online',
            'min_nights' => 'set a minimum stay of ' . ($data['min_nights'] ?? '') . ' nights', 'clear_min' => 'removed the minimum stay', 'clear_all' => 'cleared all changes',
        ][$data['action']];
        $types = RoomType::whereIn('id', $data['room_type_ids'])->pluck('name')->implode(', ');
        if ($changed) {
            Audit::record('rates.calendar_changed', "Rate calendar: {$what} for {$types}, " . Carbon::parse($data['from'])->format('j M') . ' – ' . Carbon::parse($data['to'])->format('j M Y')
                . ($weekdays ? ' (' . collect(RatePlan::DAYS)->only($weekdays)->implode(', ') . ' only)' : ''), null, [], ['nights' => $changed]);
        }

        return redirect()->route('rates.index', ['tab' => 'calendar', 'month' => $data['month'] ?? Carbon::parse($data['from'])->format('Y-m')])
            ->with('success', $changed ? ucfirst($what) . " on {$changed} room-night(s)." : 'Nothing needed changing.');
    }

    /** Quote a stay for the price checker on the plans page. */
    public function check(Request $request)
    {
        $data = $request->validate(['type' => 'required|exists:room_types,id', 'in' => 'required|date', 'out' => 'required|date|after:in', 'plan' => 'nullable|exists:rate_plans,id']);
        $type = RoomType::find($data['type']);
        $plan = ! empty($data['plan']) ? RatePlan::find($data['plan']) : null;
        $in = Carbon::parse($data['in'])->startOfDay();
        $out = Carbon::parse($data['out'])->startOfDay();
        $quote = $this->rates->quote($type, $in, $out, null, $plan);

        return response()->json([
            'nights'   => collect($quote)->map(fn ($p, $d) => ['date' => Carbon::parse($d)->format('D j M'), 'price' => $p, 'season' => $this->rates->seasonFor($type->id, $d)?->name])->values(),
            'total'    => round(array_sum($quote), 2),
            'problems' => $plan ? $this->rates->problems($plan, $type, $in, $out, RateService::FRONT_DESK, null, $plan->company_id) : [],
            'online'   => $this->rates->restriction($type, $in, $out),
        ]);
    }

    private function month(?string $m): Carbon
    {
        try {
            return $m ? Carbon::createFromFormat('Y-m', $m)->startOfMonth()->startOfDay() : today()->startOfMonth();
        } catch (\Throwable) {
            return today()->startOfMonth();
        }
    }
}
