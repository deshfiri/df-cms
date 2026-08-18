<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Pushes a notification down the recipient's private Reverb channel as well as
 * writing the bell row.
 *
 * The layout already subscribes to `App.Models.User.{id}` and refreshes the bell
 * on Echo's `.notification()` callback, so a notification that adds 'broadcast'
 * to its channels arrives live instead of waiting for the 60-second poll.
 *
 * The payload is deliberately the same array the database row stores: one
 * definition of what a notification says, two ways of delivering it.
 */
trait BroadcastsToDashboard
{
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
