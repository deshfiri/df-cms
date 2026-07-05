<?php

namespace App\Services\Contracts;

use App\Models\ClientMeeting;

interface GoogleCalendarServiceInterface
{
    /**
     * Whether real credentials are present. Every other method is a safe
     * no-op when this is false — nothing here should ever block booking,
     * updating, or cancelling a meeting locally.
     */
    public function isConfigured(): bool;

    /**
     * Create the calendar event (+ Meet link) for a newly booked meeting.
     * Returns ['event_id' => string, 'meet_url' => ?string] or null if not
     * configured / the API call failed.
     */
    public function createEvent(ClientMeeting $meeting): ?array;

    /**
     * Push a local time/detail change to the existing calendar event.
     */
    public function updateEvent(ClientMeeting $meeting): bool;

    /**
     * Mark the calendar event cancelled (does not delete it, matching
     * Google's own recommended cancellation semantics).
     */
    public function cancelEvent(ClientMeeting $meeting): bool;

    /**
     * Remove the calendar event entirely (used when a meeting record itself
     * is deleted, not merely cancelled).
     */
    public function deleteEvent(ClientMeeting $meeting): bool;
}
