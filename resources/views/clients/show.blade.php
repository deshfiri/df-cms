@extends('layouts.app')
@section('title', $client->client_name)

@section('content')
@php
    $statusClr = ['Running'=>'success','Warning'=>'warning','Completed'=>'primary','Hold'=>'secondary','Cancelled'=>'danger'][$client->client_status] ?? 'dark';
    $progress = $client->progress;
    $prgClr = $progress === 100 ? 'success' : ($progress >= 50 ? 'warning' : 'danger');
@endphp

{{-- ── Header ── --}}
<div class="card section-card mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-start gap-3 flex-wrap">
            <a href="{{ route('clients.index') }}" class="btn btn-sm btn-light border mt-1">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle fw-bold flex-shrink-0"
                 style="width:52px;height:52px;font-size:1.2rem">
                {{ strtoupper(substr($client->client_name, 0, 1)) }}
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h5 class="mb-0 fw-bold">{{ $client->client_name }}</h5>
                    <span class="badge bg-secondary">{{ $client->dfid_number }}</span>
                    <span class="badge bg-{{ $statusClr }}">{{ $client->client_status }}</span>
                    @if($client->doc_status === 'DONE')
                    <span class="badge bg-success"><i class="bi bi-file-check me-1"></i>DOC DONE</span>
                    @endif
                </div>
                <div class="text-muted small mt-1">
                    <i class="bi bi-shop me-1"></i>{{ $client->brand_name }}
                    @if($client->website)
                    · <a href="{{ $client->website_url }}" target="_blank" class="text-muted">{{ $client->website }}</a>
                    @endif
                    @if($client->designs_link)
                    · <a href="{{ $client->designs_link_url }}" target="_blank" class="text-muted"><i class="bi bi-palette me-1"></i>Designs</a>
                    @endif
                    @if($client->category)
                    · <span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:500;background:rgba(var(--primary-rgb),.1);color:var(--primary)">{{ $client->category->name }}</span>
                    @endif
                    · <i class="bi bi-calendar3 me-1"></i>{{ $client->joining_date?->format('d M Y') ?? '—' }}
                    · <i class="bi bi-person-check me-1"></i>Assigned to <span id="assignedToName">{{ $client->assignedUser?->name ?? 'Unassigned' }}</span>
                </div>
                <div class="mt-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:8px;max-width:300px">
                            <div class="progress-bar bg-{{ $prgClr }}" style="width:{{ $progress }}%"></div>
                        </div>
                        <span class="small fw-semibold">{{ $progress }}% complete</span>
                        <span class="small text-muted">({{ $client->stageProgress->where('is_completed',true)->count() }}/{{ $stages->count() }} stages)</span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 ms-auto">
                @can('update', $client)
                <a href="{{ route('clients.edit', $client) }}" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
                @endcan
                @can('transfer', $client)
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#transferOwnerModal">
                    <i class="bi bi-arrow-left-right me-1"></i>Transfer
                </button>
                @endcan
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        Status
                    </button>
                    <ul class="dropdown-menu">
                        @foreach(\App\Models\Client::$statuses as $s)
                        <li>
                            <button class="dropdown-item small btn-status {{ $s === $client->client_status ? 'active fw-semibold' : '' }}"
                                    data-status="{{ $s }}">{{ $s }}</button>
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Tabs ── --}}
<ul class="nav nav-tabs mb-3" id="clientTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-workflow"><i class="bi bi-diagram-3 me-1"></i>Workflow</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-product"><i class="bi bi-box me-1"></i>Product Updates</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-payments"><i class="bi bi-credit-card me-1"></i>Payments</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-docs"><i class="bi bi-folder2 me-1"></i>Documents</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-meetings" id="meetingsTabLink"><i class="bi bi-calendar-event me-1"></i>Meetings</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-notes"><i class="bi bi-chat-left-text me-1"></i>Notes</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-activity"><i class="bi bi-clock-history me-1"></i>Activity</a></li>
</ul>

<div class="tab-content">

    {{-- ── WORKFLOW TAB ── --}}
    <div class="tab-pane fade show active" id="tab-workflow">
        <div class="card section-card">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0">Client Timeline</h6>
                <small class="text-muted">A step unlocks only after the previous step is approved</small>
            </div>
            <div class="card-body" id="workflowList">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Need Revision Reason Modal --}}
    <div class="modal fade" id="stageRejectModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold">Request Revision</h6>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rejectStageId">
                    <label class="form-label fw-semibold small">Reason <span class="text-danger">*</span></label>
                    <textarea id="rejectReason" class="form-control" rows="3" placeholder="What needs to change?"></textarea>
                </div>
                <div class="modal-footer py-2">
                    <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button id="confirmReject" class="btn btn-sm btn-warning">Send Back</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── PRODUCT UPDATES TAB ── --}}
    <div class="tab-pane fade" id="tab-product">
        <div class="card section-card">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0">Product Updates</h6>
                @can('update', $client)
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Update
                </button>
                @endcan
            </div>
            <div class="card-body p-0">
                <div id="productList">
                    <div class="d-flex justify-content-center py-5">
                        <div class="spinner-border text-primary spinner-border-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── PAYMENTS TAB ── --}}
    <div class="tab-pane fade" id="tab-payments">
        <div class="card section-card">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0">Payment History</h6>
                @can('update', $client)
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Payment
                </button>
                @endcan
            </div>
            <div id="paymentSummary" class="px-3 pb-2"></div>
            <div class="card-body p-0">
                <div id="paymentList">
                    <div class="d-flex justify-content-center py-5">
                        <div class="spinner-border text-primary spinner-border-sm"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── DOCUMENTS TAB ── --}}
    <div class="tab-pane fade" id="tab-docs">
        {{-- Stats bar --}}
        <div class="row g-3 mb-3" id="docStats">
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0" id="dsStat-total" style="color:var(--primary)">—</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Docs</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0" id="dsStat-size" style="color:var(--primary)">—</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Storage Used</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div id="dsStat-agreement" class="fw-bold fs-5 mb-0">—</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Agreement</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card d-flex align-items-center justify-content-center py-3">
                    @can('update', $client)
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDocModal">
                        <i class="bi bi-upload me-1"></i>Upload Document
                    </button>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Document list --}}
        <div class="card">
            <div class="card-body p-0" id="docList">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── MEETINGS TAB ── --}}
    <div class="tab-pane fade" id="tab-meetings">
        <div class="card section-card">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0">Meetings</h6>
                @can('manage-meetings')
                <button class="btn btn-sm btn-primary" id="addMeetingBtn" data-bs-toggle="modal" data-bs-target="#addMeetingModal">
                    <i class="bi bi-plus-lg me-1"></i>Schedule Meeting
                </button>
                @endcan
            </div>
            <div class="card-body p-0" id="meetingsList">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── NOTES TAB ── --}}
    <div class="tab-pane fade" id="tab-notes">
        <div class="card section-card">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Notes</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <textarea id="noteInput" class="form-control" rows="3" placeholder="Write a note..."></textarea>
                    <button id="saveNote" class="btn btn-sm btn-primary mt-2">
                        <i class="bi bi-send me-1"></i>Save Note
                    </button>
                </div>
                <div id="notesList"></div>
            </div>
        </div>
    </div>

    {{-- ── ACTIVITY TAB ── --}}
    <div class="tab-pane fade" id="tab-activity">
        <div class="card section-card mb-3">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Ownership History</h6>
            </div>
            <div class="card-body" id="ownershipHistoryList">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                </div>
            </div>
        </div>
        <div class="card section-card">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Activity Log</h6>
            </div>
            <div class="card-body" id="activityList">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Transfer Ownership Modal --}}
<div class="modal fade" id="transferOwnerModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Transfer Client</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold small">New Owner <span class="text-danger">*</span></label>
                <select id="transferNewOwner" class="form-select mb-3">
                    <option value=""></option>
                    @foreach($users as $u)
                    @if($u->id !== $client->assigned_to)
                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endif
                    @endforeach
                </select>
                <label class="form-label fw-semibold small">Note (optional)</label>
                <textarea id="transferNote" class="form-control" rows="2"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="transferConfirm" class="btn btn-sm btn-primary">Transfer</button>
            </div>
        </div>
    </div>
</div>

{{-- ── Modals ── --}}

{{-- Add Product Modal --}}
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Add Product Update</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                    <select id="productStatus" class="form-select">
                        @foreach(\App\Models\ProductUpdate::$statuses as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Remarks</label>
                    <textarea id="productRemarks" class="form-control" rows="3" placeholder="Additional details..."></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveProduct" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

{{-- Add Payment Modal --}}
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Add Payment</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                        <select id="payStatus" class="form-select">
                            @foreach(\App\Models\Payment::$statuses as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Amount</label>
                        <input type="number" id="payAmount" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Payment Date</label>
                        <input type="date" id="payDate" class="form-control" value="{{ date('Y-m-d') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Method</label>
                        <select id="payMethod" class="form-select">
                            <option value="">Select...</option>
                            @foreach(\App\Models\Payment::$methods as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Transaction Number</label>
                        <input type="text" id="payTxn" class="form-control" placeholder="Ref / Transaction ID">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Remarks</label>
                        <textarea id="payRemarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="savePayment" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

{{-- Upload Document Modal --}}
<div class="modal fade" id="addDocModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title" id="addDocModalTitle"><i class="bi bi-upload me-2"></i>Upload Document</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <input type="hidden" id="docParentId" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Document Type <span class="text-danger">*</span></label>
                        <select id="docTypeId" class="form-select form-select-sm">
                            <option value="">Select type…</option>
                            @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}" data-name="{{ $dt->name }}">{{ $dt->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" id="docTitle" class="form-control form-control-sm" placeholder="e.g. Client Agreement v1">
                    </div>
                    <div class="col-12">
                        <label class="form-label">File <span class="text-danger">*</span></label>
                        <div id="docDropZone" class="border-2 border rounded-3 text-center py-4 px-3" style="border-style:dashed !important;cursor:pointer;transition:background .15s">
                            <i class="bi bi-cloud-upload" style="font-size:1.6rem;color:var(--text3)"></i>
                            <div style="font-size:.79rem;color:var(--text2);margin-top:6px">Drag & drop file here or <span style="color:var(--primary);font-weight:600">browse</span></div>
                            <div style="font-size:.7rem;color:var(--text3);margin-top:3px">PDF, Word, Excel, CSV, Images, ZIP · Max 20MB</div>
                        </div>
                        <input type="file" id="docFile" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xlsx,.xls,.csv,.zip">
                        <div id="docFileInfo" class="mt-2 d-none">
                            <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:var(--surface2);border:1px solid var(--border)">
                                <i class="bi bi-file-earmark" id="docFileIcon" style="color:var(--primary)"></i>
                                <div class="flex-fill min-w-0">
                                    <div id="docFileName" class="text-truncate" style="font-size:.79rem;font-weight:500"></div>
                                    <div id="docFileSize" style="font-size:.68rem;color:var(--text3)"></div>
                                </div>
                                <button type="button" onclick="clearDocFile()" class="btn btn-sm p-0" style="color:var(--text3)"><i class="bi bi-x-circle"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description</label>
                        <textarea id="docDesc" class="form-control form-control-sm" rows="2" placeholder="Optional description…"></textarea>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" id="docExpiry" class="form-control form-control-sm">
                        </div>
                        <div>
                            <label class="form-label">Tags <span style="font-size:.68rem;color:var(--text3)">(comma separated)</span></label>
                            <input type="text" id="docTags" class="form-control form-control-sm" placeholder="e.g. signed, final, v2">
                        </div>
                    </div>
                </div>
                <div id="docUploadProgress" class="mt-3 d-none">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span style="font-size:.76rem;color:var(--text2)">Uploading…</span>
                        <span id="docProgressPct" style="font-size:.73rem;color:var(--primary);margin-left:auto">0%</span>
                    </div>
                    <div class="progress" style="height:5px">
                        <div class="progress-bar" id="docProgressBar" style="width:0%;background:var(--primary)"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="uploadDoc" class="btn btn-sm btn-primary">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Preview Modal --}}
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="height:90vh">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title" id="previewTitle">Preview</h6>
                <div class="d-flex gap-2 ms-3">
                    <a id="previewDownload" href="#" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" id="previewBody" style="overflow:hidden;height:calc(90vh - 60px)">
            </div>
        </div>
    </div>
</div>

{{-- Add / Edit Meeting Modal --}}
<div class="modal fade" id="addMeetingModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" id="meetingModalTitle"><i class="bi bi-calendar-plus me-2"></i>Schedule Meeting</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <input type="hidden" id="meetingEditId" value="">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
                        <input type="text" id="meetingTitle" class="form-control form-control-sm" placeholder="Meeting title…">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Type <span class="text-danger">*</span></label>
                        <select id="meetingType" class="form-select form-select-sm">
                            <option value="in_person">In Person</option>
                            <option value="phone">Phone Call</option>
                            <option value="video">Video Call</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Duration <span class="text-danger">*</span></label>
                        <select id="meetingDuration" class="form-select form-select-sm">
                            <option value="15">15 min</option>
                            <option value="30">30 min</option>
                            <option value="45">45 min</option>
                            <option value="60" selected>1 hour</option>
                            <option value="90">1.5 hours</option>
                            <option value="120">2 hours</option>
                            <option value="180">3 hours</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="meetingDatetime" class="form-control form-control-sm" step="300">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Assigned Staff</label>
                        <select id="meetingAssignedTo" class="form-select form-select-sm select2" style="width:100%">
                            <option value="">Unassigned</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6" id="meetingLocationWrap">
                        <label class="form-label fw-semibold small">Location</label>
                        <input type="text" id="meetingLocation" class="form-control form-control-sm" placeholder="Office, address…">
                    </div>
                    <div class="col-md-6 d-none" id="meetingLinkWrap">
                        <label class="form-label fw-semibold small">Join Link</label>
                        <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:var(--surface2);border:1px solid var(--border);font-size:.76rem;color:var(--text3);height:31px">
                            <i class="bi bi-camera-video"></i>
                            <span>A Google Meet link is generated automatically when saved</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Agenda</label>
                        <textarea id="meetingAgenda" class="form-control form-control-sm" rows="3" placeholder="What will be discussed?"></textarea>
                    </div>
                </div>
                <div id="meetingConflictWarn" class="mt-3 p-2 rounded d-none c-red" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg);font-size:.77rem"></div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="saveMeetingBtn" class="btn btn-sm btn-primary">
                    <i class="bi bi-calendar-check me-1"></i>Save Meeting
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Complete Meeting Modal --}}
<div class="modal fade" id="completeMeetingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold"><i class="bi bi-check-circle me-2"></i>Mark as Completed</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <input type="hidden" id="completeMeetingId" value="">
                <label class="form-label fw-semibold small">Post-meeting Notes</label>
                <textarea id="completeMeetingNotes" class="form-control form-control-sm" rows="4" placeholder="Key outcomes, next steps, action items…"></textarea>
            </div>
            <div class="modal-footer py-2 px-3">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="confirmCompleteBtn" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg me-1"></i>Mark Completed
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Version History Modal --}}
<div class="modal fade" id="versionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title"><i class="bi bi-clock-history me-2"></i>Version History</h6>
                <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="versionsBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.wf-timeline { position: relative; padding-left: 6px; }
.wf-step { position: relative; display: flex; gap: 14px; padding: 0 0 22px 0; }
.wf-step:not(:last-child)::before {
    content: ''; position: absolute; left: 13px; top: 28px; bottom: 0;
    width: 2px; background: var(--border);
}
.wf-step.wf-locked { opacity: .55; }
.wf-dot {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; z-index: 1; border: 2px solid var(--border); background: var(--surface);
}
.wf-dot-done   { background: var(--c-green); border-color: var(--c-green); color: #fff; }
.wf-dot-open   { background: var(--surface); border-color: var(--primary); color: var(--primary); }
.wf-dot-locked { background: var(--surface2); border-color: var(--border); color: var(--text3); }
.wf-dot-overdue{ background: var(--c-red); border-color: var(--c-red); color: #fff; }
.wf-step.wf-current .wf-dot-open { box-shadow: 0 0 0 4px rgba(var(--primary-rgb),.15); }
.wf-body { flex: 1; padding-top: 2px; }
</style>
@endpush

@push('scripts')
<script>
const clientId = {{ $client->id }};
const baseUrl  = '/clients/' + clientId;

// ── Workflow Timeline ────────────────────────────────────────────────────────
$(document).on('click', '.btn-stage-submit', function () {
    const stageId = $(this).data('stage');
    $.post(baseUrl + '/stages/submit', { stage_id: stageId })
     .done(() => location.reload())
     .fail(r => Swal.fire('Error', r.responseJSON?.message || 'Could not submit stage.', 'error'));
});

$(document).on('click', '.btn-stage-approve', function () {
    const stageId = $(this).data('stage');
    $.post(baseUrl + '/stages/approve', { stage_id: stageId })
     .done(() => location.reload())
     .fail(r => Swal.fire('Error', r.responseJSON?.message || 'Could not approve stage.', 'error'));
});

$(document).on('click', '.btn-stage-reject', function () {
    $('#rejectStageId').val($(this).data('stage'));
    $('#rejectReason').val('');
    new bootstrap.Modal('#stageRejectModal').show();
});

$('#confirmReject').on('click', function () {
    const stageId = $('#rejectStageId').val();
    const reason  = $.trim($('#rejectReason').val());
    if (!reason) return;
    $.post(baseUrl + '/stages/reject', { stage_id: stageId, reason: reason })
     .done(() => location.reload())
     .fail(r => Swal.fire('Error', r.responseJSON?.message || 'Could not send back stage.', 'error'));
});

$(document).on('click', '.btn-stage-override', function () {
    const stageId   = $(this).data('stage');
    const completed = $(this).data('completed');
    Swal.fire({
        title: 'Admin override?',
        text: 'This bypasses locking and approval requirements.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(baseUrl + '/stages/toggle', { stage_id: stageId, completed: completed })
         .done(() => location.reload())
         .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not update stage.', 'error'));
    });
});

function wfStep(row) {
    var dotClass = row.status === 'Approved' ? 'wf-dot-done'
        : (row.payment_lock ? 'wf-dot-overdue'
        : (row.locked ? 'wf-dot-locked'
        : (row.overdue ? 'wf-dot-overdue' : 'wf-dot-open')));

    var dotIcon = row.status === 'Approved' ? '<i class="bi bi-check-lg"></i>'
        : (row.payment_lock ? '<i class="bi bi-cash-coin"></i>'
        : (row.locked ? '<i class="bi bi-lock-fill"></i>'
        : '<i class="bi bi-circle-fill" style="font-size:.4rem"></i>'));

    var deptPill = row.department
        ? '<span style="font-size:.68rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">' + esc(row.department) + '</span>'
        : '';

    var overduePill = row.overdue ? '<span class="spill spill-rejected"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue</span>' : '';
    var paymentPill = row.payment_lock ? '<span class="spill spill-rejected"><i class="bi bi-cash-coin me-1"></i>Payment Required</span>' : '';

    var meta = '';
    if (row.submitted_at) meta += 'Submitted ' + esc(row.submitted_at) + (row.submitted_by ? ' by ' + esc(row.submitted_by) : '');
    if (row.completed_at) meta += (meta ? ' · ' : '') + 'Approved ' + esc(row.completed_at) + (row.completed_by ? ' by ' + esc(row.completed_by) : '');
    var rejectionHtml = row.rejection_reason ? '<div class="c-yellow mt-1"><i class="bi bi-arrow-return-left me-1"></i>' + esc(row.rejection_reason) + '</div>' : '';
    var paymentTextHtml = row.payment_lock ? '<div class="c-red mt-1"><i class="bi bi-cash-coin me-1"></i>' + esc(row.payment_lock) + '</div>' : '';

    var actions = '';
    if (row.can_submit) {
        actions += '<button class="btn btn-sm btn-primary btn-stage-submit" data-stage="' + row.id + '"><i class="bi bi-send me-1"></i>' + (row.requires_approval ? 'Submit' : 'Mark Done') + '</button>';
    }
    if (row.can_approve) {
        actions += '<button class="btn btn-sm btn-success btn-stage-approve" data-stage="' + row.id + '"><i class="bi bi-check-lg me-1"></i>Approve</button>';
        actions += '<button class="btn btn-sm btn-outline-warning btn-stage-reject" data-stage="' + row.id + '"><i class="bi bi-arrow-return-left me-1"></i>Need Revision</button>';
    }
    if (canManageWorkflow) {
        actions += '<button class="btn btn-sm btn-outline-secondary btn-stage-override" data-stage="' + row.id + '" data-completed="' + (row.status === 'Approved' ? '0' : '1') + '"><i class="bi bi-shield-lock me-1"></i>' + (row.status === 'Approved' ? 'Revert (Admin)' : 'Force Approve (Admin)') + '</button>';
    }

    return '<div class="wf-step ' + (row.locked ? 'wf-locked' : '') + ' ' + (row.current ? 'wf-current' : '') + '">'
         + '<div class="wf-dot ' + dotClass + '">' + dotIcon + '</div>'
         + '<div class="wf-body">'
         +   '<div class="d-flex align-items-center gap-2 flex-wrap">'
         +     '<span class="fw-semibold small">' + esc(row.name) + '</span>' + deptPill
         +     '<span class="spill ' + row.spill_class + '">' + esc(row.status) + '</span>' + overduePill + paymentPill
         +   '</div>'
         +   '<div class="text-muted mt-1" style="font-size:.72rem">' + meta + rejectionHtml + paymentTextHtml + '</div>'
         +   '<div class="d-flex gap-2 mt-2">' + actions + '</div>'
         + '</div>'
         + '</div>';
}

function loadWorkflow() {
    $('#workflowList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(baseUrl + '/timeline')
    .done(function (res) {
        var items = res.stages || [];
        var html = '<div class="wf-timeline">';
        items.forEach(function (row) { html += wfStep(row); });
        html += '</div>';
        $('#workflowList').html(html);
    })
    .fail(function () {
        $('#workflowList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load workflow timeline.</div>');
    });
}

// ── Activity ────────────────────────────────────────────────────────────────
function loadActivity() {
    $('#activityList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(baseUrl + '/activity')
    .done(function (res) {
        var items = res.data || [];
        if (!items.length) {
            $('#activityList').html('<p class="text-muted small">No activity yet.</p>');
            return;
        }
        var html = '<div class="timeline">';
        items.forEach(function (log) {
            html += '<div class="timeline-item"><div class="t-dot done"></div><div class="ms-1">'
                 +  '<span class="badge bg-secondary me-1">' + esc(log.module) + '</span>'
                 +  '<strong>' + esc(log.action) + '</strong>'
                 +  (log.user ? ' by <em>' + esc(log.user) + '</em>' : '')
                 +  '<div class="text-muted small">' + esc(log.created_at) + ' · ' + esc(log.ip_address) + '</div>'
                 +  '</div></div>';
        });
        html += '</div>';
        $('#activityList').html(html);
    })
    .fail(function () {
        $('#activityList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load activity.</div>');
    });
}

function loadOwnershipHistory() {
    $('#ownershipHistoryList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(baseUrl + '/ownership-history')
    .done(function (res) {
        var items = res.data || [];
        if (!items.length) {
            $('#ownershipHistoryList').html('<p class="text-muted small">No ownership changes yet.</p>');
            return;
        }
        var html = '<div class="timeline">';
        items.forEach(function (row) {
            html += '<div class="timeline-item"><div class="t-dot done"></div><div class="ms-1">'
                 +  '<strong>' + esc(row.from) + '</strong> <i class="bi bi-arrow-right mx-1"></i> <strong>' + esc(row.to) + '</strong>'
                 +  ' by <em>' + esc(row.by) + '</em>'
                 +  (row.note ? '<div class="text-muted small mt-1">' + esc(row.note) + '</div>' : '')
                 +  '<div class="text-muted small">' + esc(row.date) + '</div>'
                 +  '</div></div>';
        });
        html += '</div>';
        $('#ownershipHistoryList').html(html);
    })
    .fail(function () {
        $('#ownershipHistoryList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load ownership history.</div>');
    });
}

// ── Product Updates ────────────────────────────────────────────────────────
function loadProducts() {
    $.get(baseUrl + '/products').done(function (data) {
        if (!data.length) {
            $('#productList').html('<p class="text-muted text-center py-4 small">No product updates yet.</p>');
            return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.83rem"><thead><tr><th>Status</th><th>Remarks</th><th>By</th><th>Date</th><th></th></tr></thead><tbody>';
        data.forEach(function (u) {
            html += `<tr>
                <td><span class="badge bg-info">${u.status}</span></td>
                <td>${u.remarks || '—'}</td>
                <td>${u.created_by?.name || '—'}</td>
                <td>${new Date(u.created_at).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})}</td>
                <td>${canUpdateClient ? '<button class="btn btn-xs btn-outline-danger delete-product" data-id="' + u.id + '"><i class="bi bi-trash"></i></button>' : ''}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        $('#productList').html(html);
    });
}

$('#saveProduct').on('click', function () {
    $.post(baseUrl + '/products', {
        status: $('#productStatus').val(),
        remarks: $('#productRemarks').val()
    }).done(function (r) {
        bootstrap.Modal.getInstance('#addProductModal').hide();
        $('#productRemarks').val('');
        loadProducts();
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
    });
});

$(document).on('click', '.delete-product', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete update?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(r => { if (r.isConfirmed) $.ajax({ url: baseUrl + '/products/' + id, type: 'DELETE' }).done(loadProducts); });
});

// ── Payments ───────────────────────────────────────────────────────────────
function loadPayments() {
    $.get(baseUrl + '/payments').done(function (r) {
        const data = r.payments;
        const s    = r.summary;
        // Summary bar
        $('#paymentSummary').html(`<div class="d-flex gap-3 small text-muted py-2">
            <span><i class="bi bi-cash text-success me-1"></i>Paid: <strong>৳${parseFloat(s.total_paid||0).toFixed(2)}</strong></span>
            <span><i class="bi bi-hourglass text-warning me-1"></i>Partial: <strong>৳${parseFloat(s.total_partial||0).toFixed(2)}</strong></span>
            <span><i class="bi bi-receipt me-1"></i>${s.count} payment(s)</span>
        </div>`);

        if (!data.length) {
            $('#paymentList').html('<p class="text-muted text-center py-4 small">No payments recorded.</p>');
            return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.83rem"><thead><tr><th>Status</th><th>Amount</th><th>Date</th><th>Method</th><th>Txn #</th><th>By</th><th></th></tr></thead><tbody>';
        const statusClr = { Paid: 'success', Partial: 'warning', Unpaid: 'danger' };
        data.forEach(p => {
            html += `<tr>
                <td><span class="badge bg-${statusClr[p.status]||'secondary'}">${p.status}</span></td>
                <td>${p.amount ? '৳' + parseFloat(p.amount).toFixed(2) : '—'}</td>
                <td>${p.payment_date ? new Date(p.payment_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
                <td>${p.payment_method||'—'}</td>
                <td>${p.transaction_number||'—'}</td>
                <td>${p.created_by?.name||'—'}</td>
                <td>${canUpdateClient ? '<button class="btn btn-xs btn-outline-danger delete-payment" data-id="' + p.id + '"><i class="bi bi-trash"></i></button>' : ''}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        $('#paymentList').html(html);
    });
}

$('#savePayment').on('click', function () {
    $.post(baseUrl + '/payments', {
        status: $('#payStatus').val(),
        amount: $('#payAmount').val(),
        payment_date: $('#payDate').val(),
        payment_method: $('#payMethod').val(),
        transaction_number: $('#payTxn').val(),
        remarks: $('#payRemarks').val()
    }).done(function () {
        bootstrap.Modal.getInstance('#addPaymentModal').hide();
        loadPayments();
        Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
    });
});

$(document).on('click', '.delete-payment', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete payment?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(r => { if (r.isConfirmed) $.ajax({ url: baseUrl + '/payments/' + id, type: 'DELETE' }).done(loadPayments); });
});

// ── Documents ──────────────────────────────────────────────────────────────
function docExtIcon(ext, mime) {
    if (!mime) return 'bi-file-earmark';
    if (mime.startsWith('image/')) return 'bi-file-earmark-image';
    if (mime === 'application/pdf') return 'bi-file-earmark-pdf';
    if (mime.includes('word')) return 'bi-file-earmark-word';
    if (mime.includes('excel') || mime.includes('spreadsheet')) return 'bi-file-earmark-excel';
    if (mime.includes('zip')) return 'bi-file-zip';
    return 'bi-file-earmark';
}
function docExtColor(mime) {
    if (!mime) return 'var(--text3)';
    if (mime.startsWith('image/')) return '#059669';
    if (mime === 'application/pdf') return '#dc2626';
    if (mime.includes('word')) return '#2563eb';
    if (mime.includes('excel') || mime.includes('spreadsheet')) return '#059669';
    if (mime.includes('zip')) return '#d97706';
    return 'var(--text3)';
}

function loadDocs() {
    $.get(baseUrl + '/documents').done(function (resp) {
        // Update stats
        $('#dsStat-total').text(resp.total);
        $('#dsStat-size').text(resp.totalSize);

        // Agreement status
        const hasSigned = resp.docs?.some(d => d.type_name === 'Signed Agreement');
        $('#dsStat-agreement').html(hasSigned
            ? '<span class="spill spill-running">Signed</span>'
            : '<span class="spill spill-warning">Pending</span>');

        if (!resp.docs?.length) {
            $('#docList').html('<div class="text-center py-5" style="color:var(--text3)"><i class="bi bi-folder2-open" style="font-size:2rem"></i><div class="mt-2" style="font-size:.82rem">No documents uploaded yet.</div></div>');
            return;
        }

        let html = '<table class="table align-middle mb-0" style="font-size:.79rem">'
            + '<thead><tr>'
            + '<th style="width:36px"></th>'
            + '<th>Title</th>'
            + '<th>Type</th>'
            + '<th>Size</th>'
            + '<th>Version</th>'
            + '<th>Uploaded By</th>'
            + '<th>Date</th>'
            + '<th class="text-end pe-3">Actions</th>'
            + '</tr></thead><tbody>';

        resp.docs.forEach(d => {
            const icon  = docExtIcon(d.extension, d.mime);
            const color = docExtColor(d.mime);
            html += `<tr>
                <td class="ps-3"><i class="bi ${icon}" style="font-size:1.3rem;color:${color}"></i></td>
                <td>
                    <div style="font-weight:600;color:var(--text)">${d.title}</div>
                    <div style="font-size:.69rem;color:var(--text3)">${d.original_name}</div>
                    ${d.expiry_date ? `<div class="c-yellow" style="font-size:.66rem"><i class="bi bi-calendar-x me-1"></i>Expires ${d.expiry_date}</div>` : ''}
                </td>
                <td><span style="font-size:.7rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">${d.type_name||'—'}</span></td>
                <td style="color:var(--text3)">${d.size}</td>
                <td>${d.version > 1 ? `<span class="badge" style="background:rgba(var(--primary-rgb),.1);color:var(--primary)">v${d.version}</span>` : '<span style="color:var(--text3);font-size:.71rem">v1</span>'}</td>
                <td style="color:var(--text2)">${d.uploader}</td>
                <td style="color:var(--text3);white-space:nowrap">${d.uploaded_ago}</td>
                <td class="text-end pe-3">
                    <div class="d-flex gap-1 justify-content-end">
                        ${(d.is_image || d.is_pdf) ? `<button class="btn btn-sm px-2 py-1 doc-preview" data-id="${d.id}" data-url="${d.preview_url}" data-title="${d.title}" data-mime="${d.mime}" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Preview"><i class="bi bi-eye"></i></button>` : ''}
                        <a href="${d.download_url}" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Download"><i class="bi bi-download"></i></a>
                        ${canUpdateClient ? `<button class="btn btn-sm px-2 py-1 doc-replace" data-id="${d.id}" data-type-id="${d.type_name}" data-title="${d.title}" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Upload new version"><i class="bi bi-arrow-repeat"></i></button>` : ''}
                        ${d.versions_count > 0 ? `<button class="btn btn-sm px-2 py-1 doc-history" data-id="${d.id}" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Version history"><i class="bi bi-clock-history"></i></button>` : ''}
                        ${canUpdateClient ? `<button class="btn btn-sm px-2 py-1 doc-delete c-red" data-id="${d.id}" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg)" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
                    </div>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        $('#docList').html(html);
    });
}

// Drag & drop
const $dz = $('#docDropZone');
$dz.on('click', () => $('#docFile').trigger('click'));
$dz.on('dragover', function(e) { e.preventDefault(); $(this).css('background','var(--surface2)'); });
$dz.on('dragleave', function() { $(this).css('background',''); });
$dz.on('drop', function(e) {
    e.preventDefault(); $(this).css('background','');
    const files = e.originalEvent.dataTransfer.files;
    if (files.length) { $('#docFile').prop('files', files); showDocFileInfo(files[0]); }
});
$('#docFile').on('change', function() { if (this.files[0]) showDocFileInfo(this.files[0]); });

function showDocFileInfo(file) {
    const icons = { 'application/pdf': 'bi-file-earmark-pdf', 'image': 'bi-file-earmark-image' };
    const icon = file.type.startsWith('image/') ? 'bi-file-earmark-image' : 'bi-file-earmark';
    $('#docFileIcon').attr('class', 'bi ' + icon);
    $('#docFileName').text(file.name);
    $('#docFileSize').text(file.size > 1048576 ? (file.size/1048576).toFixed(1) + ' MB' : Math.round(file.size/1024) + ' KB');
    $('#docFileInfo').removeClass('d-none');
    // Auto-fill title from filename if empty
    if (!$('#docTitle').val()) {
        $('#docTitle').val(file.name.replace(/\.[^/.]+$/, ''));
    }
}

function clearDocFile() {
    $('#docFile').val('');
    $('#docFileInfo').addClass('d-none');
}

// Replace (new version) button
$(document).on('click', '.doc-replace', function() {
    const id = $(this).data('id');
    $('#docParentId').val(id);
    $('#addDocModalTitle').html('<i class="bi bi-arrow-repeat me-2"></i>Upload New Version');
    new bootstrap.Modal('#addDocModal').show();
});

// Preview
$(document).on('click', '.doc-preview', function() {
    const url   = $(this).data('url');
    const title = $(this).data('title');
    const mime  = $(this).data('mime');
    const id    = $(this).data('id');
    $('#previewTitle').text(title);
    $('#previewDownload').attr('href', url.replace('/preview', '/download'));
    let body = '';
    if (mime === 'application/pdf') {
        body = `<iframe src="${url}" style="width:100%;height:100%;border:none"></iframe>`;
    } else if (mime.startsWith('image/')) {
        body = `<div class="text-center p-3"><img src="${url}" style="max-width:100%;max-height:calc(90vh - 80px);object-fit:contain"></div>`;
    }
    $('#previewBody').html(body);
    new bootstrap.Modal('#previewModal').show();
});

// Version history
$(document).on('click', '.doc-history', function() {
    const id = $(this).data('id');
    $('#versionsBody').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    new bootstrap.Modal('#versionsModal').show();
    $.get(baseUrl + '/documents/' + id + '/versions').done(function(versions) {
        if (!versions.length) { $('#versionsBody').html('<p class="text-muted text-center">No versions found.</p>'); return; }
        let html = '<table class="table table-sm align-middle mb-0" style="font-size:.79rem"><thead><tr><th>Version</th><th>Filename</th><th>Size</th><th>Uploaded By</th><th>Date</th><th></th></tr></thead><tbody>';
        versions.forEach(v => {
            html += `<tr>
                <td><span class="badge" style="background:rgba(var(--primary-rgb),.1);color:var(--primary)">v${v.version}</span></td>
                <td>${v.original_name}</td>
                <td>${v.size}</td>
                <td>${v.uploader}</td>
                <td>${v.uploaded_ago}</td>
                <td><a href="${v.download_url}" class="btn btn-xs btn-outline-secondary"><i class="bi bi-download"></i></a></td>
            </tr>`;
        });
        html += '</tbody></table>';
        $('#versionsBody').html(html);
    });
});

// Delete
$(document).on('click', '.doc-delete', function() {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete document?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(r => { if (r.isConfirmed) $.ajax({ url: baseUrl + '/documents/' + id, type: 'DELETE' }).done(loadDocs); });
});

// Upload
$('#uploadDoc').on('click', function () {
    if (!$('#docTypeId').val()) { Swal.fire('Error', 'Please select a document type.', 'error'); return; }
    if (!$('#docTitle').val().trim()) { Swal.fire('Error', 'Please enter a title.', 'error'); return; }
    if (!$('#docFile')[0].files[0]) { Swal.fire('Error', 'Please select a file.', 'error'); return; }

    const fd = new FormData();
    fd.append('document_type_id', $('#docTypeId').val());
    fd.append('title', $('#docTitle').val().trim());
    fd.append('description', $('#docDesc').val());
    fd.append('expiry_date', $('#docExpiry').val());
    fd.append('tags', $('#docTags').val());
    fd.append('file', $('#docFile')[0].files[0]);
    if ($('#docParentId').val()) fd.append('parent_id', $('#docParentId').val());
    fd.append('_token', $('meta[name=csrf-token]').attr('content'));

    $('#docUploadProgress').removeClass('d-none');
    $('#uploadDoc').prop('disabled', true);

    $.ajax({
        url: baseUrl + '/documents', type: 'POST', data: fd,
        processData: false, contentType: false,
        xhr: function() {
            const xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    $('#docProgressBar').css('width', pct + '%');
                    $('#docProgressPct').text(pct + '%');
                }
            }, false);
            return xhr;
        }
    }).done(function() {
        bootstrap.Modal.getInstance('#addDocModal').hide();
        $('#docTypeId,#docTitle,#docDesc,#docExpiry,#docTags').val('');
        $('#docParentId').val('');
        clearDocFile();
        $('#docUploadProgress').addClass('d-none');
        $('#docProgressBar').css('width', '0%');
        $('#uploadDoc').prop('disabled', false);
        $('#addDocModalTitle').html('<i class="bi bi-upload me-2"></i>Upload Document');
        loadDocs();
        Swal.fire({ icon: 'success', title: 'Uploaded', timer: 1500, showConfirmButton: false });
    }).fail(function(r) {
        $('#docUploadProgress').addClass('d-none');
        $('#uploadDoc').prop('disabled', false);
        const msg = r.responseJSON?.errors ? Object.values(r.responseJSON.errors).flat().join('<br>') : (r.responseJSON?.message || 'Upload failed');
        Swal.fire('Error', msg, 'error');
    });
});

// ── Notes ──────────────────────────────────────────────────────────────────
function loadNotes() {
    $.get(baseUrl + '/notes').done(function (data) {
        if (!data.length) { $('#notesList').html('<p class="text-muted small">No notes yet.</p>'); return; }
        let html = '';
        data.forEach(n => {
            html += `<div class="d-flex gap-2 mb-3">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width:32px;height:32px;font-size:.75rem">${n.user?.name?.charAt(0).toUpperCase()||'?'}</div>
                <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;flex-grow:1">
                    <div class="d-flex justify-content-between align-items-start">
                        <strong style="font-size:.77rem;color:var(--text)">${n.user?.name||'Unknown'}</strong>
                        <span style="font-size:.68rem;color:var(--text3);margin-left:8px">${new Date(n.created_at).toLocaleString('en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}</span>
                    </div>
                    <p style="margin:4px 0 0;font-size:.79rem;color:var(--text2)">${n.note}</p>
                </div>
            </div>`;
        });
        $('#notesList').html(html);
    });
}

$('#saveNote').on('click', function () {
    const note = $('#noteInput').val().trim();
    if (!note) return;
    $.post(baseUrl + '/notes', { note }).done(function () {
        $('#noteInput').val('');
        loadNotes();
    });
});

// ── Status Update ──────────────────────────────────────────────────────────
$(document).on('click', '.btn-status', function () {
    $.post('{{ route("clients.status", $client) }}', { status: $(this).data('status') })
     .done(function (r) {
        location.reload();
     });
});

$('#transferConfirm').on('click', function () {
    var ownerId = $('#transferNewOwner').val();
    if (!ownerId) { Swal.fire('Select a staff member', '', 'warning'); return; }
    $.post('{{ route("clients.transfer", $client) }}', { new_owner_id: ownerId, note: $('#transferNote').val() })
     .done(function () { location.reload(); })
     .fail(function (xhr) { Swal.fire('Error', xhr.responseJSON?.message || 'Transfer failed.', 'error'); });
});

// ── Load on tab show ──────────────────────────────────────────────────────────
$('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
    const target = $(e.target).attr('href');
    history.replaceState(null, '', target);
    if (target === '#tab-workflow') loadWorkflow();
    if (target === '#tab-product')  loadProducts();
    if (target === '#tab-payments') loadPayments();
    if (target === '#tab-docs')     loadDocs();
    if (target === '#tab-notes')    loadNotes();
    if (target === '#tab-meetings') loadMeetings();
    if (target === '#tab-activity') { loadActivity(); loadOwnershipHistory(); }
});

// ── Meetings ──────────────────────────────────────────────────────────────────
const meetingBase = baseUrl + '/meetings';
var _mMap = {}; // keyed by meeting id, stores full meeting objects

var meetingStatusCls = {
    'Pending':     { extra: 'spill-pending',   icon: 'bi-hourglass-split' },
    'Scheduled':   { extra: 'spill-running',   icon: 'bi-clock' },
    'Completed':   { extra: 'spill-completed', icon: 'bi-check-circle-fill' },
    'Cancelled':   { extra: 'spill-cancelled', icon: 'bi-x-circle-fill' },
    'No Show':     { extra: 'spill-rejected',  icon: 'bi-person-x-fill' },
    'Rescheduled': { extra: 'spill-warning',   icon: 'bi-arrow-repeat' },
};
var openMeetingStatuses = ['Pending', 'Scheduled', 'Rescheduled'];
var typeIcons = { in_person: 'bi-person-fill', phone: 'bi-telephone-fill', video: 'bi-camera-video-fill', online: 'bi-globe' };
var canManageWorkflow = @json(auth()->user()->can('manage-workflow'));
var canManageMeetings = @json(auth()->user()->can('manage-meetings'));
var canUpdateClient   = @json(auth()->user()->can('update', $client));
var currentUserId = {{ auth()->id() }};

$('#meetingAssignedTo').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#addMeetingModal') });

function loadMeetings() {
    $('#meetingsList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(meetingBase)
    .done(function(res) {
        var items = res.data || [];
        _mMap = {};
        items.forEach(function(m) { _mMap[m.id] = m; });

        if (!items.length) {
            var bookLink = canManageMeetings
                ? '<a href="{{ route("meetings.book") }}?client_id={{ $client->id }}" class="btn btn-sm btn-primary mt-3"><i class="bi bi-plus-lg me-1"></i>Book a Meeting</a>'
                : '';
            $('#meetingsList').html('<div class="text-center py-5"><i class="bi bi-calendar-x" style="font-size:2rem;color:var(--text3)"></i><div class="mt-2" style="font-size:.82rem;color:var(--text3)">No meetings scheduled yet</div>' + bookLink + '</div>');
            return;
        }
        var upcoming = items.filter(function(m) { return openMeetingStatuses.indexOf(m.status) !== -1; });
        var past     = items.filter(function(m) { return openMeetingStatuses.indexOf(m.status) === -1; });
        var html = '';
        if (upcoming.length) {
            html += '<div class="px-3 pt-3 pb-1"><small style="font-size:.69rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">Upcoming</small></div>';
            upcoming.forEach(function(m) { html += meetingCard(m); });
        }
        if (past.length) {
            html += '<div class="px-3 pt-3 pb-1 border-top"><small style="font-size:.69rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">Past</small></div>';
            past.forEach(function(m) { html += meetingCard(m); });
        }
        $('#meetingsList').html(html);
    })
    .fail(function(r) {
        var msg = (r.status === 401 || r.status === 403) ? 'Access denied.' : 'Failed to load meetings (HTTP ' + r.status + ')';
        $('#meetingsList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>' + msg + '</div>');
    });
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function meetingCard(m) {
    var st    = meetingStatusCls[m.status] || { extra: 'spill-hold', icon: 'bi-circle' };
    var tIcon = typeIcons[m.type] || 'bi-calendar-event';
    var parts = m.scheduled_date ? m.scheduled_date.split(' ') : ['', '', ''];
    var day   = parts[0], mon = parts.slice(1).join(' ');
    var overdue = m.is_overdue ? '<span class="spill spill-cancelled ms-1" style="font-size:.6rem">Overdue</span>' : '';

    var isOpen    = openMeetingStatuses.indexOf(m.status) !== -1;
    var canAct    = canManageMeetings || currentUserId === m.assigned_to;

    var actions = '';
    if (isOpen) {
        if (canAct) {
            actions += '<button class="btn btn-sm btn-success py-0 px-2 m-btn-complete" data-id="' + m.id + '" style="font-size:.72rem"><i class="bi bi-check-lg me-1"></i>Done</button>';
            actions += '<button class="btn btn-sm py-0 px-2 m-btn-noshow" data-id="' + m.id + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:.72rem" title="Mark No Show"><i class="bi bi-person-x"></i></button>';
        }
        if (canManageMeetings) {
            actions += '<button class="btn btn-sm py-0 px-2 m-btn-edit" data-id="' + m.id + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:.72rem" title="Reschedule"><i class="bi bi-pencil"></i></button>';
            actions += '<button class="btn btn-sm py-0 px-2 m-btn-cancel c-red" data-id="' + m.id + '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg);font-size:.72rem"><i class="bi bi-x-lg"></i></button>';
        }
    } else if (canManageWorkflow && m.status !== 'Completed') {
        actions += '<button class="btn btn-sm py-0 px-2 m-btn-force-complete" data-id="' + m.id + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:.72rem" title="Force Complete (Admin)"><i class="bi bi-shield-check"></i></button>';
    }
    if (canManageWorkflow) {
        actions += '<button class="btn btn-sm py-0 px-2 m-btn-regenerate" data-id="' + m.id + '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:.72rem" title="Regenerate Meet Link (Admin)"><i class="bi bi-arrow-repeat"></i></button>';
    }
    if (canManageMeetings) {
        actions += '<button class="btn btn-sm py-0 px-2 m-btn-delete c-red" data-id="' + m.id + '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg);font-size:.72rem"><i class="bi bi-trash"></i></button>';
    }

    var loc  = m.location ? '<span><i class="bi bi-geo-alt me-1"></i>' + esc(m.location) + '</span>' : '';
    var link = '';
    if (m.join_url) {
        link = '<a href="' + esc(m.join_url) + '" target="_blank" style="color:var(--primary)"><i class="bi bi-box-arrow-up-right me-1"></i>Join</a>' +
               '<a href="#" class="m-btn-copy-link" data-link="' + esc(m.join_url) + '" style="color:var(--text3)" title="Copy link"><i class="bi bi-clipboard"></i></a>';
    }
    var googleBadge = m.google_meet_url ? '<span class="badge" style="background:rgba(16,185,129,.12);color:#059669;font-size:.6rem"><i class="bi bi-camera-video-fill me-1"></i>Google Meet</span>' : '';
    var agd  = m.agenda ? '<div class="mt-1" style="font-size:.74rem;color:var(--text2)">' + esc(m.agenda) + '</div>' : '';
    var nts  = (m.notes && m.status === 'Completed') ? '<div class="mt-2 p-2 rounded" style="background:var(--surface2);border:1px solid var(--border);font-size:.74rem;color:var(--text2)"><i class="bi bi-journal-text me-1"></i>' + esc(m.notes) + '</div>' : '';

    return '<div class="d-flex align-items-start gap-3 px-3 py-3 border-bottom">' +
        '<div class="text-center flex-shrink-0" style="width:44px">' +
            '<div style="font-size:1.05rem;font-weight:700;color:var(--primary);line-height:1">' + day + '</div>' +
            '<div style="font-size:.66rem;color:var(--text3);text-transform:uppercase">' + mon + '</div>' +
        '</div>' +
        '<div class="flex-grow-1 min-w-0">' +
            '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                '<span class="fw-semibold" style="font-size:.85rem;color:var(--text)">' + esc(m.title) + '</span>' +
                '<span class="spill ' + st.extra + '" style="font-size:.63rem"><i class="bi ' + st.icon + ' me-1"></i>' + m.status + '</span>' +
                overdue + googleBadge +
            '</div>' +
            '<div class="d-flex align-items-center gap-3 mt-1 flex-wrap" style="font-size:.73rem;color:var(--text3)">' +
                '<span><i class="bi ' + tIcon + ' me-1"></i>' + m.type_label + '</span>' +
                '<span><i class="bi bi-clock me-1"></i>' + (m.scheduled_time || '') + ' &middot; ' + m.duration_human + '</span>' +
                loc + link +
            '</div>' +
            agd + nts +
            '<div class="mt-1" style="font-size:.68rem;color:var(--text3)">By ' + esc(m.created_by_name || '') + (m.assigned_to_name ? ' &middot; Assigned to ' + esc(m.assigned_to_name) : '') + '</div>' +
        '</div>' +
        '<div class="d-flex gap-1 flex-shrink-0">' + actions + '</div>' +
    '</div>';
}

// ── Meeting form helpers ───────────────────────────────────────────────────────
function meetingFormData() {
    return {
        title:            $('#meetingTitle').val().trim(),
        type:             $('#meetingType').val(),
        scheduled_at:     $('#meetingDatetime').val(),
        duration_minutes: parseInt($('#meetingDuration').val(), 10),
        location:         $('#meetingLocation').val().trim(),
        assigned_to:      $('#meetingAssignedTo').val() || '',
        agenda:           $('#meetingAgenda').val().trim(),
    };
}

function resetMeetingModal() {
    $('#meetingEditId').val('');
    $('#meetingTitle,#meetingLocation,#meetingAgenda').val('');
    $('#meetingType').val('in_person').trigger('change');
    $('#meetingDuration').val('60');
    $('#meetingDatetime').val('');
    $('#meetingAssignedTo').val('').trigger('change');
    $('#meetingConflictWarn').addClass('d-none').text('');
    $('#meetingModalTitle').html('<i class="bi bi-calendar-plus me-2"></i>Schedule Meeting');
}

$('#meetingType').on('change', function() {
    var needsLink = ['video', 'online'].indexOf($(this).val()) !== -1;
    $('#meetingLinkWrap').toggleClass('d-none', !needsLink);
    $('#meetingLocationWrap').toggleClass('d-none', needsLink);
});

$('#addMeetingBtn').on('click', function() { resetMeetingModal(); });

// Conflict check on datetime/duration change
var conflictTimer;
function debounceConflict() {
    clearTimeout(conflictTimer);
    var dt  = $('#meetingDatetime').val();
    var dur = $('#meetingDuration').val();
    if (!dt || !dur) return;
    conflictTimer = setTimeout(function() {
        $.post('/meetings/check-conflict', {
            client_id:        clientId,
            scheduled_at:     dt,
            duration_minutes: dur,
            exclude_id:       $('#meetingEditId').val() || null,
        }, 'json')
        .done(function(r) {
            if (r.conflict) {
                $('#meetingConflictWarn').removeClass('d-none').html('<i class="bi bi-exclamation-triangle-fill me-1"></i>Conflict: "' + esc(r.title) + '" at ' + esc(r.time) + ' (' + esc(r.duration) + ')');
            } else {
                $('#meetingConflictWarn').addClass('d-none').text('');
            }
        });
    }, 600);
}
$('#meetingDatetime, #meetingDuration').on('change input', debounceConflict);

$('#saveMeetingBtn').on('click', function() {
    var editId = $('#meetingEditId').val();
    var data   = meetingFormData();
    if (!data.title || !data.scheduled_at) {
        Swal.fire('Required', 'Please fill in Title and Date/Time.', 'warning');
        return;
    }
    var url    = editId ? (meetingBase + '/' + editId) : meetingBase;
    var method = editId ? 'PUT' : 'POST';
    var $btn   = $(this);
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    $.ajax({ url: url, type: method, data: data, headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
    .done(function() {
        bootstrap.Modal.getInstance('#addMeetingModal').hide();
        resetMeetingModal();
        loadMeetings();
        Swal.fire({ icon: 'success', title: 'Meeting Saved', timer: 1400, showConfirmButton: false });
    })
    .fail(function(r) {
        var msg = r.responseJSON && r.responseJSON.errors
            ? Object.values(r.responseJSON.errors).flat().join('<br>')
            : (r.responseJSON && r.responseJSON.message ? r.responseJSON.message : 'Failed to save');
        Swal.fire({ icon: 'error', title: 'Error', html: msg });
    })
    .always(function() { $btn.prop('disabled', false).html('<i class="bi bi-calendar-check me-1"></i>Save Meeting'); });
});

// Edit: look up meeting from _mMap
$(document).on('click', '.m-btn-edit', function() {
    var m = _mMap[$(this).data('id')];
    if (!m) return;
    resetMeetingModal();
    $('#meetingEditId').val(m.id);
    $('#meetingTitle').val(m.title);
    $('#meetingType').val(m.type).trigger('change');
    $('#meetingDuration').val(m.duration_minutes);
    $('#meetingDatetime').val(m.scheduled_at);
    $('#meetingLocation').val(m.location || '');
    $('#meetingAssignedTo').val(m.assigned_to || '').trigger('change');
    $('#meetingAgenda').val(m.agenda || '');
    $('#meetingModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Meeting');
    new bootstrap.Modal('#addMeetingModal').show();
});

$(document).on('click', '.m-btn-complete', function() {
    $('#completeMeetingId').val($(this).data('id'));
    $('#completeMeetingNotes').val('');
    new bootstrap.Modal('#completeMeetingModal').show();
});

$('#confirmCompleteBtn').on('click', function() {
    var id   = $('#completeMeetingId').val();
    var $btn = $(this);
    $btn.prop('disabled', true);
    $.ajax({
        url: meetingBase + '/' + id + '/complete',
        type: 'POST',
        data: { notes: $('#completeMeetingNotes').val() },
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
    })
    .done(function() {
        bootstrap.Modal.getInstance('#completeMeetingModal').hide();
        loadMeetings();
        Swal.fire({ icon: 'success', title: 'Completed!', timer: 1400, showConfirmButton: false });
    })
    .fail(function(r) { Swal.fire('Error', (r.responseJSON && r.responseJSON.message) || 'Failed', 'error'); })
    .always(function() { $btn.prop('disabled', false); });
});

$(document).on('click', '.m-btn-cancel', function() {
    var id = $(this).data('id');
    Swal.fire({ title: 'Cancel this meeting?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, cancel it', confirmButtonColor: '#dc3545' })
    .then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: meetingBase + '/' + id + '/cancel', type: 'POST', headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
        .done(function() { loadMeetings(); });
    });
});

$(document).on('click', '.m-btn-noshow', function() {
    var id = $(this).data('id');
    Swal.fire({ title: 'Mark as No Show?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, mark it', confirmButtonColor: '#dc3545' })
    .then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: meetingBase + '/' + id + '/no-show', type: 'POST', headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
        .done(function() { loadMeetings(); })
        .fail(function(r) { Swal.fire('Error', (r.responseJSON && r.responseJSON.message) || 'Failed', 'error'); });
    });
});

$(document).on('click', '.m-btn-force-complete', function() {
    var id = $(this).data('id');
    Swal.fire({ title: 'Force complete this meeting?', text: 'Admin override — bypasses the normal completion flow.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, force complete', confirmButtonColor: '#dc3545' })
    .then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: meetingBase + '/' + id + '/force-complete', type: 'POST', headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
        .done(function() { loadMeetings(); Swal.fire({ icon: 'success', title: 'Force Completed', timer: 1400, showConfirmButton: false }); })
        .fail(function(r) { Swal.fire('Error', (r.responseJSON && r.responseJSON.message) || 'Failed', 'error'); });
    });
});

$(document).on('click', '.m-btn-regenerate', function() {
    var id = $(this).data('id');
    Swal.fire({ title: 'Regenerate Meet link?', text: 'This replaces the existing Google Calendar event and link.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, regenerate', confirmButtonColor: '#dc3545' })
    .then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: meetingBase + '/' + id + '/regenerate-link', type: 'POST', headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
        .done(function() { loadMeetings(); Swal.fire({ icon: 'success', title: 'Link Regenerated', timer: 1400, showConfirmButton: false }); })
        .fail(function(r) { Swal.fire('Error', (r.responseJSON && r.responseJSON.message) || 'Failed', 'error'); });
    });
});

$(document).on('click', '.m-btn-copy-link', function(e) {
    e.preventDefault();
    var link = $(this).data('link');
    navigator.clipboard.writeText(link).then(function() {
        Swal.fire({ icon: 'success', title: 'Link copied', timer: 1000, showConfirmButton: false });
    });
});

$(document).on('click', '.m-btn-delete', function() {
    var id = $(this).data('id');
    Swal.fire({ title: 'Delete meeting?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: meetingBase + '/' + id, type: 'DELETE', headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
        .done(function() {
            loadMeetings();
            Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
        });
    });
});

// ── Restore active tab from URL hash on load/reload ───────────────────────────
(function restoreTabFromHash() {
    const target = location.hash;
    const trigger = target && document.querySelector(`#clientTabs a[href="${target}"]`);
    if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
    // Workflow is the default-active tab, so it never receives a 'shown.bs.tab'
    // event unless another tab was showing first — load it explicitly here.
    if (!target || target === '#tab-workflow') loadWorkflow();
})();
</script>
@endpush