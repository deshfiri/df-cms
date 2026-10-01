<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The public, unauthenticated landing page (Phase 4). Strict data boundary:
 * only `is_public` + `is_active` Brands/Products, selected columns only —
 * never Client, Invoice, Payment, AdvertisingExpenditure, BrandChecklist,
 * ContentItem, submission/collection/publishing-review, or ActivityLog data.
 */
class LandingController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $brands = Brand::public()->active()
            ->get(['id', 'name', 'slug', 'logo', 'description']);

        return view('public.landing', ['brands' => $brands]);
    }

    public function brand(Brand $brand): View
    {
        abort_unless($brand->is_public && $brand->is_active, 404);

        $products = $brand->products()->public()->active()
            ->get(['id', 'brand_id', 'name', 'description', 'image']);

        return view('public.brand', [
            'brand'    => $brand->only(['id', 'name', 'slug', 'logo', 'website', 'description']),
            'products' => $products,
        ]);
    }
}
