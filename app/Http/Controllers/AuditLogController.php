<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Incident;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\SupplierInvoice;
use App\Models\LostItem;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The audit trail (spec §14): searchable, read-only, exportable. */
class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View audit log');
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'from'    => 'nullable|date',
            'to'      => 'nullable|date',
            'user'    => 'nullable|integer',
            'module'  => 'nullable|string|max:40',
            'q'       => 'nullable|string|max:100',
            'flagged' => 'nullable|boolean',
            'type'    => 'nullable|string|max:150',
            'id'      => 'nullable|integer',
        ]);

        $from = Carbon::parse($filters['from'] ?? today()->subDays(6))->startOfDay();
        $to = Carbon::parse($filters['to'] ?? today())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = AuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when($request->boolean('flagged'), fn ($q) => $q->where('flagged', true))
            ->when(($filters['type'] ?? null) && ($filters['id'] ?? null), fn ($q) => $q->where('subject_type', $filters['type'])->where('subject_id', $filters['id']))
            ->search($filters['q'] ?? null);

        if ($request->query('export') === 'csv') {
            return $this->csv((clone $query)->orderBy('id'), $from, $to);
        }

        $summary = (clone $query)->toBase()
            ->selectRaw('count(*) as total, sum(case when flagged then 1 else 0 end) as flagged')
            ->selectRaw("sum(case when action = 'auth.failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when action in ('billing.charge_voided','billing.payment_voided','billing.refund','pos.voided') then 1 else 0 end) as voids")
            ->first();

        $logs = $query->with('user:id,name,avatar')->orderByDesc('id')->paginate(50)->withQueryString();
        $users = User::whereIn('id', AuditLog::whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']);
        $modules = AuditLog::MODULES;
        $pagetitle = 'Audit Trail';

        return view('audit.index', compact('pagetitle', 'logs', 'users', 'modules', 'from', 'to', 'filters', 'summary'));
    }

    /** Where the record an entry is about can be opened, if anywhere. */
    public static function subjectUrl(AuditLog $log): ?string
    {
        if (! $log->subject_id) {
            return null;
        }
        $user = auth()->user();

        return match ($log->subject_type) {
            (new Folio)->getMorphClass()       => $user->can('View folios') ? route('folios.show', $log->subject_id) : null,
            (new Reservation)->getMorphClass() => $user->can('View reservations') ? route('reservations.show', $log->subject_id) : null,
            (new Room)->getMorphClass()        => $user->can('View rooms') ? route('rooms.show', $log->subject_id) : null,
            (new Guest)->getMorphClass()       => $user->can('View guests') ? route('guests.show', $log->subject_id) : null,
            (new StockItem)->getMorphClass()   => $user->can('View stock') ? route('inventory.items.show', $log->subject_id) : null,
            (new PosOrder)->getMorphClass()    => $user->canAny(['View POS reports', 'Use POS']) ? route('pos.orders.receipt', $log->subject_id) : null,
            (new User)->getMorphClass()        => $user->can('View user') ? route('users.overview', $log->subject_id) : null,
            (new CashShift)->getMorphClass()   => $user->canAny(['Review cash shifts', 'View finance reports']) ? route('cash-shifts.show', $log->subject_id) : null,
            (new Incident)->getMorphClass()    => $user->canAny(['View incidents', 'Manage incidents']) ? route('incidents.show', $log->subject_id) : null,
            (new Visitor)->getMorphClass()     => $user->can('View visitors') ? route('visitors.pass', $log->subject_id) : null,
            (new LostItem)->getMorphClass()    => $user->can('Manage lost & found') ? route('lost-found.index', ['show' => 'all', 'item' => $log->subject_id]) : null,
            (new PurchaseOrder)->getMorphClass()   => $user->can('View stock') ? route('inventory.po.show', $log->subject_id) : null,
            (new PurchaseRequest)->getMorphClass() => $user->canAny(['Approve purchases', 'Manage purchase orders']) ? route('inventory.requests.show', $log->subject_id) : null,
            (new SupplierInvoice)->getMorphClass() => $user->canAny(['Manage supplier invoices', 'Pay suppliers']) ? route('inventory.invoices.show', $log->subject_id) : null,
            default                            => null,
        };
    }

    private function csv($query, Carbon $from, Carbon $to): StreamedResponse
    {
        $name = 'audit-trail-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Time', 'User', 'Roles', 'Area', 'Action', 'Record', 'Description', 'Reason', 'Before', 'After', 'Flagged', 'IP address']);
            $query->chunk(500, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    fputcsv($out, array_map([self::class, 'safeCell'], [
                        $log->created_at->toDateString(), $log->created_at->format('H:i:s'), $log->user_name, $log->user_roles,
                        $log->moduleLabel(), $log->actionLabel(), $log->subject_label, $log->description, $log->reason,
                        $log->old_values ? json_encode($log->old_values, JSON_UNESCAPED_UNICODE) : '',
                        $log->new_values ? json_encode($log->new_values, JSON_UNESCAPED_UNICODE) : '',
                        $log->flagged ? 'Yes' : '', $log->ip_address,
                    ]));
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stop spreadsheet apps from running a cell as a formula. */
    private static function safeCell($value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
