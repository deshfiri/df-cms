<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryService
{
    public function __construct(
        private readonly ActivityLogService    $activityLog,
    ) {}

    public function update(Category $category, array $data): Category
    {
        $payload = [
            'name'   => $data['name'],
            'slug'   => Str::slug($data['name']),
            'status' => $data['status'] ?? $category->status,
        ];

        // Not gated by change approval: categories are settings data, and the
        // people who can reach this screen are already trusted with it.
        return DB::transaction(function () use ($category, $payload) {
            $old = $category->only(array_keys($payload));
            $category->update($payload);
            $this->activityLog->log('Category', 'Updated', null, $old, $payload);

            return $category->fresh();
        });
    }
}
