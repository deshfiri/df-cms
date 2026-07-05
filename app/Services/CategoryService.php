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
        private readonly ChangeApprovalService $changeApproval,
    ) {}

    public function update(Category $category, array $data): Category
    {
        $payload = [
            'name'   => $data['name'],
            'slug'   => Str::slug($data['name']),
            'status' => $data['status'] ?? $category->status,
        ];

        $this->changeApproval->guard(Category::class, $category->id, $category->only(array_keys($payload)), $payload, Auth::user());

        return DB::transaction(function () use ($category, $payload) {
            $old = $category->only(array_keys($payload));
            $category->update($payload);
            $this->activityLog->log('Category', 'Updated', null, $old, $payload);

            return $category->fresh();
        });
    }
}
