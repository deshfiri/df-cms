<?php

use App\Http\Controllers\Portal\Auth\PortalForgotPasswordController;
use App\Http\Controllers\Portal\Auth\PortalLoginController;
use App\Http\Controllers\Portal\Auth\PortalResetPasswordController;
use App\Http\Controllers\Portal\PortalActionRequestController;
use App\Http\Controllers\Portal\PortalApprovalController;
use App\Http\Controllers\Portal\PortalCorrectionRequestController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalJourneyController;
use App\Http\Controllers\Portal\PortalNotificationController;
use App\Http\Controllers\Portal\PortalPaymentProofController;
use App\Http\Controllers\Portal\PortalProfileController;
use App\Http\Controllers\Portal\PortalServiceController;
use App\Http\Controllers\Portal\PortalSupportTicketController;
use App\Http\Controllers\Portal\PortalUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest:client_portal'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('login', [PortalLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [PortalLoginController::class, 'login'])
        ->middleware('throttle:client-portal-login')
        ->name('login.submit');

    Route::get('forgot-password', [PortalForgotPasswordController::class, 'showLinkRequestForm'])
        ->name('password.request');
    Route::post('forgot-password', [PortalForgotPasswordController::class, 'sendResetLinkEmail'])
        ->name('password.email');

    Route::get('reset-password/{token}', [PortalResetPasswordController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('reset-password', [PortalResetPasswordController::class, 'reset'])
        ->name('password.update');
});

Route::middleware(['auth:client_portal', 'portal.active'])->prefix('portal')->name('portal.')->group(function () {
    Route::post('logout', [PortalLoginController::class, 'logout'])->name('logout');

    Route::get('dashboard', [PortalDashboardController::class, 'index'])->name('dashboard');

    Route::get('journey', [PortalJourneyController::class, 'index'])->name('journey');

    Route::get('services', [PortalServiceController::class, 'index'])->name('services.index');

    Route::get('updates', [PortalUpdateController::class, 'index'])->name('updates.index');
    Route::get('updates/{update}', [PortalUpdateController::class, 'show'])->name('updates.show');

    Route::get('documents', [PortalDocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [PortalDocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/download', [PortalDocumentController::class, 'download'])->name('documents.download');
    Route::get('documents/{document}/preview', [PortalDocumentController::class, 'preview'])->name('documents.preview');

    Route::get('invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/download', [PortalInvoiceController::class, 'download'])->name('invoices.download');
    Route::post('invoices/{invoice}/payment-proof', [PortalPaymentProofController::class, 'store'])->name('invoices.payment-proof.store');

    Route::get('actions', [PortalActionRequestController::class, 'index'])->name('actions.index');
    Route::get('actions/{actionRequest}', [PortalActionRequestController::class, 'show'])->name('actions.show');
    Route::post('actions/{actionRequest}/submit', [PortalActionRequestController::class, 'submit'])->name('actions.submit');

    Route::get('approvals', [PortalApprovalController::class, 'index'])->name('approvals.index');
    Route::get('approvals/{approvalRequest}', [PortalApprovalController::class, 'show'])->name('approvals.show');
    Route::post('approvals/{approvalRequest}/respond', [PortalApprovalController::class, 'respond'])->name('approvals.respond');

    Route::get('information', [PortalCorrectionRequestController::class, 'index'])->name('information.index');
    Route::post('information/correction-requests', [PortalCorrectionRequestController::class, 'store'])->name('correction-requests.store');

    Route::get('support', [PortalSupportTicketController::class, 'index'])->name('support.index');
    Route::post('support', [PortalSupportTicketController::class, 'store'])->name('support.store');
    Route::get('support/{ticket}', [PortalSupportTicketController::class, 'show'])->name('support.show');
    Route::post('support/{ticket}/reply', [PortalSupportTicketController::class, 'reply'])->name('support.reply');

    Route::get('notifications', [PortalNotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [PortalNotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [PortalNotificationController::class, 'markAllRead'])->name('notifications.read-all');

    Route::get('profile', [PortalProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile/password', [PortalProfileController::class, 'updatePassword'])->name('profile.password');
});
