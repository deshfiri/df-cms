<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Refunds: money given back against a payment, requested, approved by someone
 * else, then paid out and confirmed with a reference.
 *
 * refunds holds where each one stands; refund_events is its append-only history
 * — every state it passed through, who moved it, when and why. Neither is ever
 * deleted: a refund that should not happen is rejected or cancelled.
 *
 * A payment with refunds against it cannot be deleted (restrictOnDelete), so
 * no refund can outlive the money it returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 30)->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // The charge the payment was taken against, when there was one.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('status', 20)->default('requested')
                ->comment('requested,under_review,approved,rejected,processing,completed,cancelled');
            $table->string('method', 100)->nullable();
            $table->string('reference', 150)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['invoice_id', 'status']);
            $table->index('status');
        });

        Schema::create('refund_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['refund_id', 'id']);
        });

        // Who may do what. Requesting and paying out sit with whoever handles
        // money; deciding sits with management. Additive only.
        $grants = [
            'request refunds' => ['Manager', 'Accounts'],
            'approve refunds' => ['Manager'],
            'process refunds' => ['Manager', 'Accounts'],
        ];
        foreach ($grants as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach (array_merge(['Super Admin'], $roles) as $roleName) {
                Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_events');
        Schema::dropIfExists('refunds');
        Permission::whereIn('name', ['request refunds', 'approve refunds', 'process refunds'])->delete();
    }
};
