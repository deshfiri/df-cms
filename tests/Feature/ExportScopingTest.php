<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage clients', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    }

    private function makeClient(?int $assignedTo, string $name): Client
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF' . uniqid(),
            'client_name' => $name,
            'brand_name'  => $name,
            'category_id' => $category->id,
            'assigned_to' => $assignedTo,
        ]);
    }

    private function readNames(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        unlink($path);

        return array_column(array_slice($rows, 1), 2); // column C = Client Name
    }

    public function test_a_user_without_view_permission_cannot_export(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('export.clients', ['format' => 'csv']));

        $response->assertForbidden();
    }

    public function test_export_is_scoped_to_own_assigned_and_unassigned_clients(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view clients');
        $otherOwner = User::factory()->create();

        $this->makeClient($viewer->id, 'Mine Client');
        $this->makeClient(null, 'Unassigned Client');
        $this->makeClient($otherOwner->id, 'Someone Elses Client');

        $response = $this->actingAs($viewer)->get(route('export.clients', ['format' => 'csv']));
        $response->assertOk();

        $path = storage_path('app/tmp_export_test.csv');
        file_put_contents($path, $response->streamedContent());
        $names = $this->readNames($path);

        $this->assertContains('Mine Client', $names);
        $this->assertContains('Unassigned Client', $names);
        $this->assertNotContains('Someone Elses Client', $names);
    }

    public function test_export_by_explicit_ids_is_also_scoped(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view clients');
        $otherOwner = User::factory()->create();

        $mine  = $this->makeClient($viewer->id, 'Mine Client');
        $other = $this->makeClient($otherOwner->id, 'Someone Elses Client');

        $response = $this->actingAs($viewer)->get(route('export.clients', ['format' => 'csv', 'ids' => "{$mine->id},{$other->id}"]));
        $response->assertOk();

        $path = storage_path('app/tmp_export_test_ids.csv');
        file_put_contents($path, $response->streamedContent());
        $names = $this->readNames($path);

        $this->assertContains('Mine Client', $names);
        $this->assertNotContains('Someone Elses Client', $names);
    }

    public function test_super_admin_export_includes_every_client(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $otherOwner = User::factory()->create();

        $this->makeClient($otherOwner->id, 'Someone Elses Client');

        $response = $this->actingAs($admin)->get(route('export.clients', ['format' => 'csv']));
        $response->assertOk();

        $path = storage_path('app/tmp_export_test_admin.csv');
        file_put_contents($path, $response->streamedContent());
        $names = $this->readNames($path);

        $this->assertContains('Someone Elses Client', $names);
    }
}
