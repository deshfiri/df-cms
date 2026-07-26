<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Payment;
use App\Models\PaymentProofSubmission;
use App\Models\User;
use App\Notifications\PaymentProofSubmitted;
use App\Notifications\Portal\PaymentVerified;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PaymentProofService
{
    use NotifiesPortalUsers;

    private const NOTIFY_ROLES = ['Accounts', 'Super Admin'];

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function submit(
        Client $client,
        ClientPortalUser $portalUser,
        ?int $invoiceId,
        ?float $amountClaimed,
        ?string $paymentMethod,
        ?string $transactionReference,
        ?string $paymentDate,
        UploadedFile $file,
    ): PaymentProofSubmission {
        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid() . '.' . $ext;
        $path = $file->storeAs('portal/payment-proofs/' . $client->id, $storedName, 'local');

        $proof = PaymentProofSubmission::create([
            'client_id'             => $client->id,
            'invoice_id'            => $invoiceId,
            'submitted_by'          => $portalUser->id,
            'amount_claimed'        => $amountClaimed,
            'payment_method'        => $paymentMethod,
            'transaction_reference' => $transactionReference,
            'payment_date'          => $paymentDate,
            'original_name'         => $file->getClientOriginalName(),
            'stored_name'           => $storedName,
            'disk'                  => 'local',
            'path'                  => $path,
            'mime_type'             => $file->getMimeType() ?? 'application/octet-stream',
            'file_size'             => $file->getSize(),
            'status'                => PaymentProofSubmission::STATUS_PENDING,
        ]);

        $this->notifyAccounts($proof);

        return $proof;
    }

    private function notifyAccounts(PaymentProofSubmission $proof): void
    {
        $existingRoles = Role::whereIn('name', self::NOTIFY_ROLES)->pluck('name')->all();
        if (empty($existingRoles)) {
            return;
        }

        $recipients = User::role($existingRoles)->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new PaymentProofSubmitted($proof));
    }

    public function verify(PaymentProofSubmission $proof, ?string $note = null): PaymentProofSubmission
    {
        return DB::transaction(function () use ($proof, $note) {
            $payment = Payment::create([
                'client_id'          => $proof->client_id,
                'invoice_id'         => $proof->invoice_id,
                'amount'             => $proof->amount_claimed,
                'payment_date'       => $proof->payment_date ?? now(),
                'payment_method'     => $proof->payment_method,
                'transaction_number' => $proof->transaction_reference,
                'status'             => 'Paid',
                'created_by'         => Auth::id(),
            ]);

            $proof->update([
                'status'            => PaymentProofSubmission::STATUS_VERIFIED,
                'verified_by'       => Auth::id(),
                'verified_at'       => now(),
                'verification_note' => $note,
                'resulting_payment_id' => $payment->id,
            ]);

            $this->activityLog->log('Payment Proof', 'Verified', $proof->client_id, null, ['amount' => $proof->amount_claimed]);

            if ($proof->invoice) {
                $this->invoiceService->recalculateStatus($proof->invoice);
            }

            $this->notifyPortalUsers($proof->client, new PaymentVerified($proof));

            return $proof;
        });
    }

    public function reject(PaymentProofSubmission $proof, string $note): PaymentProofSubmission
    {
        $proof->update([
            'status'            => PaymentProofSubmission::STATUS_REJECTED,
            'verified_by'       => Auth::id(),
            'verified_at'       => now(),
            'verification_note' => $note,
        ]);

        $this->activityLog->log('Payment Proof', 'Rejected', $proof->client_id, null, ['note' => $note]);

        return $proof;
    }
}
