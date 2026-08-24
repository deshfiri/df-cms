<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\ClientCorrectionRequest;
use App\Notifications\CorrectionRequestSubmitted;
use App\Services\PortalActivityLogService;
use App\Services\Storage\StorageSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class PortalCorrectionRequestController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly PortalActivityLogService $activityLog,
        private readonly StorageSettings $storage,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $correctionRequests = ClientCorrectionRequest::where('client_id', $client->id)->latest()->get();

        return view('portal.information.index', compact('client', 'correctionRequests'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category'        => ['required', Rule::in(['Personal', 'Company', 'Brand', 'Contact', 'Billing', 'Delivery', 'Business', 'Product'])],
            'field_label'     => ['required', 'string', 'max:150'],
            'current_value'   => ['nullable', 'string', 'max:2000'],
            'requested_value' => ['required', 'string', 'max:2000'],
            'reason'          => ['nullable', 'string', 'max:2000'],
            'file'            => ['nullable', 'file', 'max:20480'],
        ]);

        $client = $this->portalUser()->client;
        $fileData = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            $storedName = Str::uuid() . '.' . $ext;
            $disk = $this->storage->activeDisk();
            $path = $file->storeAs('portal/corrections/' . $client->id, $storedName, $disk);
            $fileData = [
                'original_name' => $file->getClientOriginalName(),
                'stored_name'   => $storedName,
                'disk'          => $disk,
                'path'          => $path,
                'mime_type'     => $file->getMimeType() ?? 'application/octet-stream',
                'file_size'     => $file->getSize(),
            ];
        }

        $correctionRequest = ClientCorrectionRequest::create(array_merge([
            'client_id'       => $client->id,
            'submitted_by'    => $this->portalUser()->id,
            'category'        => $data['category'],
            'field_label'     => $data['field_label'],
            'current_value'   => $data['current_value'] ?? null,
            'requested_value' => $data['requested_value'],
            'reason'          => $data['reason'] ?? null,
            'status'          => ClientCorrectionRequest::STATUS_PENDING,
        ], $fileData));

        $this->activityLog->log($this->portalUser(), 'Correction Request', 'Submitted', ClientCorrectionRequest::class, $correctionRequest->id);

        $this->notifyStaff($correctionRequest);

        return redirect()->route('portal.information.index')->with('success', 'Correction request submitted for review.');
    }

    private function notifyStaff(ClientCorrectionRequest $correctionRequest): void
    {
        $roles = Role::whereIn('name', ['Super Admin', 'Manager'])->pluck('name')->all();
        if (empty($roles)) {
            return;
        }

        $recipients = \App\Models\User::role($roles)->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CorrectionRequestSubmitted($correctionRequest));
    }
}
