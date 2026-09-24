<?php

namespace App\Services;

use App\Models\BugReport;
use App\Models\User;
use App\Notifications\BugReportResolved;
use App\Notifications\BugReportSubmitted;
use App\Services\Concerns\NotifiesStaff;
use Illuminate\Support\Facades\DB;

class BugReportService
{
    use NotifiesStaff;

    private const REVIEWER_ROLES = ['Super Admin', 'Manager'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(array $data, User $actor): BugReport
    {
        return DB::transaction(function () use ($data, $actor) {
            $data['reported_by'] = $actor->id;
            $data['status']      = BugReport::STATUS_OPEN;

            $report = BugReport::create($data);

            $this->activityLog->log(
                'Bug Report',
                'Submitted',
                null,
                null,
                ['subject' => $report->subject, 'severity' => $report->severity]
            );

            $this->notifyReviewers($report, $actor);

            return $report->load('reportedBy:id,name');
        });
    }

    public function respond(BugReport $report, string $status, ?string $note, User $actor): BugReport
    {
        $report->update([
            'status'        => $status,
            'response_note' => $note,
            'reviewed_by'   => $actor->id,
            'reviewed_at'   => now(),
        ]);

        $this->activityLog->log(
            'Bug Report',
            "Bug Report {$status}",
            null,
            null,
            ['subject' => $report->subject]
        );

        // Someone resolving their own report already knows the outcome.
        if ($report->reportedBy && $report->reported_by !== $actor->id) {
            $report->reportedBy->notify(new BugReportResolved($report));
        }

        return $report->fresh();
    }

    public function delete(BugReport $report): void
    {
        $this->activityLog->log('Bug Report', 'Deleted', null, ['subject' => $report->subject]);
        $report->delete();
    }

    /** Reviewers who can actually action the report — never the person who filed it. */
    private function notifyReviewers(BugReport $report, User $actor): void
    {
        $this->notifyStaff(
            self::REVIEWER_ROLES,
            new BugReportSubmitted($report),
            permission: 'manage bug reports',
            except: $actor,
        );
    }
}
