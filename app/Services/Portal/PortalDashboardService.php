<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Services\PaymentService;

class PortalDashboardService
{
    public function __construct(
        private readonly PortalJourneyPresenter $journeyPresenter,
        private readonly PortalServiceGroupingService $serviceGrouping,
        private readonly PaymentService $paymentService,
    ) {}

    public function buildDashboard(Client $client): array
    {
        $stages = $this->journeyPresenter->present($client);
        $current = $this->journeyPresenter->currentStage($client);
        $overallProgress = $this->journeyPresenter->overallProgressPercent($client);
        $completedStages = count(array_filter($stages, fn ($s) => $s['status'] === 'Approved'));

        $paymentSummary = $this->paymentService->summaryForClient($client);

        $services = $this->serviceGrouping->groupByDepartment($client);
        $activeServices = count(array_filter($services, fn ($s) => $s['status'] === 'Active'));

        $pendingActions = $client->actionRequests()
            ->whereIn('status', ['Pending', 'Need Revision'])
            ->count();

        $pendingApprovals = $client->approvalRequests()
            ->whereIn('status', ['Pending', 'Revision Requested'])
            ->count();

        $dueAmount = $client->invoices()
            ->whereNotIn('status', ['Paid', 'Refunded', 'Non-Refundable', 'Cancelled'])
            ->get()
            ->sum(fn ($invoice) => $invoice->due_amount);

        $latestUpdates = $client->projectUpdates()->visible()->limit(5)->get();
        $recentDocuments = $client->clientDocuments()->clientVisible()->latest()->limit(5)->get();
        $recentInvoices = $client->invoices()->latest()->limit(5)->get();

        return [
            'overall_progress'  => $overallProgress,
            'current_stage'     => $current,
            'completed_stages'  => $completedStages,
            'total_stages'      => count($stages),
            'active_services'   => $activeServices,
            'pending_actions'   => $pendingActions,
            'pending_approvals' => $pendingApprovals,
            'due_amount'        => $dueAmount,
            'payment_summary'   => $paymentSummary,
            'latest_updates'    => $latestUpdates,
            'recent_documents'  => $recentDocuments,
            'recent_invoices'   => $recentInvoices,
            'manager'           => $client->assignedUser,
        ];
    }
}
