<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $stages = [
        ['code' => 'deal_completed',              'name' => 'Client Deal Completed',        'department' => 'Sales',     'requires_approval' => false],
        ['code' => 'meeting_scheduled',            'name' => 'Meeting Scheduled',             'department' => 'Sales',     'requires_approval' => true],
        ['code' => 'agreement_signed',             'name' => 'Agreement Signed',              'department' => 'Sales',     'requires_approval' => true],
        ['code' => 'documents_collected',          'name' => 'Client Documents Collected',    'department' => 'Document',  'requires_approval' => true],
        ['code' => 'business_info_submitted',      'name' => 'Business Information Submitted','department' => 'Document', 'requires_approval' => true],
        ['code' => 'brand_name_finalized',         'name' => 'Brand Name Finalized',          'department' => 'Design',    'requires_approval' => true],
        ['code' => 'logo_design',                  'name' => 'Logo Design',                   'department' => 'Design',    'requires_approval' => true],
        ['code' => 'banner_design',                'name' => 'Banner Design',                 'department' => 'Design',    'requires_approval' => true],
        ['code' => 'website_development',          'name' => 'Website Development',           'department' => 'Website',   'requires_approval' => true],
        ['code' => 'website_approved',             'name' => 'Website Approved',              'department' => 'Website',   'requires_approval' => true],
        ['code' => 'product_sourcing',             'name' => 'Product Sourcing',              'department' => 'Product',   'requires_approval' => true],
        ['code' => 'product_upload',               'name' => 'Product Upload',                'department' => 'Product',   'requires_approval' => true],
        ['code' => 'facebook_page_setup',          'name' => 'Facebook Page Setup',           'department' => 'Marketing', 'requires_approval' => true],
        ['code' => 'marketing_content_creation',   'name' => 'Marketing Content Creation',    'department' => 'Marketing', 'requires_approval' => true],
        ['code' => 'video_content_creation',       'name' => 'Video Content Creation',        'department' => 'Marketing','requires_approval' => true],
        ['code' => 'marketing_launch',             'name' => 'Marketing Launch',               'department' => 'Marketing','requires_approval' => true],
        ['code' => 'ongoing_support',              'name' => 'Ongoing Support',                'department' => 'Support',  'requires_approval' => false],
        ['code' => 'client_active',                'name' => 'Client Active',                  'department' => 'Support',  'requires_approval' => true],
        ['code' => 'deal_closed',                  'name' => 'Deal Closed',                    'department' => 'Admin',    'requires_approval' => true],
    ];

    public function up(): void
    {
        // Replace the old 6 placeholder stages with the fixed 19-step pipeline.
        // Any progress recorded against the placeholder stages is not transferable
        // since the stage identities are entirely different.
        DB::table('client_stage_progress')->delete();
        DB::table('workflow_stages')->delete();

        $now = now();
        foreach ($this->stages as $i => $stage) {
            DB::table('workflow_stages')->insert([
                'name'              => $stage['name'],
                'code'              => $stage['code'],
                'department'        => $stage['department'],
                'requires_approval' => $stage['requires_approval'],
                'sort_order'        => $i + 1,
                'status'            => true,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        $stageIds = DB::table('workflow_stages')->orderBy('sort_order')->pluck('id');
        $clientIds = DB::table('clients')->whereNull('deleted_at')->pluck('id');

        $rows = [];
        foreach ($clientIds as $clientId) {
            foreach ($stageIds as $stageId) {
                $rows[] = [
                    'client_id'   => $clientId,
                    'stage_id'    => $stageId,
                    'status'      => 'Pending',
                    'is_completed'=> false,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('client_stage_progress')->insert($chunk);
        }
    }

    public function down(): void
    {
        $codes = array_column($this->stages, 'code');
        DB::table('client_stage_progress')->whereIn('stage_id', function ($q) use ($codes) {
            $q->select('id')->from('workflow_stages')->whereIn('code', $codes);
        })->delete();
        DB::table('workflow_stages')->whereIn('code', $codes)->delete();
    }
};
