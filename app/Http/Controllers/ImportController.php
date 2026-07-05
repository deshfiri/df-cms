<?php

namespace App\Http\Controllers;

use App\Http\Requests\Import\UploadImportRequest;
use App\Models\ImportLog;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $importService,
    ) {}

    public function index()
    {
        $logs   = ImportLog::with('user')->latest()->limit(20)->get();
        $fields = ImportService::importableFields();

        return view('import.index', compact('logs', 'fields'));
    }

    public function preview(UploadImportRequest $request): JsonResponse
    {
        $preview = $this->importService->preview($request->file('file'));

        session(['import_file_tmp' => $request->file('file')->store('import_tmp', 'local')]);

        return response()->json($preview);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'    => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'mapping' => 'required',
        ]);

        // JS sends mapping as a JSON string inside FormData
        $mapping = $request->mapping;
        if (is_string($mapping)) {
            $mapping = json_decode($mapping, true) ?? [];
        }

        if (empty($mapping)) {
            return response()->json(['success' => false, 'message' => 'Column mapping is empty.'], 422);
        }

        $log = ImportLog::create([
            'user_id'   => Auth::id(),
            'file_name' => $request->file('file')->getClientOriginalName(),
            'mapping'   => $mapping,
            'status'    => 'pending',
        ]);

        $result = $this->importService->import($request->file('file'), $mapping, $log);

        $newCount = $result->success_rows - $result->updated_rows;
        $msg = "Import complete — New: {$newCount}, "
             . "Updated: {$result->updated_rows}, "
             . "Skipped (unchanged): {$result->skipped_rows}, "
             . "Failed: {$result->failed_rows}";

        return response()->json([
            'success' => $result->status === 'completed',
            'log'     => $result,
            'message' => $msg,
        ]);
    }

    public function show(ImportLog $log): JsonResponse
    {
        return response()->json($log);
    }

    public function rollback(ImportLog $log): JsonResponse
    {
        // Rollback is only possible within 1 hour of import
        abort_if($log->created_at->lt(now()->subHour()), 403, 'Rollback window expired.');
        abort_if($log->status !== 'completed', 422, 'Can only rollback completed imports.');

        // We store the range of IDs created during this import by tracking first/last
        // For simplicity: delete all clients created by this user within the import window
        $this->importService->rollback($log);

        return response()->json(['success' => true, 'message' => 'Import rolled back.']);
    }
}
