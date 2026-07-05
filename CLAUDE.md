# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Full first-time setup
composer setup

# Development (starts server + queue + logs + vite concurrently)
composer dev

# Run tests
composer test

# Single test file or filter
php artisan test --filter=ClientTest
php artisan test tests/Feature/ClientControllerTest.php

# Assets
npm run dev      # watch
npm run build    # production

# Migrations
php artisan migrate
php artisan migrate:fresh --seed   # reset + seed with roles, users, categories

# Tinker
php artisan tinker
```

Default seeded credentials: `admin@dfcp.com` / `password` (Super Admin).

---

## Architecture Overview

### Frontend Stack

**Pure Blade + jQuery — not Vue/Inertia.** Pages are server-rendered Blade templates enhanced with:
- **DataTables** (AJAX server-side) for all list views
- **Select2** for searchable dropdowns
- **SweetAlert2** for confirmation dialogs and toasts
- **Bootstrap 5** for layout/components
- **Bootstrap Icons** (`bi bi-*`) exclusively for icons
- Chart.js for dashboard charts

Assets are compiled via **Vite**. There is no TypeScript. CSS lives in `resources/scss/` (Tailwind CSS v4 + Sass) but most per-page styles are written as `<style>` blocks inside `@push('styles')`.

### Blade Layout

All authenticated pages extend `layouts/app.blade.php`:
```blade
@extends('layouts.app')
@section('title', 'Page Title')
@push('styles') ... @endpush
@section('content') ... @endsection
@push('scripts') ... @endpush
```

The layout injects `$appName`, `$themeColor`, and `$themeColorDark` globally via `@php` at the top.

### CSS / Theming

**Always use CSS custom properties, never Bootstrap contextual color classes like `bg-primary`, `text-danger`, etc. for custom UI.**

Light/dark mode is toggled by `data-theme="dark"` on `<html>`. The full variable set:

```css
/* Layout/color tokens */
--primary, --primary-dark, --primary-rgb
--bg, --surface, --surface2, --border
--text, --text2, --text3
--shadow-sm, --shadow-md, --shadow-lg
--radius (10px)
```

For charts, call `chartTheme()` (defined in `app.blade.php`) to get theme-aware colors.

Status pills use the `.spill` utility class with modifiers: `.spill-running`, `.spill-warning`, `.spill-completed`, `.spill-hold`, `.spill-cancelled`. Add new `.spill-{status}` variants in `app.blade.php` rather than using Bootstrap badges.

### Service / Repository Pattern

Business logic lives in `app/Services/`. Services are injected into controllers via constructor. The pattern:

```
Controller → Service → Repository → Eloquent
```

- **Services** handle transactions, activity logging, auth context (`Auth::id()`).
- **Repositories** provide query abstraction. New repositories must implement a contract interface in `app/Repositories/Contracts/` and be bound in `app/Providers/RepositoryServiceProvider.php`.
- **Controllers** call `$request->validated()` (via Form Requests) and delegate to the service.

Not every module needs a repository — simpler modules (payments, notes, meetings) have services that call Eloquent directly.

### Authorization

Two-layer authorization:

1. **Spatie permissions** (string-based): `view clients`, `manage clients`, `delete clients`, etc. Check in blade with `@can('manage clients')` and in policies with `$user->hasPermissionTo(...)`.
2. **Policies** registered in `AppServiceProvider`. `Gate::before` gives Super Admins unrestricted access.

To add a new module's permissions: add them to `DatabaseSeeder::$permissions`, assign to appropriate roles, and create a Policy class registered in `AppServiceProvider::boot()`.

### Activity Logging

Inject `ActivityLogService` into any service and call:
```php
$this->activityLog->log(
    module: 'Client',
    action: 'Status Changed',
    clientId: $client->id,
    oldValue: $old,   // scalar or array — auto JSON-encoded if array
    newValue: $new
);
```

`ActivityLog` stores `module`, `action`, `client_id`, `user_id`, `old_value`, `new_value`, `ip_address`, `browser`. There is no generic `subject_type`/`subject_id` polymorphism — `client_id` is a direct FK.

### DataTables Integration

List pages use Yajra DataTables. The controller pattern:

```php
public function index(Request $request)
{
    if ($request->ajax()) {
        return $this->dataTable($request);  // private method
    }
    return view('module.index', [...]);
}

private function dataTable(Request $request): JsonResponse
{
    $query = Model::query()->with([...]);
    // apply filters from $request
    return DataTables::of($query)
        ->addColumn('col_name', fn ($row) => '...html...')
        ->rawColumns(['col_name'])
        ->make(true);
}
```

In Blade, initialize with `$('#table').DataTable({ ajax: { url: '' }, serverSide: true, ... })`.

### Forms and Validation

Use Form Request classes in `app/Http/Requests/{Module}/`. AJAX responses return JSON; full-page forms redirect with `->with('success', '...')`. The controller checks `$request->expectsJson()` or `$request->ajax()` where both flows must be supported.

### Routing Convention

All routes live in `routes/web.php` under the `auth` middleware group. Nested resources use `prefix('clients/{client}')->name('clients.')` grouping. The pattern for sub-resources:

```php
Route::prefix('clients/{client}')->name('clients.')->group(function () {
    Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
    Route::post('notes', [NoteController::class, 'store'])->name('notes.store');
    // ...
});
```

Standalone top-level modules (`tasks`, `meetings`, etc.) are registered at root level.

### Models

- All models use `SoftDeletes` for deletable records.
- Accessors follow the `get{Name}Attribute` pattern (Laravel 9 style, not the newer `Attribute::make` API).
- Scopes (`scopeSearch`, `scopeStatus`) are used for reusable query filters.
- External URLs must be normalized through a `get{Field}UrlAttribute` accessor that prepends `https://` when no protocol is present.
- `Client::$statuses` and similar static arrays on models define valid enum-like values used in both validation and views.

### Queue

Queue driver is `database`. Long-running operations (exports, imports, notifications) should be dispatched as jobs. The dev command starts `php artisan queue:listen` automatically.

### Dashboard Caching

Dashboard chart queries are cached with `Cache::remember('dash.{key}', 600, fn () => ...)`. If a new module's data affects existing charts, flush those keys in the model's `booted()` hook alongside the existing `Cache::forget` calls.
