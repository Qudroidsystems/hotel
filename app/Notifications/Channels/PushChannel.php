<?php

namespace App\Notifications\Channels;

use App\Services\Mobile\AppPreferences;
use App\Services\Mobile\PushSender;
use App\Services\Mobile\StaffActions;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/** Sends a StaffAlert to the person's phones, after the page or command has finished. */
class PushChannel
{
    public function __construct(private PushSender $push)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $this->push->enabled() || ! method_exists($notification, 'toArray')) {
            return;
        }
        $data = $notification->toArray($notifiable);
        if (! AppPreferences::for($notifiable)->wantsPush($data['group'] ?? 'work', $data['level'] ?? 'normal')) {
            return;
        }
        $id = $notification->id;

        // A slow or failed push must never hold up or break the work that raised it.
        defer(function () use ($notifiable, $data, $id) {
            try {
                // Buttons on the phone's notification (Approve, Done ...), as this person may press them.
                if (! empty($data['kind']) && ! empty($data['item_id'])) {
                    $data['actions'] = array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'label', 'style', 'input'])),
                        app(StaffActions::class)->item($notifiable, $data['kind'], (int) $data['item_id'])['actions'] ?? []);
                }
                $this->push->toUser($notifiable, $data, $id);
            } catch (\Throwable $e) {
                Log::warning('Push notification failed: ' . $e->getMessage());
            }
        });
    }
}
