@extends('layouts.app')
@section('title', 'New Client')

@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('clients.index') }}" class="btn btn-sm btn-light border">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="page-title mb-0">Add New Client</h4>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card section-card">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>Client Information</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('clients.store') }}" id="clientForm">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">DFID Number</label>
                            <input type="text" name="dfid_number" value="{{ old('dfid_number', $nextDfid) }}"
                                   class="form-control @error('dfid_number') is-invalid @enderror"
                                   placeholder="{{ $nextDfid }}">
                            @error('dfid_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Client Name <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" value="{{ old('client_name') }}" required
                                   class="form-control @error('client_name') is-invalid @enderror"
                                   placeholder="Full client name">
                            @error('client_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Brand Name <span class="text-danger">*</span></label>
                            <input type="text" name="brand_name" value="{{ old('brand_name') }}" required
                                   class="form-control @error('brand_name') is-invalid @enderror"
                                   placeholder="Brand / shop name">
                            @error('brand_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Website Link</label>
                            <input type="url" name="website" value="{{ old('website') }}"
                                   class="form-control @error('website') is-invalid @enderror"
                                   placeholder="https://...">
                            @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Page Link (Facebook/Brand Page)</label>
                            <input type="url" name="page_link" value="{{ old('page_link') }}"
                                   class="form-control @error('page_link') is-invalid @enderror"
                                   placeholder="https://facebook.com/...">
                            @error('page_link')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Designs Link</label>
                            <input type="url" name="designs_link" value="{{ old('designs_link') }}"
                                   class="form-control @error('designs_link') is-invalid @enderror"
                                   placeholder="Figma, Canva, Drive…">
                            @error('designs_link')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Category <span class="text-danger">*</span></label>
                            <select name="category_id" required
                                    class="form-select select2 @error('category_id') is-invalid @enderror">
                                <option value="">Select category</option>
                                @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Joining Date</label>
                            <input type="date" name="joining_date" value="{{ old('joining_date', date('Y-m-d')) }}"
                                   class="form-control @error('joining_date') is-invalid @enderror">
                            @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Client Status</label>
                            <select name="client_status" class="form-select">
                                @foreach($statuses as $s)
                                @continue($s === 'Terminated')
                                <option value="{{ $s }}" {{ old('client_status', 'Running') == $s ? 'selected' : '' }}>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Assigned To</label>
                            <select name="assigned_to" class="form-select select2">
                                <option value="">Unassigned</option>
                                @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ old('assigned_to') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">DOC Status</label>
                            <select name="doc_status" class="form-select">
                                <option value="">—</option>
                                <option value="DONE" {{ old('doc_status') == 'DONE' ? 'selected' : '' }}>DONE</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Notes / Remarks</label>
                            <textarea name="remarks" rows="3" class="form-control"
                                      placeholder="Any additional notes...">{{ old('remarks') }}</textarea>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Create Client
                        </button>
                        <a href="{{ route('clients.index') }}" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card section-card">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Quick Guide</h6>
            </div>
            <div class="card-body">
                <ul class="list-unstyled small text-muted mb-0">
                    <li class="mb-2"><i class="bi bi-check2 text-success me-1"></i>DFID is auto-generated if left blank</li>
                    <li class="mb-2"><i class="bi bi-check2 text-success me-1"></i>Workflow stages are automatically created</li>
                    <li class="mb-2"><i class="bi bi-check2 text-success me-1"></i>Progress is calculated automatically</li>
                    <li class="mb-2"><i class="bi bi-check2 text-success me-1"></i>You can add payments and products after creation</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
});
</script>
@endpush
