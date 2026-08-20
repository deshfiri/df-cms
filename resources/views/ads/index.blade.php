@extends('layouts.app')
@section('title', 'Ad Campaigns')

@section('content')

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="page-title mb-0"><i class="bi bi-megaphone me-2"></i>Ad Campaigns</h4>
        </div>
        @can('create', \App\Models\AdCampaign::class)
            <button class="btn btn-sm btn-primary" id="newCampaignBtn" data-bs-toggle="modal" data-bs-target="#campaignModal">
                <i class="bi bi-plus-lg me-1"></i>New Campaign
            </button>
        @endcan
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-green">{{ $totals['active_count'] }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Active
                    Campaigns</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">৳{{ number_format($totals['total_budget'], 0) }}
                </div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Budget
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-red">৳{{ number_format($totals['total_spend'], 0) }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total Spend
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-yellow">{{ $totals['overall_roas'] ?? '—' }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Overall ROAS
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <button class="fpill" data-status="" id="pillAll">All</button>
        @php $statusCls = ['Active' => 'spill-running', 'Paused' => 'spill-warning', 'Completed' => 'spill-completed', 'Cancelled' => 'spill-cancelled']; @endphp
        @foreach($statusCls as $st => $cls)
            <button class="fpill" data-status="{{ $st }}">
                <span class="spill {{ $cls }}" style="padding:1px 7px;font-size:.65rem">{{ $st }}</span>
            </button>
        @endforeach

        <div class="ms-auto" style="width:240px">
            <select id="filterClient" class="form-select form-select-sm">
                <option value="">All Clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                @endforeach
            </select>
        </div>
        <div style="width:200px">
            <select id="filterBrand" class="form-select form-select-sm" disabled>
                <option value="">All Brands</option>
            </select>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="campaignsTable" class="table table-hover align-middle w-100 mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Client</th>
                            <th>Brand</th>
                            <th>Assigned</th>
                            <th>Status</th>
                            <th>Budget</th>
                            <th>Spend</th>
                            <th>ROAS</th>
                            <th width="90" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    {{-- New Campaign Modal --}}
    <div class="modal fade" id="campaignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold"><i class="bi bi-plus-lg me-2"></i>New Ad Campaign</h6>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Client <span class="text-danger">*</span></label>
                            <select id="campClient" class="form-select">
                                <option value="">Select client...</option>
                                @foreach($clients as $c)
                                    <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Brand</label>
                            <select id="campBrand" class="form-select" disabled>
                                <option value="">No Brand / General</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                            <input type="text" id="campName" class="form-control" placeholder="e.g. Eid Collection Launch">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Platform</label>
                            <select id="campPlatform" class="form-select">
                                <option value="">Select...</option>
                                @foreach(\App\Models\AdCampaign::$platforms as $p)
                                    <option value="{{ $p }}">{{ $p }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Status</label>
                            <select id="campStatus" class="form-select">
                                @foreach(\App\Models\AdCampaign::$statuses as $s)
                                    <option value="{{ $s }}">{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Budget</label>
                            <input type="number" id="campBudget" class="form-control" placeholder="0.00" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Assigned To</label>
                            <select id="campAssignedTo" class="form-select">
                                <option value="">Unassigned</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Start Date</label>
                            <input type="date" id="campStartDate" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">End Date</label>
                            <input type="date" id="campEndDate" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Remarks</label>
                            <textarea id="campRemarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button id="saveCampaignBtn" class="btn btn-sm btn-primary"><i
                            class="bi bi-check me-1"></i>Save</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        var activeStatus = '';

        function syncCampaignPills() {
            $('.fpill').removeClass('active');
            if (!activeStatus) { $('#pillAll').addClass('active'); return; }
            $('.fpill[data-status="' + activeStatus + '"]').addClass('active');
        }
        syncCampaignPills();

        $('.fpill').on('click', function () {
            activeStatus = $(this).data('status') || '';
            syncCampaignPills();
            window.cTable.ajax.reload();
        });

        function loadBrandOptions($select, clientId, placeholder) {
            $select.html('<option value="">' + placeholder + '</option>');
            if (!clientId) { $select.prop('disabled', true).trigger('change'); return; }
            $select.prop('disabled', false);
            $.get('/clients/' + clientId + '/brands').done(function (r) {
                r.data.forEach(function (b) {
                    $select.append($('<option>').val(b.id).text(b.name));
                });
                $select.trigger('change');
            });
        }

        $('#filterClient').on('change', function () {
            loadBrandOptions($('#filterBrand'), $(this).val(), 'All Brands');
            window.cTable.ajax.reload();
        });

        $('#filterBrand').on('change', function () { window.cTable.ajax.reload(); });

        $('#campClient').on('change', function () {
            loadBrandOptions($('#campBrand'), $(this).val(), 'No Brand / General');
        });

        $(function () {
            $('#filterClient').select2({ theme: 'bootstrap-5', width: '100%' });
            $('#filterBrand').select2({ theme: 'bootstrap-5', width: '100%' });
            $('#campClient').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#campaignModal') });
            $('#campBrand').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#campaignModal') });
            $('#campAssignedTo').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#campaignModal') });

            window.cTable = $('#campaignsTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route("ads.index") }}',
                    data: function (d) {
                        d.status = activeStatus;
                        d.client_id = $('#filterClient').val();
                        d.brand_id = $('#filterBrand').val();
                    }
                },
                columns: [
                    { data: 'DT_RowIndex', orderable: false, searchable: false },
                    { data: 'client', orderable: false },
                    { data: 'brand', orderable: false },
                    { data: 'assigned', orderable: false },
                    { data: 'status_badge', orderable: false },
                    { data: 'budget_fmt', orderable: false },
                    { data: 'spend_fmt', orderable: false },
                    { data: 'roas_fmt', orderable: false },
                    { data: 'actions', orderable: false, searchable: false, className: 'text-end pe-3' },
                ]
            });
        });

        $('#newCampaignBtn').on('click', function () {
            $('#campClient,#campAssignedTo').val('').trigger('change');
            $('#campName,#campBudget,#campStartDate,#campEndDate,#campRemarks').val('');
            $('#campPlatform').val('');
            $('#campStatus').val('Active');
            loadBrandOptions($('#campBrand'), '', 'No Brand / General');
        });

        $('#saveCampaignBtn').on('click', function () {
            var clientId = $('#campClient').val();
            var name = $('#campName').val().trim();
            if (!clientId || !name) {
                Swal.fire('Missing fields', 'Please select a client and enter a campaign name.', 'warning');
                return;
            }
            $.post('{{ route("ads.store") }}', {
                client_id: clientId,
                brand_id: $('#campBrand').val(),
                name: name,
                platform: $('#campPlatform').val(),
                status: $('#campStatus').val(),
                budget: $('#campBudget').val(),
                assigned_to: $('#campAssignedTo').val(),
                start_date: $('#campStartDate').val(),
                end_date: $('#campEndDate').val(),
                remarks: $('#campRemarks').val()
            }).done(function () {
                bootstrap.Modal.getInstance('#campaignModal').hide();
                window.cTable.ajax.reload();
                Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
            }).fail(function (r) {
                Swal.fire('Error', r.responseJSON?.message || 'Could not save campaign.', 'error');
            });
        });

        // ── Delete a campaign ─────────────────────────────────────────────
        // The button only exists for users the policy allows, and the endpoint
        // checks again. Campaigns are soft-deleted, so the wording promises
        // removal from the list rather than destruction.
        $(document).on('click', '.campaign-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            Swal.fire({
                icon: 'warning',
                title: 'Delete this campaign?',
                html: '<strong>' + $('<div>').text(name).html() + '</strong>'
                    + '<div style="font-size:.85rem;margin-top:.5rem">It is removed from the list and from the totals above.'
                    + ' The record and its daily reports are retained in the database, not erased.</div>',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                confirmButtonColor: '#dc3545',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                $.ajax({ url: '{{ url("ads") }}/' + id, type: 'DELETE' })
                    .done(function () {
                        window.cTable.ajax.reload();
                        Swal.fire({ icon: 'success', title: 'Campaign deleted', timer: 1300, showConfirmButton: false });
                    })
                    .fail(function (r) {
                        Swal.fire('Error', r.responseJSON?.message || 'Could not delete campaign.', 'error');
                    });
            });
        });
    </script>
@endpush