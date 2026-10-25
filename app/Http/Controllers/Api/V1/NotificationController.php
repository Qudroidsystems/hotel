<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\User;
use App\Services\Mobile\AppPreferences;
use App\Services\Mobile\StaffActions;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The app's Alerts list (Laravel database notifications), its buttons and settings. */
class NotificationController extends ApiController
{
    public function __construct(private StaffActions $actions)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $filter = (string) $request->query('filter', 'all');
        $page = $user->notifications()
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(isset(AppPreferences::GROUPS[$filter]), fn ($q) => $q->where('data->group', $filter))
            ->paginate(min(50, max(10, (int) $request->query('per_page', 30))));

        $live = $this->liveActions($user, $page->getCollection());

        return $this->ok($page->getCollection()->map(fn ($n) => $this->present($n, $live))->values(), meta: [
            'unread'       => $user->unreadNotifications()->count(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
            'quiet'        => AppPreferences::for($user)->inQuietHours(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return $this->ok(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id)
    {
        $data = $request->validate(['ids' => 'nullable|array|max:200', 'ids.*' => 'string|max:40', 'unread' => 'nullable|boolean']);
        $ids = $data['ids'] ?? [$id];
        $request->user()->notifications()->whereIn('id', $ids)->update(['read_at' => $request->boolean('unread') ? null : now()]);

        return $this->ok(null, meta: ['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(null, meta: ['unread' => 0]);
    }

    public function destroy(Request $request, string $id)
    {
        $data = $request->validate(['ids' => 'nullable|array|max:200', 'ids.*' => 'string|max:40']);
        $request->user()->notifications()->whereIn('id', $data['ids'] ?? [$id])->delete();

        return $this->ok(null, meta: ['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function clearRead(Request $request)
    {
        $removed = $request->user()->readNotifications()->delete();

        return $this->ok(['removed' => $removed]);
    }

    /** A button on an alert (Approve, Done ...): runs it like the same button on My work or Approvals. */
    public function action(Request $request, string $id, string $action)
    {
        $user = $request->user();
        $n = $user->notifications()->whereKey($id)->firstOrFail();
        $data = $request->validate(['input' => 'nullable|string|max:1000']);
        $kind = $n->data['kind'] ?? null;
        $itemId = (int) ($n->data['item_id'] ?? 0);
        if (! $kind || ! $itemId) {
            return $this->fail('This alert has no buttons.', 422, 'no_actions');
        }
        $label = collect($this->actions->item($user, $kind, $itemId)['actions'] ?? [])->firstWhere('id', $action)['label'] ?? null;

        try {
            $message = $this->actions->perform($user, $kind, $itemId, $action, $data['input'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422, 'not_allowed_now');
        }

        $n->forceFill(['data' => ['done' => $this->pastTense($action, $label)] + $n->data, 'read_at' => $n->read_at ?? now()])->save();

        return $this->ok($this->present($n->fresh(), []), $message);
    }

    public function settings(Request $request)
    {
        return $this->ok(AppPreferences::for($request->user())->settings());
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'groups'     => 'sometimes|array',
            'groups.*'   => 'boolean',
            'quiet_from' => 'sometimes|nullable|date_format:H:i|required_with:quiet_to',
            'quiet_to'   => 'sometimes|nullable|date_format:H:i|required_with:quiet_from',
        ]);

        return $this->ok(AppPreferences::for($request->user())->update($data)->settings(), 'Saved.');
    }

    // ------------------------------------------------------------------

    /** The buttons alerts should show now (an item done by someone else loses them), one lookup per kind of list. */
    private function liveActions(User $user, $notifications): array
    {
        $kinds = $notifications->filter(fn ($n) => empty($n->data['done']))->pluck('data.kind')->filter()->unique();
        if ($kinds->isEmpty()) {
            return [];
        }
        $items = [];
        if ($kinds->intersect(StaffActions::WORK_KINDS)->isNotEmpty()) {
            $items = array_merge($items, $this->actions->work($user));
        }
        if ($kinds->intersect(StaffActions::APPROVAL_KINDS)->isNotEmpty() && $this->actions->canApprove($user)) {
            $items = array_merge($items, $this->actions->approvals($user));
        }

        return collect($items)->mapWithKeys(fn ($i) => [$i['key'] => $i['actions']])->all();
    }

    private function present(DatabaseNotification $n, array $live): array
    {
        $d = $n->data;
        $group = $d['group'] ?? 'work';
        $key = isset($d['kind'], $d['item_id']) ? $d['kind'] . ':' . $d['item_id'] : null;

        return [
            'id'          => $n->id,
            'type'        => $n->type === 'staff_alert' ? 'staff_alert' : class_basename($n->type),
            'category'    => $group,
            'group'       => $group,
            'group_label' => AppPreferences::GROUPS[$group] ?? ucfirst($group),
            'title'       => $d['title'] ?? 'Alert',
            'body'        => $d['body'] ?? '',
            'level'       => $d['level'] ?? 'normal',
            'thread'      => $d['thread'] ?? null,
            'url'         => $d['url'] ?? null,
            'done'        => $d['done'] ?? null,
            'actions'     => empty($d['done']) && $key ? array_values(array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'label', 'style', 'input'])), $live[$key] ?? [])) : [],
            'read'        => $n->read_at !== null,
            'created_at'  => $n->created_at?->toIso8601String(),
            'time'        => $n->created_at?->diffForHumans(short: true),
            'data'        => array_filter(['kind' => $d['kind'] ?? null, 'item_id' => $d['item_id'] ?? null]),
        ];
    }

    private function pastTense(string $action, ?string $label): string
    {
        return match ($action) {
            'approve' => 'Approved', 'reject' => 'Rejected', 'signoff' => 'Signed off', 'done' => 'Done',
            'progress' => 'On it', 'start' => 'Started', 'finish' => 'Finished', 'pass' => 'Passed', 'fail' => 'Sent back',
            'complete' => 'Fixed', 'maintenance' => 'Sent to maintenance', default => $label ?? 'Done',
        };
    }
}
