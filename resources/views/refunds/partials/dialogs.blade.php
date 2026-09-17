{{--
    Refund dialogs, shared by the Refunds page and a client's Payments tab.

        Refunds.request(payment, onDone)   ask for a refund against a payment
        Refunds.open(id, onDone)           a refund's details, history and next steps

    The buttons offered come from the server's `can` flags for the viewer; the
    server enforces every rule again whatever is clicked.
--}}
@once
@push('styles')
<style>
    .rf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .9rem; text-align: left; font-size: .8rem; }
    .rf-grid .k { font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text3); }
    .rf-grid .v { color: var(--text); font-weight: 600; word-break: break-word; }
    .rf-tl { list-style: none; margin: .75rem 0 0; padding: 0 0 0 1.1rem; border-left: 2px solid var(--border); text-align: left; }
    .rf-tl li { position: relative; padding: 0 0 .65rem .6rem; font-size: .78rem; color: var(--text2); }
    .rf-tl li::before { content: ''; position: absolute; left: -1.46rem; top: .3rem; width: 10px; height: 10px; border-radius: 50%; background: var(--surface); border: 2px solid var(--primary); }
    .rf-tl .t { font-size: .68rem; color: var(--text3); }
    .rf-actions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; margin-top: 1rem; }
    .rf-reason { text-align: left; font-size: .8rem; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: .5rem .65rem; margin-top: .6rem; white-space: pre-wrap; }
</style>
@endpush

@push('scripts')
<script>
window.Refunds = (function () {
    const esc = s => $('<div>').text(s == null ? '' : String(s)).html();
    const money = n => '৳' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const when = iso => iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
    const spill = {
        requested: 'spill-warning', under_review: 'spill-in-progress', approved: 'spill-running', processing: 'spill-in-progress',
        completed: 'spill-completed', rejected: 'spill-cancelled', cancelled: 'spill-hold',
    };

    function errorText(x) {
        const errors = x.responseJSON && x.responseJSON.errors;
        if (errors) return Object.values(errors).flat().join(' ');
        if (x.status === 419) return 'Your session has expired. Refresh the page and try again.';
        if (x.status === 0) return 'The server could not be reached. Check your connection and try again.';
        return (x.responseJSON && x.responseJSON.message) || 'Something went wrong. Please try again.';
    }

    /** Send, keeping the dialog open with the reason when the server refuses. */
    function send(url, data) {
        return new Promise(function (resolve) {
            $.ajax({ url: url, type: 'POST', data: data })
                .done(res => resolve(res))
                .fail(x => { Swal.showValidationMessage(errorText(x)); resolve(false); });
        });
    }

    function request(payment, onDone) {
        const max = Number(payment.refundable_amount || 0);

        Swal.fire({
            title: 'Request a refund',
            html: '<div class="text-start" style="font-size:.8rem;color:var(--text2)">'
                +   'Payment of <strong>' + money(payment.amount) + '</strong>' + (payment.payment_date ? ' on ' + esc(String(payment.payment_date).substring(0, 10)) : '')
                +   ' · up to <strong>' + money(max) + '</strong> can be refunded.'
                +   '<div style="color:var(--text3)" class="mt-1">An approver decides, then it is paid out and confirmed with a reference. Nothing moves until then.</div>'
                + '</div>'
                + '<div class="text-start mt-3">'
                +   '<label class="form-label small fw-semibold mb-1" for="rfAmount">Amount</label>'
                +   '<input id="rfAmount" type="number" step="0.01" min="0.01" max="' + max + '" value="' + max + '" class="form-control form-control-sm">'
                +   '<label class="form-label small fw-semibold mb-1 mt-2" for="rfMethod">Pay back by <span style="color:var(--text3);font-weight:400">(optional)</span></label>'
                +   '<input id="rfMethod" type="text" maxlength="100" class="form-control form-control-sm" placeholder="e.g. bKash, bank transfer" value="' + esc(payment.payment_method || '') + '">'
                +   '<label class="form-label small fw-semibold mb-1 mt-2" for="rfReason">Reason <span class="text-danger">*</span></label>'
                +   '<textarea id="rfReason" rows="3" maxlength="2000" class="form-control form-control-sm" placeholder="Why is this money going back?"></textarea>'
                +   '<div class="small mt-2" style="color:var(--text3)">If this payment was against a charge, the charge\'s balance goes back up by what is refunded — lower its total if the client no longer owes it.</div>'
                + '</div>',
            showCancelButton: true,
            confirmButtonText: 'Request refund',
            focusConfirm: false,
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            preConfirm: function () {
                const amount = Number($('#rfAmount').val());
                if (!(amount > 0)) { Swal.showValidationMessage('Enter the amount to refund.'); return false; }
                if (amount > max + 0.001) { Swal.showValidationMessage('At most ' + money(max) + ' can be refunded from this payment.'); return false; }
                if ($.trim($('#rfReason').val()).length < 3) { Swal.showValidationMessage('Say why the money is going back — it is kept on the record.'); return false; }
                return send('/payments/' + payment.id + '/refunds', { amount: amount, method: $('#rfMethod').val(), reason: $.trim($('#rfReason').val()) });
            },
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            Swal.fire({ icon: 'success', title: r.value.refund.refund_number, text: r.value.message });
            if (onDone) onDone(r.value.refund);
        });
    }

    function row(label, value) {
        return '<div><div class="k">' + label + '</div><div class="v">' + value + '</div></div>';
    }

    function render(rf) {
        const events = (rf.events || []).map(e => '<li><strong style="color:var(--text)">' + esc(e.to_label) + '</strong> · ' + esc(e.by || '—')
            + (e.meta && e.meta.reference ? ' · ref ' + esc(e.meta.reference) : '')
            + (e.note ? '<div>' + esc(e.note) + '</div>' : '')
            + '<div class="t">' + when(e.at) + '</div></li>').join('');

        const buttons = [
            rf.can.review   ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-rf="review"><i class="bi bi-search me-1"></i>Start review</button>' : '',
            rf.can.approve  ? '<button type="button" class="btn btn-sm btn-success" data-rf="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>' : '',
            rf.can.reject   ? '<button type="button" class="btn btn-sm btn-outline-danger" data-rf="reject"><i class="bi bi-x-lg me-1"></i>Reject</button>' : '',
            rf.can.process  ? '<button type="button" class="btn btn-sm btn-primary" data-rf="process"><i class="bi bi-arrow-repeat me-1"></i>Start paying out</button>' : '',
            rf.can.complete ? '<button type="button" class="btn btn-sm btn-success" data-rf="complete"><i class="bi bi-check2-all me-1"></i>Mark paid back</button>' : '',
            rf.can.cancel   ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-rf="cancel"><i class="bi bi-slash-circle me-1"></i>Cancel refund</button>' : '',
        ].join('');

        const ownNote = rf.is_own && ['requested', 'under_review'].includes(rf.status)
            ? '<div class="small mt-2" style="color:var(--text3)"><i class="bi bi-person-lock me-1"></i>You asked for this refund, so another approver decides on it.</div>' : '';

        return '<div class="d-flex justify-content-center align-items-center gap-2 mb-3">'
            +   '<span class="spill ' + (spill[rf.status] || 'spill-hold') + '">' + esc(rf.status_label) + '</span>'
            +   '<strong style="font-size:1.25rem;color:var(--text)">' + money(rf.amount) + '</strong>'
            + '</div>'
            + '<div class="rf-grid">'
            +   row('Client', rf.client ? '<a href="/clients/' + rf.client.id + '#tab-payments">' + esc(rf.client.name) + '</a>' : '—')
            +   row('Payment', rf.payment ? money(rf.payment.amount) + ' · ' + esc(rf.payment.date || '') : '—')
            +   row('Charge', rf.invoice ? esc(rf.invoice.number) + (rf.invoice.title ? ' · ' + esc(rf.invoice.title) : '') : 'Standalone payment')
            +   row('Still refundable', rf.payment ? money(rf.payment.refundable) : '—')
            +   row('Requested', esc(rf.requested_by || '—') + '<div class="t" style="font-weight:400;color:var(--text3)">' + when(rf.requested_at) + '</div>')
            +   row('Decided', rf.decided_by ? esc(rf.decided_by) + '<div style="font-weight:400;color:var(--text3)">' + when(rf.decided_at) + '</div>' : '—')
            +   row('Method', esc(rf.method || '—'))
            +   row('Reference', esc(rf.reference || '—'))
            + '</div>'
            + '<div class="rf-reason"><strong>Reason:</strong> ' + esc(rf.reason) + '</div>'
            + (rf.decision_note ? '<div class="rf-reason"><strong>Decision note:</strong> ' + esc(rf.decision_note) + '</div>' : '')
            + (rf.cancel_reason ? '<div class="rf-reason"><strong>Cancelled:</strong> ' + esc(rf.cancel_reason) + ' — ' + esc(rf.cancelled_by || '') + '</div>' : '')
            + ownNote
            + (buttons ? '<div class="rf-actions">' + buttons + '</div>' : '')
            + '<div class="text-start fw-semibold mt-3" style="font-size:.78rem">History</div>'
            + '<ol class="rf-tl">' + (events || '<li>No history.</li>') + '</ol>';
    }

    const steps = {
        review:   { title: 'Start review',            input: 'textarea', label: 'Note (optional)', field: 'note', required: false },
        approve:  { title: 'Approve this refund?',    input: 'textarea', label: 'Note (optional)', field: 'note', required: false, confirm: 'Approve' },
        reject:   { title: 'Reject this refund',      input: 'textarea', label: 'Why? The requester sees this.', field: 'note', required: true, confirm: 'Reject', danger: true },
        process:  { title: 'Start paying out',        input: 'text',     label: 'Paying back by (optional)', field: 'method', required: false },
        complete: { title: 'Mark as paid back',       input: 'text',     label: 'Transaction reference', field: 'reference', required: true, confirm: 'Confirm paid back' },
        cancel:   { title: 'Cancel this refund',      input: 'textarea', label: 'Why? Kept on the record.', field: 'reason', required: true, confirm: 'Cancel refund', danger: true },
    };

    function act(rf, action, onDone) {
        const s = steps[action];
        Swal.fire({
            title: s.title,
            html: '<div style="font-size:.85rem">' + esc(rf.refund_number) + ' · ' + money(rf.amount) + '</div>',
            input: s.input,
            inputLabel: s.label,
            inputAttributes: { maxlength: s.field === 'reference' ? 150 : 2000 },
            showCancelButton: true,
            confirmButtonText: s.confirm || 'Save',
            confirmButtonColor: s.danger ? '#dc3545' : undefined,
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            preConfirm: function (value) {
                value = $.trim(value || '');
                if (s.required && value.length < (s.field === 'reference' ? 2 : 3)) {
                    Swal.showValidationMessage(s.field === 'reference' ? 'Enter the transaction reference.' : 'This needs a reason.');
                    return false;
                }
                const data = {};
                if (value) data[s.field] = value;
                return send('/refunds/' + rf.id + '/' + action, data);
            },
        }).then(function (r) {
            if (r.isConfirmed && r.value) {
                if (onDone) onDone(r.value.refund);
                show(r.value.refund, onDone, r.value.message);
            } else {
                show(rf, onDone);
            }
        });
    }

    function show(rf, onDone, flash) {
        Swal.fire({
            title: esc(rf.refund_number),
            html: (flash ? '<div class="small mb-2" style="color:var(--c-green)"><i class="bi bi-check-circle me-1"></i>' + esc(flash) + '</div>' : '') + render(rf),
            width: 640,
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: function (popup) {
                popup.querySelectorAll('[data-rf]').forEach(btn => btn.addEventListener('click', () => act(rf, btn.dataset.rf, onDone)));
            },
        });
    }

    function open(id, onDone) {
        Swal.fire({ title: 'Refund', html: '<div class="py-3"><span class="spinner-border spinner-border-sm"></span></div>', showConfirmButton: false, showCloseButton: true });
        $.getJSON('/refunds/' + id)
            .done(r => show(r.refund, onDone))
            .fail(x => Swal.fire('Could not open the refund', errorText(x), 'error'));
    }

    return { request, open, spill, money };
})();
</script>
@endpush
@endonce
