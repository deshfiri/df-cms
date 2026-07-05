<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductUpdateController;
use App\Http\Controllers\RoleController;
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

    // Nested sub-resources under client
    Route::prefix('clients/{client}')->name('clients.')->group(function () {

        // Workflow
        Route::get('timeline', [WorkflowController::class, 'timeline'])->name('timeline');
        Route::post('stages/toggle', [WorkflowController::class, 'toggleStage'])->name('stages.toggle');
        Route::post('stages/submit', [WorkflowController::class, 'submitStage'])->name('stages.submit');
        Route::post('stages/approve', [WorkflowController::class, 'approveStage'])->name('stages.approve');
        Route::post('stages/reject', [WorkflowController::class, 'rejectStage'])->name('stages.reject');

        // Activity Log
        Route::get('activity', [ClientController::class, 'activity'])->name('activity');

        // Product Updates
        Route::get('products', [ProductUpdateController::class, 'index'])->name('products.index');
        Route::post('products', [ProductUpdateController::class, 'store'])->name('products.store');
        Route::delete('products/{productUpdate}', [ProductUpdateController::class, 'destroy'])->name('products.destroy');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::put('payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

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

    // Tasks (standalone)
    Route::resource('tasks', TaskController::class)->except(['create', 'edit']);
    Route::post('tasks/{task}/comments', [TaskController::class, 'storeComment'])->name('tasks.comments.store');
    Route::delete('tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->name('tasks.comments.destroy');
    Route::post('tasks/{task}/attachments', [TaskController::class, 'storeAttachment'])->name('tasks.attachments.store');
    Route::get('tasks/{task}/attachments/{attachment}/download', [TaskController::class, 'downloadAttachment'])->name('tasks.attachments.download');
    Route::delete('tasks/{task}/attachments/{attachment}', [TaskController::class, 'destroyAttachment'])->name('tasks.attachments.destroy');

    // Meetings (standalone)
    Route::get('meetings/book', [MeetingController::class, 'bookForm'])->name('meetings.book');
    Route::post('meetings/book', [MeetingController::class, 'bookStore'])->name('meetings.book.store');
    Route::post('meetings/check-conflict', [MeetingController::class, 'checkConflict'])->name('meetings.check-conflict');
    Route::get('meetings', [MeetingController::class, 'allMeetings'])->name('meetings.all');

    // Export
    Route::get('export/clients', [ExportController::class, 'clients'])->name('export.clients');

    // Global search
    Route::get('search', [SearchController::class, 'global'])->name('search.global');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

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

