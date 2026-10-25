<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Notification;

/**
 * One alert for a member of staff: kept for the app's Alerts list and, when
 * push is set up and the person hasn't turned that kind off, sent to their phone.
 *
 * data: title, body, group (work|guests|approvals|it), level (urgent|normal|passive),
 *       url (an app link such as "work" or a website address), thread, kind, item_id
 */
class StaffAlert extends Notification
{
    public function __construct(public array $data)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return $this->data + ['level' => 'normal'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_alert';
    }
}
