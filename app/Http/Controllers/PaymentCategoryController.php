<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Payment Categories: what a client can be billed for.
 *
 * Held by whoever records money ("manage payments"), not just Super Admin —
 * Accounts are the people who know a new line of business needs its own bucket.
 */
class PaymentCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeManage($request);

        $categories = PaymentCategory::ordered()
            ->withCount(['invoices', 'payments'])
            ->get();

        $received = Payment::where('status', 'Paid')
            ->selectRaw('payment_category_id, SUM(amount) as total')
            ->groupBy('payment_category_id')
            ->pluck('total', 'payment_category_id');

        $billed = Invoice::where('status', '!=', Invoice::STATUS_CANCELLED)
            ->selectRaw('payment_category_id, SUM(total_payable) as total')
            ->groupBy('payment_category_id')
            ->pluck('total', 'payment_category_id');

        return view('settings.payment-categories', compact('categories', 'received', 'billed'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('payment_categories', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $data['sort_order'] ??= (int) PaymentCategory::max('sort_order') + 10;

        $category = PaymentCategory::create($data + ['is_active' => true]);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function update(Request $request, PaymentCategory $paymentCategory): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:100', Rule::unique('payment_categories', 'name')->ignore($paymentCategory->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order'  => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $paymentCategory->update($data);

        return response()->json(['success' => true, 'data' => $paymentCategory->fresh()]);
    }

    /** Only a category nothing was ever billed or paid under can go; the rest are archived. */
    public function destroy(Request $request, PaymentCategory $paymentCategory): JsonResponse
    {
        $this->authorizeManage($request);

        if ($paymentCategory->isInUse()) {
            return response()->json([
                'message' => "\"{$paymentCategory->name}\" already has charges or payments. Deactivate it instead — past records keep their category.",
            ], 422);
        }

        $paymentCategory->delete();

        return response()->json(['success' => true]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('manage payments'), 403);
    }
}
