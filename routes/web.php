<?php

use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowItemController;
use App\Http\Controllers\ClientActionRequestController;
use App\Http\Controllers\ClientApprovalRequestController;
use App\Http\Controllers\ClientCorrectionRequestController;
use App\Http\Controllers\ClientPortalAccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PerformanceConfigController;
use App\Http\Controllers\ProjectUpdateController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeRequestController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PendingChangeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductUpdateController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Auth::routes(['register' => false]);

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Client resource
    Route::resource('clients', ClientController::class);
    Route::post('clients/{client}/status', [ClientController::class, 'updateStatus'])->name('clients.status');
    Route::get('clients/{client}/quick-view', [ClientController::class, 'quickView'])->name('clients.quick-view');
    Route::post('clients/bulk-delete', [ClientController::class, 'bulkDelete'])->name('clients.bulk-delete');
    Route::post('clients/bulk-assign', [ClientController::class, 'bulkAssign'])->name('clients.bulk-assign');
    Route::post('clients/bulk-terminate', [ClientController::class, 'bulkTerminate'])->name('clients.bulk-terminate');

    // Nested sub-resources under client
    Route::prefix('clients/{client}')->name('clients.')->group(function () {

        // Workflow — the client's pipeline, driven by the flow engine.
        Route::get('workflow', [FlowItemController::class, 'clientWorkflow'])->name('workflow');

        // Legacy departmental pipeline. Still read by the client portal journey
        // and the dashboard; being retired in favour of the route above.
        Route::get('timeline', [WorkflowController::class, 'timeline'])->name('timeline');
        Route::post('stages/toggle', [WorkflowController::class, 'toggleStage'])->name('stages.toggle');
        Route::post('stages/submit', [WorkflowController::class, 'submitStage'])->name('stages.submit');
        Route::post('stages/approve', [WorkflowController::class, 'approveStage'])->name('stages.approve');
        Route::post('stages/reject', [WorkflowController::class, 'rejectStage'])->name('stages.reject');

        // Activity Log
        Route::get('activity', [ClientController::class, 'activity'])->name('activity');

        // Ownership
        Route::post('transfer', [ClientController::class, 'transferOwnership'])->name('transfer');
        Route::get('ownership-history', [ClientController::class, 'ownershipHistory'])->name('ownership-history');

        // Product Updates
        Route::get('products', [ProductUpdateController::class, 'index'])->name('products.index');
        Route::post('products', [ProductUpdateController::class, 'store'])->name('products.store');
        Route::delete('products/{productUpdate}', [ProductUpdateController::class, 'destroy'])->name('products.destroy');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::put('payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

        // Ad Campaigns
        Route::get('ads', [AdCampaignController::class, 'index'])->name('ads.index');
        Route::post('ads', [AdCampaignController::class, 'store'])->name('ads.store');
        Route::get('ads/{campaign}', [AdCampaignController::class, 'show'])->name('ads.show');
        Route::put('ads/{campaign}', [AdCampaignController::class, 'update'])->name('ads.update');
        Route::delete('ads/{campaign}', [AdCampaignController::class, 'destroy'])->name('ads.destroy');
        Route::post('ads/{campaign}/assign', [AdCampaignController::class, 'assign'])->name('ads.assign');
        Route::post('ads/{campaign}/reports', [AdCampaignController::class, 'storeReport'])->name('ads.reports.store');
        Route::delete('ads/{campaign}/reports/{report}', [AdCampaignController::class, 'destroyReport'])->name('ads.reports.destroy');

        // Brands (ad-campaign grouping)
        Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

        // Notes
        Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
        Route::post('notes', [NoteController::class, 'store'])->name('notes.store');
        Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');

        // Documents (ClientDocument system)
        Route::get('documents',                                [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents',                               [DocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/{document}/preview',             [DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('documents/{document}/download',            [DocumentController::class, 'download'])->name('documents.download');
        Route::get('documents/{document}/versions',            [DocumentController::class, 'versions'])->name('documents.versions');
        Route::delete('documents/{document}',                  [DocumentController::class, 'destroy'])->name('documents.destroy');

        // Meetings
        Route::get('meetings',                                 [MeetingController::class, 'index'])->name('meetings.index');
        Route::post('meetings',                                [MeetingController::class, 'store'])->name('meetings.store');
        Route::put('meetings/{meeting}',                       [MeetingController::class, 'update'])->name('meetings.update');
        Route::delete('meetings/{meeting}',                    [MeetingController::class, 'destroy'])->name('meetings.destroy');
        Route::post('meetings/{meeting}/complete',             [MeetingController::class, 'complete'])->name('meetings.complete');
        Route::post('meetings/{meeting}/force-complete',       [MeetingController::class, 'forceComplete'])->name('meetings.force-complete');
        Route::post('meetings/{meeting}/cancel',               [MeetingController::class, 'cancel'])->name('meetings.cancel');
        Route::post('meetings/{meeting}/no-show',              [MeetingController::class, 'noShow'])->name('meetings.no-show');
        Route::post('meetings/{meeting}/regenerate-link',      [MeetingController::class, 'regenerateLink'])->name('meetings.regenerate-link');

        // Client Portal — project updates, action requests, approval requests, invoices, portal accounts
        Route::get('project-updates', [ProjectUpdateController::class, 'index'])->name('project-updates.index');
        Route::post('project-updates', [ProjectUpdateController::class, 'store'])->name('project-updates.store');
        Route::delete('project-updates/{update}', [ProjectUpdateController::class, 'destroy'])->name('project-updates.destroy');

        Route::get('action-requests', [ClientActionRequestController::class, 'index'])->name('action-requests.index');
        Route::post('action-requests', [ClientActionRequestController::class, 'store'])->name('action-requests.store');
        Route::post('action-requests/{actionRequest}/review', [ClientActionRequestController::class, 'review'])->name('action-requests.review');

        Route::get('approval-requests', [ClientApprovalRequestController::class, 'index'])->name('approval-requests.index');
        Route::post('approval-requests', [ClientApprovalRequestController::class, 'store'])->name('approval-requests.store');
        Route::get('approval-requests/{approvalRequest}', [ClientApprovalRequestController::class, 'show'])->name('approval-requests.show');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        Route::get('portal-accounts', [ClientPortalAccountController::class, 'index'])->name('portal-accounts.index');
        Route::post('portal-accounts', [ClientPortalAccountController::class, 'store'])->name('portal-accounts.store');
        Route::post('portal-accounts/{portalAccount}/status', [ClientPortalAccountController::class, 'status'])->name('portal-accounts.status');
        Route::post('portal-accounts/{portalAccount}/reset-password', [ClientPortalAccountController::class, 'resetPassword'])->name('portal-accounts.reset-password');
    });

    // Workflow stage management (admin)
    Route::resource('workflow', WorkflowController::class)->except(['create', 'edit', 'show'])->parameters(['workflow' => 'stage']);
    Route::post('workflow/reorder', [WorkflowController::class, 'reorder'])->name('workflow.reorder');
    Route::post('workflow/{stage}/merge', [WorkflowController::class, 'merge'])->name('workflow.merge');

    // Import
    Route::get('import', [ImportController::class, 'index'])->name('import.index');
    Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
    Route::post('import', [ImportController::class, 'store'])->name('import.store');
    Route::get('import/{log}', [ImportController::class, 'show'])->name('import.show');
    Route::post('import/{log}/rollback', [ImportController::class, 'rollback'])->name('import.rollback');

    // File Manager
    Route::prefix('file-manager')->name('file-manager.')->group(function () {
        Route::get('/',        [FileManagerController::class, 'index'])->name('index');
        Route::get('list',     [FileManagerController::class, 'list'])->name('list');
        Route::get('download', [FileManagerController::class, 'download'])->name('download');
        Route::get('preview',  [FileManagerController::class, 'preview'])->name('preview');
        Route::post('folder',  [FileManagerController::class, 'createFolder'])->name('folder.create');
        Route::post('upload',  [FileManagerController::class, 'upload'])->name('upload');
        Route::post('rename',  [FileManagerController::class, 'rename'])->name('rename');
        Route::delete('/',     [FileManagerController::class, 'destroy'])->name('destroy');
    });

    // Performance / KPI scoreboard (view performance — enforced in the controller)
    Route::get('performance', [PerformanceController::class, 'index'])->name('performance.index');

    // Performance configuration (manage performance — enforced in the controller).
    // Registered before performance/{user} so /performance/config isn't captured by the wildcard.
    Route::get('performance/config', [PerformanceConfigController::class, 'index'])->name('performance.config');
    Route::post('performance/config/targets', [PerformanceConfigController::class, 'storeTarget'])->name('performance.config.targets.store');
    Route::delete('performance/config/targets/{target}', [PerformanceConfigController::class, 'destroyTarget'])->name('performance.config.targets.destroy');
    Route::post('performance/config/weights', [PerformanceConfigController::class, 'storeWeight'])->name('performance.config.weights.store');
    Route::delete('performance/config/weights/{weight}', [PerformanceConfigController::class, 'destroyWeight'])->name('performance.config.weights.destroy');
    Route::post('performance/config/settings', [PerformanceConfigController::class, 'updateSettings'])->name('performance.config.settings');
    Route::post('performance/config/capacity', [PerformanceConfigController::class, 'updateCapacity'])->name('performance.config.capacity');

    // Historical scoreboard from persisted snapshots (before the {user} wildcard).
    Route::get('performance/history', [PerformanceController::class, 'history'])->name('performance.history');

    // Live workload board — active-task load vs. capacity (before the {user} wildcard).
    Route::get('performance/workload', [PerformanceController::class, 'workload'])->name('performance.workload');

    Route::get('performance/{user}', [PerformanceController::class, 'show'])->name('performance.show');

    // Reviews & Reports
    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::post('reviews/mine', [ReviewController::class, 'mine'])->name('reviews.mine');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Tasks (standalone)
    Route::resource('tasks', TaskController::class)->except(['create', 'edit']);
    Route::post('tasks/{task}/revisions', [TaskController::class, 'storeRevision'])->name('tasks.revisions.store');
    Route::post('tasks/{task}/comments', [TaskController::class, 'storeComment'])->name('tasks.comments.store');
    Route::delete('tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->name('tasks.comments.destroy');
    Route::post('tasks/{task}/attachments', [TaskController::class, 'storeAttachment'])->name('tasks.attachments.store');
    Route::get('tasks/{task}/attachments/{attachment}/download', [TaskController::class, 'downloadAttachment'])->name('tasks.attachments.download');
    Route::delete('tasks/{task}/attachments/{attachment}', [TaskController::class, 'destroyAttachment'])->name('tasks.attachments.destroy');

    // Employee requests (standalone)
    Route::resource('requests', EmployeeRequestController::class)
        ->only(['index', 'store', 'destroy'])
        ->parameters(['requests' => 'employeeRequest']);
    Route::post('requests/{employeeRequest}/respond', [EmployeeRequestController::class, 'respond'])->name('requests.respond');

    // Payments (standalone)
    Route::get('payments', [PaymentController::class, 'all'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'storeAny'])->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroyAny'])->name('payments.destroy');

    // Ad Campaigns (standalone)
    Route::get('ads', [AdCampaignController::class, 'all'])->name('ads.index');
    Route::post('ads', [AdCampaignController::class, 'storeAny'])->name('ads.store');
    Route::post('ads/{campaign}/assign', [AdCampaignController::class, 'assignAny'])->name('ads.assign');
    Route::delete('ads/{campaign}', [AdCampaignController::class, 'destroyAny'])->name('ads.destroy');

    // Meetings (standalone)
    Route::get('meetings/book', [MeetingController::class, 'bookForm'])->name('meetings.book');
    Route::post('meetings/book', [MeetingController::class, 'bookStore'])->name('meetings.book.store');
    Route::post('meetings/check-conflict', [MeetingController::class, 'checkConflict'])->name('meetings.check-conflict');
    Route::post('meetings/availability', [MeetingController::class, 'availability'])->name('meetings.availability');
    Route::get('meetings', [MeetingController::class, 'allMeetings'])->name('meetings.all');

    // Export
    Route::get('export/clients', [ExportController::class, 'clients'])->name('export.clients');

    // Global search
    Route::get('search', [SearchController::class, 'global'])->name('search.global');

    // Pending change approvals (Super Admin / Manager only — enforced in the controller)
    Route::get('pending-changes', [PendingChangeController::class, 'index'])->name('pending-changes.index');
    Route::post('pending-changes/{pendingChange}/approve', [PendingChangeController::class, 'approve'])->name('pending-changes.approve');
    Route::post('pending-changes/{pendingChange}/reject', [PendingChangeController::class, 'reject'])->name('pending-changes.reject');

    // Client Portal — staff-side review queues
    Route::get('payment-proofs', [PaymentProofController::class, 'index'])->name('payment-proofs.index');
    Route::post('payment-proofs/{proof}/verify', [PaymentProofController::class, 'verify'])->name('payment-proofs.verify');
    Route::post('payment-proofs/{proof}/reject', [PaymentProofController::class, 'reject'])->name('payment-proofs.reject');

    Route::get('correction-requests', [ClientCorrectionRequestController::class, 'index'])->name('correction-requests.index');
    Route::post('correction-requests/{correctionRequest}/respond', [ClientCorrectionRequestController::class, 'respond'])->name('correction-requests.respond');

    Route::get('support-tickets', [SupportTicketController::class, 'index'])->name('support-tickets.index');
    Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support-tickets.show');
    Route::post('support-tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])->name('support-tickets.reply');
    Route::post('support-tickets/{ticket}/assign', [SupportTicketController::class, 'assign'])->name('support-tickets.assign');
    Route::post('support-tickets/{ticket}/status', [SupportTicketController::class, 'status'])->name('support-tickets.status');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // Chat (Reverb realtime) — everyone can chat; monitoring gated by 'monitor chats' in the controller.
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('conversations', [ChatController::class, 'conversations'])->name('conversations');
        Route::get('users', [ChatController::class, 'users'])->name('users');

        // Monitoring — literal routes before the with/{user} + {conversation} wildcards.
        Route::get('monitor', [ChatController::class, 'monitor'])->name('monitor');
        Route::get('monitor/conversations', [ChatController::class, 'monitorConversations'])->name('monitor.conversations');
        Route::get('monitor/{conversation}', [ChatController::class, 'monitorShow'])->name('monitor.show');

        // Literal segment, so it must precede the with/{user} wildcard.
        Route::get('attachments/{message}', [ChatController::class, 'attachment'])->name('attachment');

        Route::get('with/{user}', [ChatController::class, 'open'])->name('open');
        Route::post('with/{user}', [ChatController::class, 'send'])->name('send');
        Route::post('{conversation}/read', [ChatController::class, 'read'])->name('read');

        Route::delete('messages/{message}', [ChatController::class, 'destroyMessage'])->name('messages.destroy');
        Route::post('messages/{message}/react', [ChatController::class, 'react'])->name('messages.react');
    });

    // ── 1:1 audio calls (Reverb signalling + peer-to-peer WebRTC audio) ──
    // Bound by uuid so a call id is never guessable from a sequential key.
    Route::prefix('calls')->name('calls.')->group(function () {
        Route::get('ice', [CallController::class, 'ice'])->name('ice');
        Route::get('history', [CallController::class, 'history'])->name('history');

        // Call spam is the abuse case worth rate limiting; ringing someone
        // repeatedly is disruptive in a way that answering is not.
        Route::post('to/{user}', [CallController::class, 'start'])
            ->middleware('throttle:15,1')->name('start');

        Route::post('{call:uuid}/accept', [CallController::class, 'accept'])->name('accept');
        Route::post('{call:uuid}/reject', [CallController::class, 'reject'])->name('reject');
        Route::post('{call:uuid}/end', [CallController::class, 'end'])->name('end');

        // ICE candidates arrive in bursts of dozens during negotiation, so this
        // ceiling is high on purpose — it is a runaway guard, not a throttle.
        Route::post('{call:uuid}/signal', [CallController::class, 'signal'])
            ->middleware('throttle:300,1')->name('signal');
    });

    // ── Generic workflow engine ──────────────────────────────────────────
    // Admin: build & track workflows (gated by 'manage workflows').
    Route::middleware('can:manage workflows')->prefix('workflows')->name('workflows.')->group(function () {
        Route::get('/', [FlowController::class, 'index'])->name('index');
        Route::post('/', [FlowController::class, 'store'])->name('store');
        Route::get('items', [FlowController::class, 'items'])->name('items'); // before {flow}
        Route::get('{flow}', [FlowController::class, 'show'])->name('show');
        Route::put('{flow}', [FlowController::class, 'update'])->name('update');
        Route::delete('{flow}', [FlowController::class, 'destroy'])->name('destroy');
        Route::post('{flow}/toggle', [FlowController::class, 'toggleActive'])->name('toggle');
        Route::post('{flow}/stages', [FlowController::class, 'storeStage'])->name('stages.store');
        Route::post('{flow}/reorder', [FlowController::class, 'reorderStages'])->name('reorder');
        Route::put('stages/{stage}', [FlowController::class, 'updateStage'])->name('stages.update');
        Route::delete('stages/{stage}', [FlowController::class, 'destroyStage'])->name('stages.destroy');
        Route::post('stages/{stage}/users', [FlowController::class, 'assignUsers'])->name('stages.users');
    });

    // User: personal queue + item actions (visibility enforced in FlowService).
    Route::get('my-queue', [FlowItemController::class, 'queue'])->name('flow.queue');
    Route::get('my-queue/history', [FlowItemController::class, 'history'])->name('flow.history');
    Route::post('flow-items', [FlowItemController::class, 'store'])->name('flow-items.store');
    Route::get('flow-items/{item}', [FlowItemController::class, 'show'])->name('flow-items.show');
    Route::get('flow-items/{item}/handoff', [FlowItemController::class, 'handoff'])->name('flow-items.handoff');
    Route::put('flow-items/{item}', [FlowItemController::class, 'updateItem'])->name('flow-items.update');
    Route::post('flow-items/{item}/claim', [FlowItemController::class, 'claim'])->name('flow-items.claim');
    Route::post('flow-items/{item}/release', [FlowItemController::class, 'release'])->name('flow-items.release');
    Route::post('flow-items/{item}/advance', [FlowItemController::class, 'advance'])->name('flow-items.advance');
    Route::post('flow-items/{item}/send-back', [FlowItemController::class, 'sendBack'])->name('flow-items.send-back');
    Route::post('flow-items/{item}/cancel', [FlowItemController::class, 'cancel'])->name('flow-items.cancel');
    Route::post('flow-items/{item}/comments', [FlowItemController::class, 'storeComment'])->name('flow-items.comments.store');
    Route::delete('flow-items/{item}/comments/{comment}', [FlowItemController::class, 'destroyComment'])->name('flow-items.comments.destroy');
    Route::post('flow-items/{item}/attachments', [FlowItemController::class, 'storeAttachment'])->name('flow-items.attachments.store');
    Route::get('flow-items/{item}/attachments/{attachment}/download', [FlowItemController::class, 'downloadAttachment'])->name('flow-items.attachments.download');
    Route::delete('flow-items/{item}/attachments/{attachment}', [FlowItemController::class, 'destroyAttachment'])->name('flow-items.attachments.destroy');

    // Settings
    Route::resource('categories', CategoryController::class)->except(['create', 'edit', 'show']);
    Route::resource('users', UserController::class)->only(['index', 'store', 'update']);

    // General settings (Super Admin only)
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingController::class, 'update'])->name('settings.update');

    // Roles & Permissions (Super Admin only)
    Route::resource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('roles/{role}/sync-permissions', [RoleController::class, 'syncPermissions'])->name('roles.sync-permissions');
    Route::post('roles/{role}/clone', [RoleController::class, 'clone'])->name('roles.clone');
    Route::resource('permissions', PermissionController::class)->only(['index', 'store', 'destroy']);
});

require __DIR__.'/portal.php';

