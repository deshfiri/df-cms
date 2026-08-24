@extends('layouts.app')
@section('title', 'Marketing')

@push('styles')
<style>
    .mk-brand-card {
        display: block; text-decoration: none; height: 100%;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: var(--space-4);
        transition: transform .15s, border-color .15s, box-shadow .15s;
    }
    .mk-brand-card:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: var(--shadow-md); }
    .mk-brand-name { font-size: .95rem; font-weight: 700; color: var(--text); }
    .mk-brand-client { font-size: .74rem; color: var(--text3); }
    .mk-platforms { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .7rem; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-megaphone me-2"></i>Marketing</h4>
        <small style="color:var(--text3)">Pick a brand to see its advertising performance.</small>
    </div>
    @if($canManage)
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newBrandModal">
            <i class="bi bi-plus-lg me-1"></i>New Brand
        </button>
    @endif
</div>

<div class="row g-3">
    @forelse($brands as $brand)
        @php
            $meta = $brand->integrations->firstWhere('platform', 'meta');
        @endphp
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('marketing.brand', $brand) }}" class="mk-brand-card">
                <div class="mk-brand-name">{{ $brand->name }}</div>
                <div class="mk-brand-client">{{ $brand->client->client_name ?? '—' }}</div>

                <div class="mk-platforms">
                    @if($meta && $meta->status === 'connected')
                        <span class="spill spill-completed"><i class="bi bi-meta me-1"></i>Meta connected</span>
                    @elseif($meta && $meta->status === 'token_expired')
                        <span class="spill spill-warning"><i class="bi bi-exclamation-triangle me-1"></i>Meta needs reconnecting</span>
                    @else
                        <span class="spill spill-hold"><i class="bi bi-plug me-1"></i>No platform connected</span>
                    @endif
                </div>

                @if($meta?->last_synced_at)
                    <div style="font-size:.7rem;color:var(--text3);margin-top:.5rem">
                        Last sync {{ $meta->last_synced_at->diffForHumans() }}
                    </div>
                @endif
            </a>
        </div>
    @empty
        <div class="col-12">
            <div class="card section-card">
                <div class="card-body text-center py-5" style="color:var(--text3)">
                    <i class="bi bi-megaphone" style="font-size:2.4rem"></i>
                    <div class="mt-2" style="font-size:.9rem">
                        @if($canManage)
                            No brands yet. Create one for any client to start tracking its advertising.
                        @else
                            No brands yet. Brands are created on a client's profile, under the Brands tab.
                        @endif
                    </div>
                    @if($canManage)
                        <button class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#newBrandModal">
                            <i class="bi bi-plus-lg me-1"></i>New Brand
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endforelse
</div>

@if($canManage)
    {{-- New Brand: the client is chosen here rather than implied by the page,
         so a brand can be opened on behalf of any client without leaving Marketing. --}}
    <div class="modal fade" id="newBrandModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--surface);border:1px solid var(--border)">
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-bold"><i class="bi bi-tags me-1"></i>New Brand</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="newBrandForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Client <span style="color:var(--c-red)">*</span></label>
                            {{-- No `required` here: Select2 hides the real <select>, and
                                 native validation on a hidden control blocks submit
                                 with an unfocusable-element error. Checked in JS instead. --}}
                            <select id="nbClient" class="form-select">
                                <option value="">Select a client…</option>
                                @foreach($clients as $c)
                                    <option value="{{ $c->id }}">{{ $c->client_name }}@if($c->dfid_number) — {{ $c->dfid_number }}@endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Brand Name <span style="color:var(--c-red)">*</span></label>
                            <input type="text" id="nbName" class="form-control" maxlength="150" placeholder="e.g. Acme Coffee" required>
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold small">Remarks</label>
                            <textarea id="nbRemarks" class="form-control" rows="2" maxlength="1000" placeholder="Optional"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary" id="nbSubmit">Create Brand</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@push('scripts')
@if($canManage)
<script>
    $(function () {
        var brandBase = '{{ url('marketing/brands') }}';

        $('#nbClient').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#newBrandModal'),
            placeholder: 'Select a client…',
        });

        $('#newBrandModal').on('hidden.bs.modal', function () {
            $('#newBrandForm')[0].reset();
            $('#nbClient').val('').trigger('change');
        });

        $('#newBrandForm').on('submit', function (e) {
            e.preventDefault();

            var clientId = $('#nbClient').val();
            var name = $('#nbName').val().trim();
            if (!clientId || !name) {
                Swal.fire('Missing details', 'Choose a client and give the brand a name.', 'warning');
                return;
            }

            var $btn = $('#nbSubmit').prop('disabled', true);

            $.post('{{ url('clients') }}/' + clientId + '/brands', {
                name: name,
                remarks: $('#nbRemarks').val().trim(),
            }).done(function (r) {
                // Straight to the new brand — connecting a platform is the next step.
                window.location = brandBase + '/' + r.data.id;
            }).fail(function (xhr) {
                var errors = xhr.responseJSON && xhr.responseJSON.errors;
                var message = errors
                    ? Object.values(errors)[0][0]
                    : ((xhr.responseJSON && xhr.responseJSON.message) || 'Could not create the brand.');
                Swal.fire('Error', message, 'error');
                $btn.prop('disabled', false);
            });
        });
    });
</script>
@endif
@endpush
