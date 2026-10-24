<?php

namespace App\Services\Billing;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cashier shifts (spec §7.3): open with a float, record paid-ins/outs, close
 * with a cash count. A shift covers whatever its cashier took between opening
 * and closing — guest bill payments and refunds they handled and bar sales
 * paid at their till — so the till and front desk screens don't change.
 */
class CashShiftService
{
    public function current(User $user): ?CashShift
    {
        return CashShift::open()->where('user_id', $user->id)->latest('id')->first();
    }

    public function open(User $user, string $station, ?int $outletId, float $float): CashShift
    {
        if ($float < 0) {
            throw new BookingException('The opening float can’t be negative.');
        }

        return DB::transaction(function () use ($user, $station, $outletId, $float) {
            // Lock the user's row so two taps on "Open" can't open two drawers.
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($this->current($user)) {
                throw new BookingException('You already have an open shift. Close it before opening another.');
            }

            $shift = CashShift::create([
                'user_id'       => $user->id,
                'station'       => $station,
                'outlet_id'     => $outletId,
                'opening_float' => Money::round($float),
                'opened_at'     => now(),
            ]);
            $shift->forceFill(['number' => 'SH-' . str_pad((string) $shift->id, 6, '0', STR_PAD_LEFT)])->save();

            Audit::record('billing.shift_opened', "{$user->name} opened shift {$shift->number} at {$station} with a float of " . $this->money($float),
                $shift, new: ['opening_float' => $shift->opening_float, 'station' => $station]);

            return $shift;
        });
    }

    public function addMovement(CashShift $shift, string $type, float $amount, string $reason): CashShiftMovement
    {
        if (! $shift->isOpen()) {
            throw new BookingException('This shift is closed.');
        }
        if (! isset(CashShiftMovement::TYPES[$type])) {
            throw new BookingException('Unknown kind of cash movement.');
        }
        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if (CashShiftMovement::TYPES[$type][1] < 0 && $amount > $this->totals($shift)['expected_cash'] + 0.001) {
            throw new BookingException('That is more cash than the drawer should hold (' . $this->money($this->totals($shift)['expected_cash']) . ').');
        }

        $movement = $shift->movements()->create(['type' => $type, 'amount' => $amount, 'reason' => $reason, 'user_id' => auth()->id()]);
        Audit::record('billing.shift_' . $type, "{$movement->label()} of " . $this->money($amount) . " on shift {$shift->number}", $shift,
            new: ['type' => $type, 'amount' => $amount], reason: $reason, flagged: $amount >= (float) config('hotel.audit.flag_amount', 50000));

        return $movement;
    }

    /**
     * What the drawer should hold now (or held at close, once frozen).
     * methods: [value => [label, in, out, pos, net, count]]
     */
    public function totals(CashShift $shift): array
    {
        if (! $shift->isOpen() && $shift->summary) {
            return $shift->summary;
        }

        $window = [$shift->opened_at, $shift->closed_at ?? now()];
        $payments = Payment::valid()->where('received_by', $shift->user_id)->whereBetween('received_at', $window)->get(['kind', 'method', 'amount']);
        $pos = PosOrder::takings()->where('cashier_id', $shift->user_id)->whereBetween('ordered_at', $window)->get(['method', 'total']);
        $roomCharges = PosOrder::where('status', PosOrder::CHARGED)->where('cashier_id', $shift->user_id)->whereBetween('ordered_at', $window)->get(['total']);
        $voids = PosOrder::where('status', PosOrder::VOIDED)->where('cashier_id', $shift->user_id)->whereBetween('ordered_at', $window)->count();

        $methods = [];
        foreach (PaymentMethod::cases() as $m) {
            $mine = $payments->where('method', $m);
            $row = [
                'label' => $m->label(),
                'in'    => (float) $mine->where('kind', '!=', PaymentKind::Refund)->sum('amount'),
                'out'   => (float) $mine->where('kind', PaymentKind::Refund)->sum('amount'),
                'pos'   => (float) $pos->where('method', $m->value)->sum('total'),
                'count' => $mine->count() + $pos->where('method', $m->value)->count(),
            ];
            if ($row['count'] > 0) {
                $methods[$m->value] = $row + ['net' => Money::round($row['in'] - $row['out'] + $row['pos'])];
            }
        }

        $moves = $shift->movements()->get(['type', 'amount']);
        $paidIn = (float) $moves->where('type', 'paid_in')->sum('amount');
        $paidOut = (float) $moves->where('type', 'paid_out')->sum('amount');
        $drops = (float) $moves->where('type', 'drop')->sum('amount');
        $cashNet = $methods['cash']['net'] ?? 0.0;

        return [
            'methods'       => $methods,
            'cash_sales'    => $cashNet,
            'paid_in'       => $paidIn,
            'paid_out'      => $paidOut,
            'drops'         => $drops,
            'room_charges'  => (float) $roomCharges->sum('total'),
            'room_count'    => $roomCharges->count(),
            'pos_voids'     => $voids,
            'total_taken'   => Money::round(array_sum(array_column($methods, 'net'))),
            'expected_cash' => Money::round((float) $shift->opening_float + $cashNet + $paidIn - $paidOut - $drops),
        ];
    }

    public function close(CashShift $shift, float $counted, ?string $note, ?array $denominations = null): CashShift
    {
        if (! $shift->isOpen()) {
            throw new BookingException('This shift is already closed.');
        }
        if ($counted < 0) {
            throw new BookingException('The counted cash can’t be negative.');
        }

        return DB::transaction(function () use ($shift, $counted, $note, $denominations) {
            $shift->forceFill(['closed_at' => now()]);
            $totals = $this->totals($shift);
            $variance = Money::round($counted - $totals['expected_cash']);
            $tolerance = (float) config('hotel.cash.variance_tolerance', 100);
            if (abs($variance) > $tolerance && blank($note)) {
                throw new BookingException('The count is ' . ($variance < 0 ? 'short' : 'over') . ' by ' . $this->money(abs($variance)) . '. Add a note explaining the difference.');
            }

            $by = auth()->user();
            $shift->forceFill([
                'status'        => CashShift::CLOSED,
                'expected_cash' => $totals['expected_cash'],
                'counted_cash'  => Money::round($counted),
                'variance'      => $variance,
                'summary'       => $totals,
                'denominations' => $denominations ?: null,
                'close_note'    => $note,
            ])->save();

            $whose = $by && $by->id !== $shift->user_id ? " (closed for {$shift->user->name} by {$by->name})" : '';
            Audit::record('billing.shift_closed', "Closed shift {$shift->number}{$whose}: expected " . $this->money($totals['expected_cash'])
                . ', counted ' . $this->money($counted) . ($variance == 0 ? ', balanced' : ', ' . ($variance < 0 ? 'short ' : 'over ') . $this->money(abs($variance))),
                $shift, ['expected_cash' => $totals['expected_cash']], ['counted_cash' => Money::round($counted), 'variance' => $variance],
                $note, abs($variance) > $tolerance);

            return $shift;
        });
    }

    public function review(CashShift $shift, ?string $note): void
    {
        $by = auth()->user();
        if ($shift->status !== CashShift::CLOSED) {
            throw new BookingException($shift->isOpen() ? 'Close the shift before signing it off.' : 'This shift is already signed off.');
        }
        if ($by->id === $shift->user_id && ! $by->hasRole('Super Admin')) {
            throw new BookingException('Someone else must sign off your own shift.');
        }

        $shift->forceFill(['status' => CashShift::REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $by->id, 'review_note' => $note])->save();
        Audit::record('billing.shift_reviewed', "Signed off shift {$shift->number} of {$shift->user->name}", $shift,
            ['status' => CashShift::CLOSED], ['status' => CashShift::REVIEWED], $note);
    }

    private function money(float $amount): string
    {
        return config('hotel.currency_symbol') . number_format($amount, 2);
    }
}
