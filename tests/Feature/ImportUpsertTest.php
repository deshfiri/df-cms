<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientStageProgress;
use App\Models\ImportLog;
use App\Models\Payment;
use App\Models\ProductUpdate;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportUpsertTest extends TestCase
{
    use RefreshDatabase;

    private ImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importService = app(ImportService::class);
        $this->actingAs(User::factory()->create());
    }

    private function makeUploadedXlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'import') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    private function mapping(): array
    {
        // Column order: dfid_number, client_name, brand_name, website, remarks, client_status
        return [
            'dfid_number'   => 0,
            'client_name'   => 1,
            'brand_name'    => 2,
            'website'       => 3,
            'remarks'       => 4,
            'client_status' => 5,
        ];
    }

    public function test_a_blank_cell_does_not_wipe_an_existing_value_on_update(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number'   => 'DFBLANK1',
            'client_name'   => 'Existing Client',
            'brand_name'    => 'Existing Brand',
            'website'       => 'https://existing.example.com',
            'remarks'       => 'Important existing remarks',
            'client_status' => 'Running',
            'category_id'   => $category->id,
        ]);

        // Only client_name provided; brand_name/website/remarks/status left blank in the sheet.
        $file = $this->makeUploadedXlsx(
            ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status'],
            [['DFBLANK1', 'Existing Client', '', '', '', '']]
        );

        $log = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test.xlsx', 'mapping' => $this->mapping(), 'status' => 'pending']);
        $this->importService->import($file, $this->mapping(), $log);

        $client->refresh();
        $this->assertSame('Existing Brand', $client->brand_name);
        $this->assertSame('https://existing.example.com', $client->website);
        $this->assertSame('Important existing remarks', $client->remarks);
        $this->assertSame('Running', $client->client_status);
    }

    public function test_a_provided_value_does_update_the_existing_field(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number'   => 'DFUPDATE1',
            'client_name'   => 'Existing Client',
            'brand_name'    => 'Old Brand',
            'client_status' => 'Running',
            'category_id'   => $category->id,
        ]);

        $file = $this->makeUploadedXlsx(
            ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status'],
            [['DFUPDATE1', 'Existing Client', 'New Brand', '', '', 'Hold']]
        );

        $log = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test.xlsx', 'mapping' => $this->mapping(), 'status' => 'pending']);
        $result = $this->importService->import($file, $this->mapping(), $log);

        $client->refresh();
        $this->assertSame('New Brand', $client->brand_name);
        $this->assertSame('Hold', $client->client_status);
        $this->assertSame(1, $result->updated_rows);
    }

    public function test_a_new_dfid_creates_a_client_with_sensible_defaults(): void
    {
        $mapping = $this->mapping() + ['category_name' => 6];
        $file = $this->makeUploadedXlsx(
            ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status', 'Category'],
            [['DFNEW1', 'Brand New Client', '', '', '', '', 'New Category']]
        );

        $log = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test.xlsx', 'mapping' => $mapping, 'status' => 'pending']);
        $result = $this->importService->import($file, $mapping, $log);

        $client = Client::where('dfid_number', 'DFNEW1')->first();
        $this->assertNotNull($client, 'Import errors: ' . json_encode($result->errors) . ' / ' . json_encode($result->validation_errors));
        $this->assertSame('Brand New Client', $client->brand_name); // fallback from client_name
        $this->assertSame('Running', $client->client_status); // default
        $this->assertSame(1, $result->success_rows - $result->updated_rows); // 1 new
    }

    public function test_an_invalid_status_on_update_is_sanitized_and_does_not_overwrite_existing_status(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number'   => 'DFINVALID1',
            'client_name'   => 'Existing Client',
            'brand_name'    => 'Existing Brand',
            'client_status' => 'Running',
            'category_id'   => $category->id,
        ]);

        $file = $this->makeUploadedXlsx(
            ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status'],
            [['DFINVALID1', 'Existing Client', '', '', '', 'NotARealStatus']]
        );

        $log = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test.xlsx', 'mapping' => $this->mapping(), 'status' => 'pending']);
        $this->importService->import($file, $this->mapping(), $log);

        $client->refresh();
        $this->assertSame('Running', $client->client_status);
    }

    public function test_stage_flag_columns_mark_matching_workflow_stages_complete(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number'   => 'DFSTAGE1',
            'client_name'   => 'Stage Client',
            'brand_name'    => 'Stage Brand',
            'client_status' => 'Running',
            'category_id'   => $category->id,
        ]);

        $mapping = $this->mapping() + ['stage_website_done' => 6];
        $file = $this->makeUploadedXlsx(
            ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status', 'Website Done'],
            [['DFSTAGE1', 'Stage Client', '', '', '', '', 'DONE']]
        );

        $log = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test.xlsx', 'mapping' => $mapping, 'status' => 'pending']);
        $this->importService->import($file, $mapping, $log);

        $devStageId      = WorkflowStage::where('code', 'website_development')->value('id');
        $approvedStageId = WorkflowStage::where('code', 'website_approved')->value('id');
        $sourcingStageId = WorkflowStage::where('code', 'product_sourcing')->value('id');

        $devProgress      = ClientStageProgress::where('client_id', $client->id)->where('stage_id', $devStageId)->first();
        $approvedProgress = ClientStageProgress::where('client_id', $client->id)->where('stage_id', $approvedStageId)->first();
        $sourcingProgress = ClientStageProgress::where('client_id', $client->id)->where('stage_id', $sourcingStageId)->first();

        $this->assertNotNull($devProgress);
        $this->assertTrue($devProgress->is_completed);
        $this->assertNotNull($approvedProgress);
        $this->assertTrue($approvedProgress->is_completed);
        // A stage with no matching sheet column must never be auto-completed.
        $this->assertTrue(!$sourcingProgress || !$sourcingProgress->is_completed);
    }

    public function test_product_and_payment_columns_upsert_in_place_without_duplicates(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number'   => 'DFPROD1',
            'client_name'   => 'Product Client',
            'brand_name'    => 'Product Brand',
            'client_status' => 'Running',
            'category_id'   => $category->id,
        ]);

        $mapping = $this->mapping() + [
            'product_status'        => 6,
            'product_received_date' => 7,
            'payment_status'        => 8,
            'payment_date'          => 9,
            'payment_note'          => 10,
        ];
        $headers = ['DFID', 'Name', 'Brand', 'Website', 'Remarks', 'Status', 'Product', 'Received', 'Payment', 'PayDate', 'Note'];
        $row     = ['DFPROD1', 'Product Client', '', '', '', '', 'Product Hold Research', '10 May 2026', 'Paid', '12 May 2026', 'Paid in full'];

        $file1 = $this->makeUploadedXlsx($headers, [$row]);
        $log1  = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test1.xlsx', 'mapping' => $mapping, 'status' => 'pending']);
        $this->importService->import($file1, $mapping, $log1);

        $this->assertSame(1, ProductUpdate::where('client_id', $client->id)->count());
        $this->assertSame(1, Payment::where('client_id', $client->id)->count());
        $this->assertSame('Paid', Payment::where('client_id', $client->id)->first()->status);

        // Re-import with an updated product status — must update the same row, not add a second one.
        $row2    = $row;
        $row2[6] = 'Product Received';
        $file2   = $this->makeUploadedXlsx($headers, [$row2]);
        $log2    = ImportLog::create(['user_id' => auth()->id(), 'file_name' => 'test2.xlsx', 'mapping' => $mapping, 'status' => 'pending']);
        $this->importService->import($file2, $mapping, $log2);

        $this->assertSame(1, ProductUpdate::where('client_id', $client->id)->count());
        $this->assertSame(1, Payment::where('client_id', $client->id)->count());
        $this->assertSame('Product Received', ProductUpdate::where('client_id', $client->id)->first()->status);
    }
}
