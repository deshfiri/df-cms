{{--
    Correcting, deleting and auditing a payment.

    Every correction needs a reason. An approver's own applies at once (200);
    anyone else's is sent for approval (202) and the payment is untouched until
    a Super Admin or Manager approves it. Whatever happened is on the history.

        PaymentCorrection.edit(payment, updateUrl, onDone)
        PaymentCorrection.remove(deleteUrl, onDone)
        PaymentCorrection.history(historyUrl)
--}}
@once
@push('scripts')
<script>
window.PaymentCorrection = (function () {
    const STATUSES = {{ Js::from(\App\Models\Payment::$statuses) }};
    const esc = s => $('<div>').text(s == null ? '' : String(s)).html();

    function errorText(x) {
        const errors = x.responseJSON && x.responseJSON.errors;
        if (errors) return Object.values(errors).flat().join(' ');
        if (x.status === 419) return 'Your session has expired. Refresh the page and try again.';
        return (x.responseJSON && x.responseJSON.message) || 'Something went wrong. Please try again.';
    }

    /** 200 applied, 202 sent for approval — say which. */
    function announce(res, xhr) {
        if (xhr.status === 202) {
            Swal.fire({ icon: 'info', title: 'Sent for approval', text: res.message });
        } else {
            Swal.fire({ icon: 'success', title: res.message || 'Done', timer: 1500, showConfirmButton: false });
        }
    }

    function field(label, html) {
        return '<div class="mb-2 text-start"><label class="form-label small fw-semibold mb-1">' + label + '</label>' + html + '</div>';
    }

    function edit(p, url, onDone) {
        const standalone = !p.invoice_id;
        const date = p.payment_date ? String(p.payment_date).substring(0, 10) : '';

        Swal.fire({
            title: 'Correct payment',
            width: 560,
            html: '<div class="text-start" style="font-size:.78rem;color:var(--text3)" class="mb-2">'
                    + (p.invoice ? 'Against <strong>' + esc(p.invoice.invoice_number) + '</strong>. ' : '')
                    + 'Changes by anyone other than a Super Admin or Manager wait for their approval.</div>'
                + '<div class="row g-2 mt-1">'
                +   '<div class="col-6">' + field('Amount', '<input id="pcAmount" type="number" step="0.01" min="0" class="form-control form-control-sm" value="' + esc(p.amount) + '">') + '</div>'
                +   '<div class="col-6">' + field('Payment date', '<input id="pcDate" type="date" class="form-control form-control-sm" value="' + esc(date) + '">') + '</div>'
                +   '<div class="col-6">' + field('Method', '<input id="pcMethod" type="text" maxlength="100" class="form-control form-control-sm" value="' + esc(p.payment_method) + '">') + '</div>'
                +   '<div class="col-6">' + field('Transaction #', '<input id="pcTxn" type="text" maxlength="100" class="form-control form-control-sm" value="' + esc(p.transaction_number) + '">') + '</div>'
                +   (standalone
                        ? '<div class="col-12">' + field('Status', '<select id="pcStatus" class="form-select form-select-sm">'
                            + STATUSES.map(s => '<option' + (s === p.status ? ' selected' : '') + '>' + esc(s) + '</option>').join('') + '</select>') + '</div>'
                        : '')
                +   '<div class="col-12">' + field('Remarks', '<textarea id="pcRemarks" rows="2" class="form-control form-control-sm">' + esc(p.remarks) + '</textarea>') + '</div>'
                +   '<div class="col-12">' + field('Reason for the change <span class="text-danger">*</span>', '<textarea id="pcReason" rows="2" maxlength="1000" class="form-control form-control-sm" placeholder="e.g. Amount was entered wrong — bank statement shows ৳12,000"></textarea>') + '</div>'
                + '</div>',
            showCancelButton: true,
            confirmButtonText: 'Save correction',
            focusConfirm: false,
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            preConfirm: function () {
                const reason = $.trim($('#pcReason').val());
                if (reason.length < 3) { Swal.showValidationMessage('Say why this payment is being changed — it is kept on the record.'); return false; }

                const data = {
                    amount: $('#pcAmount').val(),
                    payment_date: $('#pcDate').val() || null,
                    payment_method: $('#pcMethod').val(),
                    transaction_number: $('#pcTxn').val(),
                    remarks: $('#pcRemarks').val(),
                    reason: reason,
                };
                if (standalone) data.status = $('#pcStatus').val();

                return new Promise(function (resolve) {
                    $.ajax({ url: url, type: 'PUT', data: data })
                        .done((res, _s, xhr) => resolve({ res, xhr }))
                        .fail(x => { Swal.showValidationMessage(errorText(x)); resolve(false); });
                });
            },
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            announce(r.value.res, r.value.xhr);
            if (onDone) onDone();
        });
    }

    function remove(url, onDone) {
        Swal.fire({
            title: 'Delete payment?',
            html: '<div style="font-size:.85rem" class="mb-2">If it was paid against a charge, that charge\'s balance goes back up. '
                + 'Unless you are a Super Admin or Manager, it is only deleted once one of them approves.</div>',
            input: 'textarea',
            inputLabel: 'Reason (kept on the record)',
            inputPlaceholder: 'e.g. Recorded twice',
            inputAttributes: { maxlength: 1000 },
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Delete',
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            preConfirm: function (reason) {
                if ($.trim(reason || '').length < 3) { Swal.showValidationMessage('Give a reason — it is kept on the record.'); return false; }
                return new Promise(function (resolve) {
                    $.ajax({ url: url, type: 'DELETE', data: { reason: $.trim(reason) } })
                        .done((res, _s, xhr) => resolve({ res, xhr }))
                        .fail(x => { Swal.showValidationMessage(errorText(x)); resolve(false); });
                });
            },
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            announce(r.value.res, r.value.xhr);
            if (onDone) onDone();
        });
    }

    const statusSpill = { pending: 'spill-warning', approved: 'spill-completed', applied: 'spill-completed', rejected: 'spill-cancelled' };
    const when = iso => iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
    const show = v => (v === null || v === undefined || v === '') ? '<em style="color:var(--text3)">empty</em>' : esc(v);

    function history(url) {
        Swal.fire({ title: 'Payment history', html: '<div class="py-3"><span class="spinner-border spinner-border-sm"></span></div>', showConfirmButton: false, showCloseButton: true, width: 640 });

        $.getJSON(url).done(function (r) {
            const rows = r.data || [];
            const html = rows.length ? rows.map(function (c) {
                const what = c.action === 'delete'
                    ? '<div class="small c-red"><i class="bi bi-trash me-1"></i>Delete the payment (' + esc(c.snapshot && c.snapshot.amount) + ')</div>'
                    : c.changes.map(ch => '<div class="small"><strong>' + esc(ch.label) + ':</strong> '
                        + '<span style="text-decoration:line-through;color:var(--text3)">' + show(ch.from) + '</span> → <strong style="color:var(--primary)">' + show(ch.to) + '</strong></div>').join('');

                return '<div class="text-start p-2 mb-2 rounded" style="border:1px solid var(--border);background:var(--surface2)">'
                    + '<div class="d-flex justify-content-between align-items-center gap-2 mb-1">'
                    +   '<span class="spill ' + (statusSpill[c.status] || 'spill-hold') + '">' + esc(c.status_label) + '</span>'
                    +   '<span style="font-size:.7rem;color:var(--text3)">#' + c.id + '</span>'
                    + '</div>'
                    + what
                    + '<div class="small mt-1" style="color:var(--text2)"><strong>Reason:</strong> ' + show(c.reason) + '</div>'
                    + '<div style="font-size:.72rem;color:var(--text3)" class="mt-1">Requested by ' + esc(c.requested_by || '—') + ' · ' + when(c.requested_at) + '</div>'
                    + (c.reviewed_at
                        ? '<div style="font-size:.72rem;color:var(--text3)">' + (c.status === 'rejected' ? 'Rejected' : (c.status === 'applied' ? 'Applied' : 'Approved')) + ' by ' + esc(c.reviewed_by || '—') + ' · ' + when(c.reviewed_at) + '</div>'
                        : '')
                    + (c.review_note ? '<div style="font-size:.72rem;color:var(--text2)">Note: ' + esc(c.review_note) + '</div>' : '')
                    + '</div>';
            }).join('') : '<div class="small py-3" style="color:var(--text3)">No corrections have been requested for this payment.</div>';

            Swal.update({ html: '<div style="max-height:60vh;overflow:auto">' + html + '</div>' });
        }).fail(function (x) {
            Swal.update({ html: '<div class="small c-red py-3">' + esc(errorText(x)) + '</div>' });
        });
    }

    return { edit, remove, history };
})();
</script>
@endpush
@endonce
