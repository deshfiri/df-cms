<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function create(Client $client, array $data): Payment
    {
        return DB::transaction(function () use ($client, $data) {
            $data['client_id']  = $client->id;
            $data['created_by'] = Auth::id();

            $payment = Payment::create($data);
            $this->activityLog->log('Payment', 'Created', $client->id, null, $data);

            return $payment;
        });
    }

    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $old = $payment->toArray();
            $payment->update($data);
            $this->activityLog->log('Payment', 'Updated', $payment->client_id, $old, $data);

            return $payment->fresh();
        });
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $this->activityLog->log('Payment', 'Deleted', $payment->client_id, $payment->toArray());
            $payment->delete();
        });
    }

    public function summaryForClient(Client $client): array
    {
        $payments = $client->payments;

        return [
            'total_paid'    => $payments->where('status', 'Paid')->sum('amount'),
            'total_partial' => $payments->where('status', 'Partial')->sum('amount'),
            'count'         => $payments->count(),
            'latest_status' => $payments->first()?->status,
        ];
    }
}
