@extends('layouts.portal')
@section('title', 'Documents')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Documents</h5>
    @if($submittableTypes->isNotEmpty())
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal"><i class="bi bi-upload me-1"></i>Upload Document</button>
    @endif
</div>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-0">
    <table class="table mb-0">
        <thead>
            <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Uploaded</th>
                <th>Version</th>
                <th>Size</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse($documents as $doc)
            <tr>
                <td><i class="bi {{ $doc->icon }} me-2" style="color:var(--text3)"></i>{{ $doc->title }}</td>
                <td>{{ $doc->documentType?->name ?? '—' }}</td>
                <td style="font-size:.78rem;color:var(--text2)">{{ $doc->created_at->format('d M Y') }}</td>
                <td>v{{ $doc->version }}</td>
                <td style="font-size:.78rem;color:var(--text3)">{{ $doc->file_size_human }}</td>
                <td>
                    @if($doc->is_client_submitted)
                        <span class="spill {{ $doc->client_review_status === 'Approved' ? 'spill-green' : ($doc->client_review_status === 'Rejected' ? 'spill-red' : 'spill-yellow') }}">{{ $doc->client_review_status }}</span>
                    @else
                        <span class="spill spill-gray">Shared</span>
                    @endif
                </td>
                <td class="text-end">
                    <a href="{{ route('portal.documents.preview', $doc) }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    <a href="{{ route('portal.documents.download', $doc) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4" style="color:var(--text3)">No documents shared with you yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($submittableTypes->isNotEmpty())
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('portal.documents.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title">Upload Document</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small">Category</label>
                        <select name="document_type_id" class="form-select" required>
                            @foreach($submittableTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">File</label>
                        <input type="file" name="file" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
