<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\MaintenanceOrder;
use App\Models\Room;
use App\Services\Mobile\StaffActions;
use App\Services\Operations\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** My work and approvals: the lists, and the buttons on each item. */
class WorkController extends ApiController
{
    public function __construct(private StaffActions $actions)
    {
    }

    /** GET /work */
    public function work(Request $request)
    {
        $items = $this->actions->work($request->user());

        return $this->ok($items, meta: ['count' => count($items), 'groups' => StaffActions::groups()->only(StaffActions::WORK_KINDS)]);
    }

    /** GET /approvals */
    public function approvals(Request $request)
    {
        abort_unless($this->actions->canApprove($request->user()), 403, 'You do not approve anything here.');
        $items = $this->actions->approvals($request->user());

        return $this->ok($items, meta: ['count' => count($items), 'groups' => StaffActions::groups()->only(StaffActions::APPROVAL_KINDS)]);
    }

    /** POST /items/{kind}/{id}/actions/{action}  {input?} */
    public function act(Request $request, string $kind, int $id, string $action)
    {
        abort_unless(in_array($kind, [...StaffActions::WORK_KINDS, ...StaffActions::APPROVAL_KINDS], true), 404);
        $data = $request->validate(['input' => 'nullable|string|max:1000']);
        $user = $request->user();

        try {
            $message = $this->actions->perform($user, $kind, $id, $action, $data['input'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422, 'not_allowed_now');
        }

        // The item as it is now (null when it has left this person's list).
        return $this->ok($this->actions->item($user, $kind, $id), $message);
    }

    // ── Report a fault ────────────────────────────────────────────────

    /** GET /report/options */
    public function reportOptions(Request $request)
    {
        abort_unless($request->user()->canAny(['Report maintenance issues', 'Manage maintenance']), 403);

        return $this->ok([
            'rooms'      => Room::active()->orderBy('number')->get(['id', 'number'])->map(fn ($r) => ['id' => $r->id, 'number' => $r->number])->values(),
            'categories' => collect(MaintenanceOrder::CATEGORIES)->map(fn ($v, $k) => ['key' => $k, 'label' => is_array($v) ? $v[0] : $v])->values(),
            'priorities' => collect(MaintenanceOrder::PRIORITIES)->map(fn ($v, $k) => ['key' => $k, 'label' => is_array($v) ? $v[0] : $v])->values(),
        ]);
    }

    /** POST /report  (multipart when photos are sent) */
    public function report(Request $request, MaintenanceService $service)
    {
        abort_unless($request->user()->canAny(['Report maintenance issues', 'Manage maintenance']), 403);
        $data = $request->validate([
            'room_id'              => 'nullable|required_without:location|integer|exists:rooms,id',
            'location'             => 'nullable|required_without:room_id|string|max:120',
            'category'             => ['required', Rule::in(array_keys(MaintenanceOrder::CATEGORIES))],
            'priority'             => ['required', Rule::in(array_keys(MaintenanceOrder::PRIORITIES))],
            'title'                => 'required|string|max:150',
            'description'          => 'nullable|string|max:2000',
            'blocks_room'          => 'nullable|boolean',
            'housekeeping_task_id' => 'nullable|integer|exists:housekeeping_tasks,id',
            'photos'               => 'nullable|array|max:4',
            'photos.*'             => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ], ['room_id.required_without' => 'Choose a room or type where the problem is.']);
        $data['blocks_room'] = $request->boolean('blocks_room');

        try {
            $order = $service->report($data, $request->file('photos', []), $request->user());
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['id' => $order->id, 'number' => $order->number],
            "{$order->number} reported" . ($order->blocks_room && $order->room?->status?->value === 'maintenance' ? " — room {$order->room->number} taken off sale until it's fixed." : '.'), status: 201);
    }
}
