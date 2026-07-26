<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\Portal\SupportReplyPosted;
use App\Notifications\SupportTicketCreated;
use App\Notifications\SupportTicketReplied;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class SupportTicketService
{
    use NotifiesPortalUsers;

    private const NOTIFY_ROLES = ['Support', 'Super Admin'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly PortalActivityLogService $portalActivityLog,
    ) {}

    public function create(
        Client $client,
        ClientPortalUser $portalUser,
        string $category,
        string $priority,
        string $subject,
        string $message,
        ?UploadedFile $file,
    ): SupportTicket {
        return DB::transaction(function () use ($client, $portalUser, $category, $priority, $subject, $message, $file) {
            $fileData = $this->storeFile($file, 'portal/support/' . $client->id);

            $ticket = SupportTicket::create(array_merge([
                'ticket_number' => 'TKT-' . now()->format('ymd') . '-' . strtoupper(Str::random(5)),
                'client_id'     => $client->id,
                'created_by'    => $portalUser->id,
                'category'      => $category,
                'priority'      => $priority,
                'subject'       => $subject,
                'message'       => $message,
                'status'        => SupportTicket::STATUS_OPEN,
            ], $fileData));

            $this->portalActivityLog->log($portalUser, 'Support Ticket', 'Created', SupportTicket::class, $ticket->id);
            $this->notifySupport($ticket);

            return $ticket;
        });
    }

    public function reply(
        SupportTicket $ticket,
        string $authorType,
        User|ClientPortalUser $author,
        string $message,
        ?UploadedFile $file,
        bool $isInternalNote = false,
    ): SupportTicketReply {
        return DB::transaction(function () use ($ticket, $authorType, $author, $message, $file, $isInternalNote) {
            $fileData = $this->storeFile($file, 'portal/support/' . $ticket->client_id);

            $reply = SupportTicketReply::create(array_merge([
                'support_ticket_id'      => $ticket->id,
                'author_type'            => $authorType,
                'author_user_id'         => $authorType === SupportTicketReply::AUTHOR_STAFF ? $author->id : null,
                'author_portal_user_id'  => $authorType === SupportTicketReply::AUTHOR_PORTAL ? $author->id : null,
                'message'                => $message,
                'is_internal_note'       => $isInternalNote,
            ], $fileData));

            $ticket->update([
                'last_reply_at' => now(),
                'status'        => $authorType === SupportTicketReply::AUTHOR_PORTAL
                    ? SupportTicket::STATUS_OPEN
                    : SupportTicket::STATUS_WAITING_FOR_CLIENT,
            ]);

            if ($authorType === SupportTicketReply::AUTHOR_PORTAL) {
                $this->portalActivityLog->log($author, 'Support Ticket', 'Replied', SupportTicket::class, $ticket->id);

                if ($ticket->assignedTo) {
                    Notification::send($ticket->assignedTo, new SupportTicketReplied($ticket, $reply));
                } else {
                    $this->notifySupport($ticket);
                }
            } else {
                $this->activityLog->log('Support Ticket', 'Replied', $ticket->client_id, null, ['ticket' => $ticket->ticket_number]);

                if (!$isInternalNote) {
                    $this->notifyPortalUsersOfReply($ticket, $reply);
                }
            }

            return $reply;
        });
    }

    private function storeFile(?UploadedFile $file, string $folder): array
    {
        if (!$file) {
            return [];
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid() . '.' . $ext;
        $path = $file->storeAs($folder, $storedName, 'local');

        return [
            'original_name' => $file->getClientOriginalName(),
            'stored_name'   => $storedName,
            'disk'          => 'local',
            'path'          => $path,
            'mime_type'     => $file->getMimeType() ?? 'application/octet-stream',
            'file_size'     => $file->getSize(),
        ];
    }

    private function notifyPortalUsersOfReply(SupportTicket $ticket, SupportTicketReply $reply): void
    {
        $this->notifyPortalUsers($ticket->client, new SupportReplyPosted($ticket, $reply));
    }

    private function notifySupport(SupportTicket $ticket): void
    {
        $roles = Role::whereIn('name', self::NOTIFY_ROLES)->pluck('name')->all();
        if (empty($roles)) {
            return;
        }

        $recipients = User::role($roles)->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new SupportTicketCreated($ticket));
    }
}
