<?php

namespace App\Http\Controllers;

use App\Services\FileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileManagerController extends Controller
{
    public function __construct(
        private readonly FileManagerService $fileManager,
    ) {}

    public function index()
    {
        abort_unless(Auth::user()->can('view file-manager'), 403);

        return view('file-manager.index');
    }

    public function list(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('view file-manager'), 403);

        try {
            return response()->json($this->fileManager->list($request->query('path')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function createFolder(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage file-manager'), 403);
        $data = $request->validate([
            'path' => 'nullable|string|max:2000',
            'name' => 'required|string|max:255',
        ]);

        try {
            $this->fileManager->createFolder($data['path'] ?? null, $data['name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage file-manager'), 403);
        $data = $request->validate([
            'path' => 'nullable|string|max:2000',
            'file' => 'required|file|max:102400',
        ]);

        try {
            $path = $this->fileManager->upload($data['path'] ?? null, $request->file('file'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'path' => $path]);
    }

    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless(Auth::user()->can('view file-manager'), 403);
        $data = $request->validate(['path' => 'required|string|max:2000']);

        try {
            [$absolutePath, $name] = $this->fileManager->download($data['path']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->download($absolutePath, $name);
    }

    public function preview(Request $request): BinaryFileResponse|JsonResponse
    {
        abort_unless(Auth::user()->can('view file-manager'), 403);
        $data = $request->validate(['path' => 'required|string|max:2000']);

        try {
            [$absolutePath, $name, $mime] = $this->fileManager->resolvePreview($data['path']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->file($absolutePath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    public function rename(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage file-manager'), 403);
        $data = $request->validate([
            'path' => 'required|string|max:2000',
            'name' => 'required|string|max:255',
        ]);

        try {
            $this->fileManager->rename($data['path'], $data['name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->can('manage file-manager'), 403);
        $data = $request->validate(['path' => 'required|string|max:2000']);

        try {
            $this->fileManager->delete($data['path']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }
}
