<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ImportLog;
use App\Models\User;
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
}
