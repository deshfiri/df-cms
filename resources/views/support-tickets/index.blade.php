@extends('layouts.app')
@section('title', 'Support Tickets')

@section('content')
<h4 class="page-title mb-3">Support Tickets</h4>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="ticketsTable" class="table table-hover align-middle w-100 mb-0">
                <thead>
                    <tr><th>#</th><th>Ticket #</th><th>Client</th><th>Subject</th><th>Category</th><th>Status</th><th>Assigned</th></tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#ticketsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: '{{ route("support-tickets.index") }}' },
        columns: [
            { data: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'ticket_number', orderable: false, searchable: false },
            { data: 'client', orderable: false, searchable: false },
            { data: 'subject', orderable: false, searchable: false },
            { data: 'category', orderable: false, searchable: false },
            { data: 'status', orderable: false, searchable: false },
            { data: 'assigned', orderable: false, searchable: false },
        ],
        createdRow: function (row, data) {
            $(row).css('cursor', 'pointer').on('click', function () {
                window.location = '{{ url("support-tickets") }}/' + data.id;
            });
        },
    });
});
</script>
@endpush
