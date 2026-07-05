<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('icon', 60)->default('bi-file-earmark');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $defaults = [
            ['name' => 'Agreement',        'slug' => 'agreement',        'icon' => 'bi-file-earmark-text',    'is_required' => 1, 'sort_order' => 1],
            ['name' => 'Signed Agreement', 'slug' => 'signed-agreement', 'icon' => 'bi-file-earmark-check',  'is_required' => 0, 'sort_order' => 2],
            ['name' => 'Invoice',          'slug' => 'invoice',          'icon' => 'bi-receipt',              'is_required' => 0, 'sort_order' => 3],
            ['name' => 'Money Receipt',    'slug' => 'money-receipt',    'icon' => 'bi-cash-stack',           'is_required' => 0, 'sort_order' => 4],
            ['name' => 'Payment Slip',     'slug' => 'payment-slip',     'icon' => 'bi-credit-card',          'is_required' => 0, 'sort_order' => 5],
            ['name' => 'Brand Logo',       'slug' => 'brand-logo',       'icon' => 'bi-badge',                'is_required' => 0, 'sort_order' => 6],
            ['name' => 'Brand Assets',     'slug' => 'brand-assets',     'icon' => 'bi-palette',              'is_required' => 0, 'sort_order' => 7],
            ['name' => 'Product Images',   'slug' => 'product-images',   'icon' => 'bi-image',                'is_required' => 0, 'sort_order' => 8],
            ['name' => 'Product Videos',   'slug' => 'product-videos',   'icon' => 'bi-camera-video',         'is_required' => 0, 'sort_order' => 9],
            ['name' => 'Trade License',    'slug' => 'trade-license',    'icon' => 'bi-building',             'is_required' => 0, 'sort_order' => 10],
            ['name' => 'TIN',              'slug' => 'tin',              'icon' => 'bi-file-earmark-medical', 'is_required' => 0, 'sort_order' => 11],
            ['name' => 'BIN',              'slug' => 'bin',              'icon' => 'bi-file-earmark-medical', 'is_required' => 0, 'sort_order' => 12],
            ['name' => 'NID',              'slug' => 'nid',              'icon' => 'bi-person-badge',         'is_required' => 0, 'sort_order' => 13],
            ['name' => 'Meeting Notes',    'slug' => 'meeting-notes',    'icon' => 'bi-journal-text',         'is_required' => 0, 'sort_order' => 14],
            ['name' => 'Courier Receipt',  'slug' => 'courier-receipt',  'icon' => 'bi-truck',                'is_required' => 0, 'sort_order' => 15],
            ['name' => 'Screenshots',      'slug' => 'screenshots',      'icon' => 'bi-display',              'is_required' => 0, 'sort_order' => 16],
            ['name' => 'Other',            'slug' => 'other',            'icon' => 'bi-file-earmark',         'is_required' => 0, 'sort_order' => 99],
        ];

        foreach ($defaults as $row) {
            DB::table('document_types')->insert(array_merge($row, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
