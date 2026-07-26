<?php

namespace App\Services\Portal;

use App\Models\Client;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

trait NotifiesPortalUsers
{
    protected function notifyPortalUsers(Client $client, Notification $notification): void
    {
        $recipients = $client->portalUsers()->where('status', 'Active')->get();

        if ($recipients->isNotEmpty()) {
            NotificationFacade::send($recipients, $notification);
        }
    }
}
