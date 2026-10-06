<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Identical to BroadcastsToDashboard, with one difference: the broadcast push
 * itself is forced onto Laravel's built-in "sync" queue connection, so it goes
 * out inline, in the same request, rather than waiting for a queue worker to
 * pick up the job.
 *
 * Why this exists: every notification's *database* row is already written
 * synchronously, request-time, regardless of which of these two traits it
 * uses — that part was never the gap. But the *broadcast* side of a plain
 * BroadcastsToDashboard notification goes through Illuminate\Notifications\
 * Events\BroadcastNotificationCreated, a framework event that implements
 * ShouldBroadcast (not ShouldBroadcastNow) — so Laravel always defers its
 * delivery to a queued Illuminate\Broadcasting\BroadcastEvent job on the
 * app's default queue connection. With QUEUE_CONNECTION=database (this app's
 * local setting) that job sits in the `jobs` table until a worker
 * (`php artisan queue:work`/`queue:listen`, normally kept running by
 * `composer dev`) processes it — exactly what manual QA found: the database
 * notification was already correct, but the live bell/sound/desktop-alert
 * never fired without that worker actively running.
 *
 * `BroadcastMessage::onConnection('sync')` (from the Illuminate\Bus\Queueable
 * trait it already carries) tells Laravel to dispatch that same job on the
 * "sync" connection instead — a connection that always exists, requires no
 * worker, and pushes to the broadcaster immediately. This mirrors the
 * existing, already-proven pattern this codebase uses for its other
 * must-feel-instant events (see App\Events\MessageSent, FlowItemClaimed:
 * both ShouldBroadcastNow "so delivery is immediate and doesn't depend on a
 * queue worker") — same intent, reached through the one lever a Notification
 * class (rather than a raw broadcast Event) actually exposes for it.
 *
 * Every dispatch of a notification using this trait must still be wrapped in
 * a try/catch at the call site: forcing the broadcast inline means a
 * genuinely unreachable broadcaster (Reverb not running) surfaces as an
 * exception in the same request that triggered it, and the underlying
 * workflow operation — already committed to the database by this point —
 * must never be allowed to fail just because that push didn't go through.
 */
trait BroadcastsInstantlyToDashboard
{
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onConnection('sync');
    }
}
