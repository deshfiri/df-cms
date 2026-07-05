<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Permissions ────────────────────────────────────────────────────
        $permissions = [
            'view clients', 'manage clients', 'delete clients',
            'manage payments', 'view payments',
            'manage products', 'manage documents',
            'manage-workflow', 'submit-stage', 'approve-stage',
            'import clients', 'export clients',
            'manage users', 'manage categories',
            'view reports',
            'view tasks', 'manage tasks',
            'manage-meetings',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Stale duplicate permission from before the manage-workflow naming fix.
        Permission::where('name', 'manage workflow')->delete();

        // ── Roles ──────────────────────────────────────────────────────────
        // Department roles map directly to workflow_stages.department so a
        // stage can only be submitted/approved by its owning team (see
        // WorkflowService). Super Admin bypasses this via Gate::before.
        $roles = [
            'Super Admin' => $permissions,
            'Manager'     => ['view clients', 'manage clients', 'delete clients', 'manage payments', 'view payments', 'manage products', 'manage documents', 'manage-workflow', 'approve-stage', 'import clients', 'export clients', 'view reports', 'view tasks', 'manage tasks', 'manage-meetings'],
            'Sales'       => ['view clients', 'manage clients', 'submit-stage', 'approve-stage', 'view tasks', 'manage tasks', 'manage-meetings'],
            'Document'    => ['view clients', 'manage documents', 'submit-stage', 'approve-stage', 'view tasks'],
            'Design'      => ['view clients', 'manage documents', 'submit-stage', 'approve-stage', 'view tasks'],
            'Website'     => ['view clients', 'submit-stage', 'approve-stage', 'view tasks'],
            'Product'     => ['view clients', 'manage products', 'submit-stage', 'approve-stage', 'view tasks'],
            'Marketing'   => ['view clients', 'manage clients', 'manage products', 'manage documents', 'export clients', 'submit-stage', 'approve-stage', 'view tasks'],
            'Support'     => ['view clients', 'manage clients', 'submit-stage', 'approve-stage', 'view tasks', 'manage tasks'],
            'Accounts'    => ['view clients', 'manage payments', 'view payments', 'export clients', 'view reports'],
            'Content'     => ['view clients', 'manage documents'],
            'Viewer'      => ['view clients', 'view payments', 'view reports'],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePerms);
        }

        // ── Super Admin User ────────────────────────────────────────────────
        $admin = User::firstOrCreate(['email' => 'admin@dfcp.com'], [
            'name'      => 'Super Admin',
            'password'  => Hash::make('password'),
            'is_active' => true,
        ]);
        $admin->assignRole('Super Admin');

        // ── Sample Users ───────────────────────────────────────────────────
        $sampleUsers = [
            ['name' => 'Manager User',    'email' => 'manager@dfcp.com',   'role' => 'Manager'],
            ['name' => 'Sales User',      'email' => 'sales@dfcp.com',     'role' => 'Sales'],
            ['name' => 'Document User',   'email' => 'document@dfcp.com',  'role' => 'Document'],
            ['name' => 'Design User',     'email' => 'design@dfcp.com',    'role' => 'Design'],
            ['name' => 'Website User',    'email' => 'website@dfcp.com',   'role' => 'Website'],
            ['name' => 'Product User',    'email' => 'product@dfcp.com',   'role' => 'Product'],
            ['name' => 'Marketing User',  'email' => 'marketing@dfcp.com', 'role' => 'Marketing'],
            ['name' => 'Support User',    'email' => 'support@dfcp.com',   'role' => 'Support'],
            ['name' => 'Accounts User',   'email' => 'accounts@dfcp.com',  'role' => 'Accounts'],
        ];

        foreach ($sampleUsers as $u) {
            $user = User::firstOrCreate(['email' => $u['email']], [
                'name'      => $u['name'],
                'password'  => Hash::make('password'),
                'is_active' => true,
            ]);
            $user->assignRole($u['role']);
        }

        // ── Categories ─────────────────────────────────────────────────────
        $categories = [
            'Gadgets', 'Skincare', 'Educational Toys', 'Kids', 'Household',
            'Kitchen & Home', 'Kids and Womens', 'HyperMarket', 'Clothing',
            'Women Skincare Apparel', 'Mobile Accessories & Gadgets', 'Baby Care',
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($cat)],
                ['name' => $cat, 'status' => true]
            );
        }

        // ── Workflow Stages (fixed 19-step client pipeline) ─────────────────
        $stages = [
            ['code' => 'deal_completed',            'name' => 'Client Deal Completed',          'department' => 'Sales',     'requires_approval' => false],
            ['code' => 'meeting_scheduled',          'name' => 'Meeting Scheduled',               'department' => 'Sales',     'requires_approval' => true],
            ['code' => 'agreement_signed',           'name' => 'Agreement Signed',                'department' => 'Sales',     'requires_approval' => true],
            ['code' => 'documents_collected',        'name' => 'Client Documents Collected',      'department' => 'Document',  'requires_approval' => true],
            ['code' => 'business_info_submitted',    'name' => 'Business Information Submitted',  'department' => 'Document',  'requires_approval' => true],
            ['code' => 'brand_name_finalized',       'name' => 'Brand Name Finalized',            'department' => 'Design',    'requires_approval' => true],
            ['code' => 'logo_design',                'name' => 'Logo Design',                     'department' => 'Design',    'requires_approval' => true],
            ['code' => 'banner_design',              'name' => 'Banner Design',                   'department' => 'Design',    'requires_approval' => true],
            ['code' => 'website_development',        'name' => 'Website Development',             'department' => 'Website',   'requires_approval' => true],
            ['code' => 'website_approved',           'name' => 'Website Approved',                'department' => 'Website',   'requires_approval' => true],
            ['code' => 'product_sourcing',           'name' => 'Product Sourcing',                'department' => 'Product',   'requires_approval' => true],
            ['code' => 'product_upload',             'name' => 'Product Upload',                  'department' => 'Product',   'requires_approval' => true],
            ['code' => 'facebook_page_setup',        'name' => 'Facebook Page Setup',             'department' => 'Marketing', 'requires_approval' => true],
            ['code' => 'marketing_content_creation', 'name' => 'Marketing Content Creation',      'department' => 'Marketing', 'requires_approval' => true],
            ['code' => 'video_content_creation',     'name' => 'Video Content Creation',          'department' => 'Marketing', 'requires_approval' => true],
            ['code' => 'marketing_launch',           'name' => 'Marketing Launch',                'department' => 'Marketing', 'requires_approval' => true],
            ['code' => 'ongoing_support',            'name' => 'Ongoing Support',                 'department' => 'Support',   'requires_approval' => false],
            ['code' => 'client_active',              'name' => 'Client Active',                   'department' => 'Support',   'requires_approval' => true],
            ['code' => 'deal_closed',                'name' => 'Deal Closed',                     'department' => 'Admin',     'requires_approval' => true],
        ];

        foreach ($stages as $i => $stage) {
            // withTrashed() — a stage retired via merge is soft-deleted, not gone;
            // it must not collide with the unique `code` column on reseed.
            WorkflowStage::withTrashed()->firstOrCreate(
                ['code' => $stage['code']],
                [
                    'name'              => $stage['name'],
                    'department'        => $stage['department'],
                    'requires_approval' => $stage['requires_approval'],
                    'sort_order'        => $i + 1,
                    'status'            => true,
                ]
            );
        }
    }
}
