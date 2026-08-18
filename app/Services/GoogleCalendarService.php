<?php

namespace App\Services;

use App\Models\ClientMeeting;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use App\Services\Google\GoogleIntegrationSettings;
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

    public function __construct(
        private readonly GoogleIntegrationSettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Reach Google with the stored credentials and report back in plain words.
     *
     * Reads the target calendar rather than writing anything: it exercises auth,
     * the calendar id and the scope in one call, without leaving a stray event
     * behind on someone's real calendar.
     *
     * @return array{ok:bool,message:string}
     */
    public function verifyConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No Google credentials are configured.'];
        }

        try {
            $calendarId = $this->settings->calendarId();
            $calendar   = $this->client()->calendars->get($calendarId);

            $mode = $this->settings->activeMode() === GoogleIntegrationSettings::MODE_OAUTH
                ? 'OAuth'
                : 'service account';

            $warning = $this->meetLinkWarning();

            return [
                'ok'      => true,
                'message' => sprintf(
                    'Connected via %s. Events will be created on "%s".%s',
                    $mode,
                    $calendar->getSummary() ?: $calendarId,
                    $warning ? ' ' . $warning : ''
                ),
            ];
        } catch (Throwable $e) {
            Log::warning('Google Calendar: connection test failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * A service account with nobody to impersonate creates events happily and
     * then quietly omits the Meet link — the exact symptom that is hardest to
     * diagnose from the booking screen.
     */
    private function meetLinkWarning(): ?string
    {
        if ($this->settings->activeMode() !== GoogleIntegrationSettings::MODE_SERVICE_ACCOUNT) {
            return null;
        }

        return $this->settings->impersonateEmail()
            ? null
            : 'Warning: no impersonation email is set, so Google Meet links will not be generated.';
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
                $this->settings->calendarId(),
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
                $this->settings->calendarId(),
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
                $this->settings->calendarId(),
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
                $this->settings->calendarId(),
                $meeting->google_event_id,
                ['sendUpdates' => 'all']
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Google Calendar: failed to delete event', ['meeting_id' => $meeting->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * OAuth is preferred: it acts as a real Google account, which is what makes
     * Meet links appear. The service account is the fallback for a Workspace
     * setup with domain-wide delegation.
     */
    private function client(): GoogleCalendar
    {
        if ($this->service) {
            return $this->service;
        }

        $client = new GoogleClient();
        $client->setApplicationName(config('app.name'));
        $client->setScopes([GoogleCalendar::CALENDAR_EVENTS]);

        if ($this->settings->activeMode() === GoogleIntegrationSettings::MODE_OAUTH) {
            $client->setClientId($this->settings->clientId());
            $client->setClientSecret($this->settings->clientSecret());
            // Exchanges the stored refresh token for a short-lived access token.
            $client->fetchAccessTokenWithRefreshToken($this->settings->refreshToken());
        } else {
            $client->setAuthConfig($this->settings->serviceAccountPath());

            if ($subject = $this->settings->impersonateEmail()) {
                $client->setSubject($subject);
            }
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
