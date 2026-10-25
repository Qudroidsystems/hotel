<?php

namespace App\Services\NightAudit;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\BookingException;
use App\Models\CashShift;
use App\Models\CompanyLedgerEntry;
use App\Models\FolioCharge;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Reservations\ReservationService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Closing the business day. The night audit marks guests who never came as no-shows, bills tonight's room
 * for everyone in house, and saves the day's figures (occupancy, average rate, revenue, collections, guest
 * movements, what is owed) as the daily manager report. Once a day is closed, charges and payments dated on
 * it can only be voided by someone allowed to change closed days.
 */
class NightAuditService
{
    public function __construct(private FolioService $folios, private ReservationService $reservations)
    {
    }

    /** What to look at before closing: arrivals who haven't come, guests who should have left, open tills… */
    public function checks(Carbon $date): array
    {
        $d = $date->toDateString();

        return [
            'arrivals'  => Reservation::with(['guest', 'rooms.room'])
                ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
                ->where('check_in_date', '<=', $d . ' 23:59:59')->orderBy('check_in_date')->get(),
            'overstays' => Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::CheckedIn->value)
                ->where('check_out_date', '<=', $d . ' 23:59:59')->orderBy('check_out_date')->get(),
            'shifts'    => CashShift::with('user')->where('status', CashShift::OPEN)->get(),
            'pos'       => Schema::hasTable('pos_orders') ? PosOrder::where('status', PosOrder::ATTENTION)->count() : 0,
            'dirty'     => Room::active()->where('status', RoomStatus::Dirty->value)->count(),
        ];
    }

    /** Close a business day. $noShows: ids of the arrivals to mark as no-shows (the rest are left for tomorrow). */
    public function close(Carbon $date, array $noShows = [], ?string $notes = null, bool $automatic = false, ?User $by = null): NightAudit
    {
        $date = $date->copy()->startOfDay();
        if (NightAudit::isClosed($date)) {
            throw new BookingException($date->format('D j M Y') . ' is already closed.');
        }
        if (! $date->eq(NightAudit::businessDate())) {
            throw new BookingException('Close ' . NightAudit::businessDate()->format('D j M Y') . ' first: days are closed in order.');
        }
        if ($date->gt(today())) {
            throw new BookingException('That day hasn’t started yet.');
        }

        $audit = DB::transaction(function () use ($date, $noShows, $notes, $automatic, $by) {
            $actions = ['no_shows' => [], 'nights_posted' => 0];

            // 1. Guests who never arrived.
            $due = Reservation::whereIn('id', $noShows)->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
                ->where('check_in_date', '<=', $date->toDateString() . ' 23:59:59')->get();
            foreach ($due as $r) {
                try {
                    $this->reservations->markNoShow($r);
                    $actions['no_shows'][] = $r->reference;
                } catch (Throwable $e) {
                    Log::info("Night audit: {$r->reference} not marked no-show: " . $e->getMessage());
                }
            }

            // 2. Tonight's room for everyone in house (and any night missed before).
            foreach (Reservation::inHouse()->get() as $r) {
                $actions['nights_posted'] += $this->folios->postRoomNights($r, $date->copy()->addDay());
            }

            // 3. The day's figures, then lock the day.
            $audit = NightAudit::create([
                'business_date' => $date->toDateString(),
                'status'        => 'closed',
                'report'        => $this->report($date),
                'actions'       => $actions,
                'notes'         => $notes,
                'automatic'     => $automatic,
                'closed_by'     => $by?->id ?? auth()->id(),
                'closed_at'     => now(),
            ]);
            $rep = $audit->report;
            Audit::record('night_audit.closed', sprintf('Closed %s%s: occupancy %s%%, room revenue %s, %d no-show(s), %d room night(s) posted',
                $date->format('D j M Y'), $automatic ? ' (automatic)' : '', $rep['rooms']['occupancy'], $this->money($rep['rooms']['revenue']),
                count($actions['no_shows']), $actions['nights_posted']), $audit, by: $by);

            return $audit;
        });

        DB::afterCommit(fn () => $this->sendReport($audit));

        return $audit;
    }

    /** The day's figures. Also used to preview the report before closing. */
    public function report(Carbon $date): array
    {
        $d = $date->toDateString();
        $sold = FolioCharge::whereNull('voided_at')->where('type', ChargeType::Room->value)->whereDate('service_date', $d);
        $nights = (clone $sold)->count();
        $roomRevenue = round((float) (clone $sold)->sum('total'), 2);
        // Before the day is closed tonight's rooms aren't billed yet: count them at their booked rate.
        $posted = (clone $sold)->whereNotNull('reservation_room_id')->pluck('reservation_room_id')->flip();
        foreach (Reservation::inHouse()->with('rooms')->get() as $r) {
            foreach ($r->rooms as $line) {
                if (isset(($line->nightly_rates ?? [])[$d]) && ! isset($posted[$line->id])) {
                    $nights++;
                    $roomRevenue = round($roomRevenue + FolioCharge::computeTax((float) $line->nightly_rates[$d], true)['total'], 2);
                }
            }
        }
        $total = Room::active()->count();
        $outOfOrder = Room::active()->whereIn('status', [RoomStatus::Maintenance->value, RoomStatus::OutOfService->value])->count();
        $sellable = max(0, $total - $outOfOrder);

        // Other charges on guest bills (bar and restaurant sales charged to rooms are counted with the POS).
        $other = FolioCharge::whereNull('voided_at')->where('type', '!=', ChargeType::Room->value)->whereDate('service_date', $d)
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', PosOrder::class))
            ->selectRaw('type, sum(total) as amount')->groupBy('type')->pluck('amount', 'type')
            ->mapWithKeys(fn ($v, $k) => [ChargeType::tryFrom($k)?->label() ?? $k => round((float) $v, 2)])->all();

        $pos = ['sales' => 0.0, 'orders' => 0, 'by_outlet' => []];
        if (Schema::hasTable('pos_orders')) {
            $orders = PosOrder::with('outlet')->whereIn('status', [PosOrder::PAID, PosOrder::CHARGED])->whereDate('ordered_at', $d)->get();
            $pos = ['sales' => round((float) $orders->sum('total'), 2), 'orders' => $orders->count(),
                'by_outlet' => $orders->groupBy(fn ($o) => $o->outlet?->name ?? 'Till')->map(fn ($g) => round((float) $g->sum('total'), 2))->all()];
        }

        $events = ['count' => 0, 'revenue' => 0.0];
        if (Schema::hasTable('events')) {
            $ev = \App\Models\Events\Event::whereIn('status', ['confirmed', 'completed'])->whereDate('starts_at', $d)->get();
            $events = ['count' => $ev->count(), 'revenue' => round((float) $ev->sum('total'), 2)];
        }

        // Money in: guest bills, the tills (sales paid there), company payments.
        $collections = [];
        foreach (Payment::whereNull('voided_at')->whereDate('received_at', $d)->get() as $p) {
            $key = $p->method->label();
            $collections[$key] = round(($collections[$key] ?? 0) + ($p->kind === PaymentKind::Refund ? -1 : 1) * (float) $p->amount, 2);
        }
        if (Schema::hasTable('pos_orders')) {
            foreach (PosOrder::where('status', PosOrder::PAID)->whereDate('ordered_at', $d)->get() as $o) {
                $key = \App\Enums\PaymentMethod::tryFrom((string) $o->method)?->label() ?? 'Till';
                $collections[$key] = round(($collections[$key] ?? 0) + (float) $o->total, 2);
            }
        }
        $companyPaid = Schema::hasTable('company_ledger_entries')
            ? round(-1 * (float) CompanyLedgerEntry::whereNull('voided_at')->where('type', 'payment')->whereDate('entry_date', $d)->sum('amount'), 2) : 0.0;
        if ($companyPaid) {
            $collections['Company payments'] = $companyPaid;
        }
        // Moving a bill to a company is not money in.
        $billedToCompanies = $collections[\App\Enums\PaymentMethod::Company->label()] ?? 0.0;
        unset($collections[\App\Enums\PaymentMethod::Company->label()]);
        arsort($collections);

        $inHouse = Reservation::where('status', ReservationStatus::CheckedIn->value)
            ->where('check_in_date', '<=', $d . ' 23:59:59')->where('check_out_date', '>', $d . ' 23:59:59')->with('rooms')->get();
        $owingAfterStay = Reservation::where('status', ReservationStatus::CheckedOut->value)->whereHas('folio', fn ($f) => $f->where('status', 'open'))->with('folio')->get()
            ->sum(fn ($r) => max(0, $r->folio->balance()));

        $revenue = round($roomRevenue + array_sum($other) + $pos['sales'] + $events['revenue'], 2);

        return [
            'date'  => $d,
            'rooms' => [
                'total' => $total, 'out_of_order' => $outOfOrder, 'sellable' => $sellable, 'sold' => $nights,
                'occupancy' => $sellable ? round($nights / $sellable * 100, 1) : 0,
                'revenue' => $roomRevenue,
                'adr' => $nights ? round($roomRevenue / $nights, 2) : 0,
                'revpar' => $sellable ? round($roomRevenue / $sellable, 2) : 0,
            ],
            'revenue' => [
                'rooms' => $roomRevenue, 'other' => $other, 'pos' => $pos, 'events' => $events, 'total' => $revenue,
            ],
            'collections' => ['by_method' => $collections, 'total' => round(array_sum($collections), 2), 'billed_to_companies' => round((float) $billedToCompanies, 2)],
            'guests' => [
                'arrivals'      => Reservation::whereDate('checked_in_at', $d)->count(),
                'departures'    => Reservation::whereDate('checked_out_at', $d)->count(),
                'no_shows'      => Reservation::where('status', ReservationStatus::NoShow->value)->whereDate('check_in_date', $d)->count(),
                'cancellations' => Reservation::whereDate('cancelled_at', $d)->count(),
                'new_bookings'  => Reservation::whereDate('created_at', $d)->count(),
                'new_value'     => round((float) Reservation::whereDate('created_at', $d)->sum('total_amount'), 2),
                'online'        => Reservation::whereDate('created_at', $d)->where('source', \App\Enums\BookingSource::Website->value)->count(),
                'in_house_rooms' => $inHouse->sum(fn ($r) => $r->rooms->count()),
                'in_house_people' => (int) $inHouse->sum(fn ($r) => (int) $r->adults + (int) $r->children),
            ],
            'owed' => [
                'in_house'    => round($inHouse->sum(fn ($r) => max(0, $this->folios->forReservation($r)->balance())), 2),
                'after_stay'  => round((float) $owingAfterStay, 2),
                'companies'   => Schema::hasTable('company_ledger_entries') ? round((float) CompanyLedgerEntry::whereNull('voided_at')->sum('amount'), 2) : 0.0,
            ],
        ];
    }

    /** Email the closed day's report to the people listed in Hotel Settings → Night Audit. */
    private function sendReport(NightAudit $audit): void
    {
        $to = array_filter((array) config('hotel.night_audit.report_emails', []));
        if (! $to) {
            return;
        }
        try {
            Mail::send('night-audit.email', ['audit' => $audit, 'r' => $audit->report], function ($m) use ($to, $audit) {
                $m->to($to)->subject(config('hotel.name') . ' — daily report for ' . $audit->business_date->format('D j M Y'));
            });
        } catch (Throwable $e) {
            Log::warning('Night audit report not emailed: ' . $e->getMessage());
        }
    }

    public function money(float $v): string
    {
        return config('hotel.currency_symbol') . number_format($v, 2);
    }
}
