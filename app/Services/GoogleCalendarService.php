<?php

namespace App\Services;

use App\Models\ClientMeeting;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarService implements GoogleCalendarServiceInterface
{
    private ?GoogleCalendar $service = null;

    public function isConfigured(): bool
    {
        $path = config('services.google_calendar.credentials_path');

        return !empty($path) && is_file($path);
    }

    public function createEvent(ClientMeeting $meeting): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $service = $this->client();
            $event = $this->buildEvent($meeting);
            $event->setConferenceData($this->conferenceRequest());

            $created = $service->events->insert(
                config('services.google_calendar.calendar_id', 'primary'),
                $event,
                ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']
            );

            $meetUrl = null;
            $entryPoints = $created->getConferenceData()?->getEntryPoints() ?? [];
            foreach ($entryPoints as $entryPoint) {
                if ($entryPoint->getEntryPointType() === 'video') {
                    $meetUrl = $entryPoint->getUri();
                    break;
                }
            }

            return ['event_id' => $created->getId(), 'meet_url' => $meetUrl];
        } catch (Throwable $e) {
            Log::warning('Google Calendar: failed to create event', ['meeting_id' => $meeting->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function updateEvent(ClientMeeting $meeting): bool
    {
        if (!$this->isConfigured() || !$meeting->google_event_id) {
            return false;
        }

        try {
            $service = $this->client();
            $event = $this->buildEvent($meeting);

            $service->events->patch(
                config('services.google_calendar.calendar_id', 'primary'),
                $meeting->google_event_id,
                $event,
                ['sendUpdates' => 'all']
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Google Calendar: failed to update event', ['meeting_id' => $meeting->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function cancelEvent(ClientMeeting $meeting): bool
    {
        if (!$this->isConfigured() || !$meeting->google_event_id) {
            return false;
        }

        try {
            $service = $this->client();
            $event = new Event(['status' => 'cancelled']);

            $service->events->patch(
                config('services.google_calendar.calendar_id', 'primary'),
                $meeting->google_event_id,
                $event,
                ['sendUpdates' => 'all']
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Google Calendar: failed to cancel event', ['meeting_id' => $meeting->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function deleteEvent(ClientMeeting $meeting): bool
    {
        if (!$this->isConfigured() || !$meeting->google_event_id) {
            return false;
        }

        try {
            $this->client()->events->delete(
                config('services.google_calendar.calendar_id', 'primary'),
                $meeting->google_event_id,
                ['sendUpdates' => 'all']
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Google Calendar: failed to delete event', ['meeting_id' => $meeting->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function client(): GoogleCalendar
    {
        if ($this->service) {
            return $this->service;
        }

        $client = new GoogleClient();
        $client->setApplicationName(config('app.name'));
        $client->setAuthConfig(config('services.google_calendar.credentials_path'));
        $client->setScopes([GoogleCalendar::CALENDAR_EVENTS]);

        if ($subject = config('services.google_calendar.impersonate_email')) {
            $client->setSubject($subject);
        }

        return $this->service = new GoogleCalendar($client);
    }

    private function buildEvent(ClientMeeting $meeting): Event
    {
        $timezone = config('app.timezone', 'UTC');
        $start = $meeting->scheduled_at;
        $end = $start->copy()->addMinutes($meeting->duration_minutes);

        $attendees = [];
        if ($meeting->assignedUser?->email) {
            $attendees[] = new EventAttendee(['email' => $meeting->assignedUser->email]);
        }
        if ($meeting->client?->contact_email) {
            $attendees[] = new EventAttendee(['email' => $meeting->client->contact_email]);
        }

        return new Event([
            'summary'     => $meeting->title,
            'description' => $meeting->agenda ?? $meeting->notes,
            'location'    => $meeting->location,
            'start'       => new EventDateTime(['dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone]),
            'end'         => new EventDateTime(['dateTime' => $end->toRfc3339String(), 'timeZone' => $timezone]),
            'attendees'   => $attendees,
        ]);
    }

    private function conferenceRequest(): ConferenceData
    {
        return new ConferenceData([
            'createRequest' => new CreateConferenceRequest([
                'requestId'             => (string) Str::uuid(),
                'conferenceSolutionKey' => new ConferenceSolutionKey(['type' => 'hangoutsMeet']),
            ]),
        ]);
    }
}
