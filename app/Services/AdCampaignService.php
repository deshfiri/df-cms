<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdCampaignAssignment;
use App\Models\AdCampaignDailyReport;
use App\Models\Client;
use App\Models\User;
use App\Notifications\AdCampaignAssigned;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class AdCampaignService
{
    private const NOTIFY_ROLES = ['Super Admin'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data): AdCampaign
    {
        return DB::transaction(function () use ($client, $data) {
            $campaign = AdCampaign::create(array_merge($data, [
                'client_id'  => $client->id,
                'created_by' => Auth::id(),
            ]));

            $this->activityLog->log('Ad Campaign', 'Created', $client->id, null, ['name' => $campaign->name]);

            return $campaign;
        });
    }

    public function update(AdCampaign $campaign, array $data): AdCampaign
    {
        return DB::transaction(function () use ($campaign, $data) {
            $old = $campaign->only(array_keys($data));

            $campaign->update(array_merge($data, ['updated_by' => Auth::id()]));

            $this->activityLog->log('Ad Campaign', 'Updated', $campaign->client_id, $old, $data);

            return $campaign;
        });
    }

    public function delete(AdCampaign $campaign): void
    {
        $this->activityLog->log('Ad Campaign', 'Deleted', $campaign->client_id, ['name' => $campaign->name]);
        $campaign->delete();
    }

    public function assign(AdCampaign $campaign, User $newAssignee, User $actor, ?string $note = null, bool $notify = true): AdCampaign
    {
        return DB::transaction(function () use ($campaign, $newAssignee, $actor, $note, $notify) {
            $previousAssigneeId = $campaign->assigned_to;

            $campaign->update([
                'assigned_to' => $newAssignee->id,
                'updated_by'  => $actor->id,
            ]);

            $assignment = AdCampaignAssignment::create([
                'ad_campaign_id'        => $campaign->id,
                'previous_assignee_id'  => $previousAssigneeId,
                'new_assignee_id'       => $newAssignee->id,
                'assigned_by'           => $actor->id,
                'note'                  => $note,
            ]);

            $this->activityLog->log(
                'Ad Campaign',
                $previousAssigneeId ? 'Reassigned' : 'Assigned',
                $campaign->client_id,
                ['assigned_to' => $previousAssigneeId],
                ['assigned_to' => $newAssignee->id, 'note' => $note]
            );

            if ($notify) {
                $this->notify($assignment, $newAssignee);
            }

            return $campaign->fresh();
        });
    }

    public function upsertReport(AdCampaign $campaign, array $data): AdCampaignDailyReport
    {
        return DB::transaction(function () use ($campaign, $data) {
            $report = $campaign->dailyReports()
                ->whereDate('report_date', $data['report_date'])
                ->first();

            if ($report) {
                $report->update(array_merge($data, ['updated_by' => Auth::id()]));
                $this->activityLog->log('Ad Campaign Report', 'Updated', $campaign->client_id, null, ['date' => $data['report_date']]);
            } else {
                $report = AdCampaignDailyReport::create(array_merge($data, [
                    'ad_campaign_id' => $campaign->id,
                    'created_by'     => Auth::id(),
                ]));
                $this->activityLog->log('Ad Campaign Report', 'Created', $campaign->client_id, null, ['date' => $data['report_date']]);
            }

            return $report;
        });
    }

    public function deleteReport(AdCampaignDailyReport $report): void
    {
        $this->activityLog->log('Ad Campaign Report', 'Deleted', $report->campaign->client_id, ['date' => (string) $report->report_date]);
        $report->delete();
    }

    private function notify(AdCampaignAssignment $assignment, User $newAssignee): void
    {
        $existingRoles = Role::whereIn('name', self::NOTIFY_ROLES)->pluck('name')->all();
        $recipients = $existingRoles ? User::role($existingRoles)->where('is_active', true)->get() : collect();

        if (!$recipients->contains('id', $newAssignee->id)) {
            $recipients->push($newAssignee);
        }

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AdCampaignAssigned($assignment));
        }
    }
}
