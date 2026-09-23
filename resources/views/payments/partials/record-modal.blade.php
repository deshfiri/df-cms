{{--
    Record Payment dialog, shared by the client's Payments tab and the Payments page.

    A payment goes one of three ways, chosen in the "Charge" picker:
      - against an open charge   → balance shown, amount capped at what's due;
      - "+ New charge"           → bill a category and take a first payment in one go
                                   ("Social Media Ads ৳20,000, ৳10,000 paid today");
      - standalone               → the long-standing Paid / Partial / Unpaid record.

    The server re-checks every one of those rules under a lock; the hints here are
    only so nobody has to find out by getting an error.

    @param string $modalId           element id for the modal
    @param bool   $withClientPicker  show a client select (Payments page)
    @param iterable $clients         required with the picker
--}}
<div class="modal fade rp-modal" id="{{ $modalId }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2"></i>Record Payment</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    @if($withClientPicker)
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Client <span style="color:var(--c-red)">*</span></label>
                            <select data-rp="client" class="form-select">
                                <option value="">Select client...</option>
                                @foreach($clients as $c)
                                    <option value="{{ $c->id }}">{{ $c->client_name }} ({{ $c->dfid_number }})</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="col-md-5">
                        <label class="form-label fw-semibold small">Category</label>
                        <select data-rp="category" class="form-select"></select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-semibold small">Paying against</label>
                        <select data-rp="charge" class="form-select"></select>
                    </div>

                    <div class="col-12" data-rp="chargeInfo" hidden></div>

                    <div class="col-12" data-rp="newCharge" hidden>
                        <div class="rp-newcharge">
                            <div class="rp-newcharge-title"><i class="bi bi-receipt me-1"></i>New charge</div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Total to be paid <span style="color:var(--c-red)">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">৳</span>
                                        <input type="number" data-rp="chargeTotal" class="form-control" min="0.01" step="0.01" placeholder="20000">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Due date</label>
                                    <input type="date" data-rp="chargeDue" class="form-control form-control-sm">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Title</label>
                                    <input type="text" data-rp="chargeTitle" class="form-control form-control-sm" maxlength="200" placeholder="e.g. Facebook ads — October">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold small" data-rp="amountLabel">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">৳</span>
                            <input type="number" data-rp="amount" class="form-control" placeholder="0.00" min="0" step="0.01">
                        </div>
                        <button type="button" class="rp-link" data-rp="payFull" hidden>Pay full balance</button>
                    </div>
                    <div class="col-md-6" data-rp="statusWrap">
                        <label class="form-label fw-semibold small">Status <span style="color:var(--c-red)">*</span></label>
                        <select data-rp="status" class="form-select">
                            @foreach(\App\Models\Payment::$statuses as $s)
                                <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12" data-rp="after"></div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Payment Date</label>
                        <input type="date" data-rp="date" class="form-control" value="{{ date('Y-m-d') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Method</label>
                        <select data-rp="method" class="form-select">
                            <option value="">Select...</option>
                            @foreach(\App\Models\Payment::$methods as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Transaction Number</label>
                        <input type="text" data-rp="txn" class="form-control" maxlength="100" placeholder="Ref / Transaction ID">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Remarks</label>
                        <textarea data-rp="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button data-rp="save" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save Payment</button>
            </div>
        </div>
    </div>
</div>

@once
@push('styles')
<style>
    .rp-newcharge {
        background: var(--surface2); border: 1px dashed var(--border);
        border-radius: var(--radius); padding: .7rem .8rem;
    }
    .rp-newcharge-title { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); margin-bottom: .45rem; }
    .rp-charge {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius); padding: .6rem .8rem;
    }
    .rp-charge-k { font-size: .64rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); }
    .rp-charge-v { font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
    .rp-charge-bar { grid-column: 1 / -1; height: 6px; border-radius: 6px; background: var(--border); overflow: hidden; }
    .rp-charge-bar > span { display: block; height: 100%; background: var(--c-green); }
    .rp-link { background: none; border: 0; padding: 0; margin-top: 4px; font-size: .74rem; color: var(--primary); }
    .rp-link:hover { text-decoration: underline; }
    .rp-hint { font-size: .78rem; color: var(--text2); }
    .rp-hint.ok { color: var(--c-green); }
    .rp-hint.bad { color: var(--c-red); }
</style>
@endpush

@push('scripts')
<script>
/**
 * Wires one Record Payment dialog.
 *
 * @param {object} opts
 * @param {string}   opts.modal       selector of the modal
 * @param {Array}    opts.categories  active [{id, name}]
 * @param {Function} opts.clientId    () => the client being paid for, or null
 * @param {Function} opts.storeUrl    (clientId) => POST url
 * @param {Function} opts.chargesUrl  (clientId) => GET url for the client's charges
 * @param {boolean}  opts.sendClient  include client_id in the payload
 * @param {Function} opts.onSaved     called after a successful save
 */
window.RecordPayment = function (opts) {
    const $m = $(opts.modal);
    const el = k => $m.find('[data-rp="' + k + '"]');
    const esc = s => $('<div>').text(s == null ? '' : s).html();
    let categories = opts.categories || [];
    let charges = [];

    function catName(id) {
        const c = categories.find(x => String(x.id) === String(id));
        return c ? c.name : '';
    }

    function fillCategories() {
        const keep = el('category').val();
        el('category').html('<option value="">— No category —</option>'
            + categories.map(c => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join(''));
        el('category').val(keep || '');
    }

    function loadCharges() {
        const clientId = opts.clientId();
        charges = [];
        if (!clientId) { renderCharges(); return $.Deferred().resolve().promise(); }

        el('charge').html('<option>Loading…</option>');
        return $.get(opts.chargesUrl(clientId), { open: 1 })
            .done(r => { charges = r.data || []; })
            .always(() => renderCharges());
    }

    /** Charges in the chosen category, with a sensible default picked. */
    function renderCharges(preferId) {
        const cat  = el('category').val();
        const list = charges.filter(c => !cat || String(c.category ? c.category.id : '') === String(cat));

        let html = '<option value="">No charge — standalone payment</option>'
                 + '<option value="new">+ New charge' + (cat ? ' for ' + esc(catName(cat)) : '') + '</option>';
        list.forEach(function (c) {
            html += '<option value="' + c.id + '">' + esc(c.invoice_number)
                 +  (c.title ? ' · ' + esc(c.title) : (c.category && !cat ? ' · ' + esc(c.category.name) : ''))
                 +  ' — ' + rpMoney(c.due_amount) + ' due</option>';
        });
        el('charge').html(html);

        let pick = '';
        if (preferId && list.some(c => String(c.id) === String(preferId))) pick = preferId;
        else if (cat) pick = list.length ? list[0].id : 'new';
        el('charge').val(String(pick));

        syncMode();
    }

    function current() {
        const v = el('charge').val();
        return charges.find(c => String(c.id) === String(v)) || null;
    }

    function syncMode() {
        const isNew  = el('charge').val() === 'new';
        const charge = current();
        const linked = isNew || !!charge;

        el('newCharge').prop('hidden', !isNew);
        el('statusWrap').prop('hidden', linked);
        el('payFull').prop('hidden', !charge);
        el('amountLabel').html(linked ? 'Amount received <span style="color:var(--c-red)">*</span>' : 'Amount');

        if (charge) {
            const pct = charge.total_payable > 0 ? Math.min(100, Math.round(charge.paid_amount / charge.total_payable * 100)) : 0;
            el('chargeInfo').prop('hidden', false).html(
                '<div class="rp-charge">'
                + '<div><div class="rp-charge-k">Total</div><div class="rp-charge-v">' + rpMoney(charge.total_payable) + '</div></div>'
                + '<div><div class="rp-charge-k">Paid</div><div class="rp-charge-v" style="color:var(--c-green)">' + rpMoney(charge.paid_amount) + '</div></div>'
                + '<div><div class="rp-charge-k">Due</div><div class="rp-charge-v" style="color:var(--c-red)">' + rpMoney(charge.due_amount) + '</div></div>'
                + '<div class="rp-charge-bar"><span style="width:' + pct + '%"></span></div>'
                + '</div>');
            el('amount').attr('max', charge.due_amount);
        } else {
            el('chargeInfo').prop('hidden', true).empty();
            el('amount').removeAttr('max');
        }

        updateAfter();
    }

    /** "৳10,000 will still be due" — what this payment leaves behind. */
    function updateAfter() {
        const amount = parseFloat(el('amount').val()) || 0;
        const charge = current();
        let total = null, label = '';

        if (el('charge').val() === 'new') {
            total = parseFloat(el('chargeTotal').val()) || 0;
            label = 'the new charge';
        } else if (charge) {
            total = charge.due_amount;
            label = charge.invoice_number;
        }

        if (total === null || amount <= 0 || total <= 0) { el('after').empty(); return; }

        const left = Math.round((total - amount) * 100) / 100;
        const html = left < 0
            ? '<span class="rp-hint bad"><i class="bi bi-exclamation-circle me-1"></i>That is more than the ' + rpMoney(total) + ' due on ' + esc(label) + '.</span>'
            : left === 0
                ? '<span class="rp-hint ok"><i class="bi bi-check-circle me-1"></i>Settles ' + esc(label) + ' in full.</span>'
                : '<span class="rp-hint"><i class="bi bi-hourglass-split me-1"></i>' + rpMoney(left) + ' will still be due on ' + esc(label) + ' after this.</span>';
        el('after').html(html);
    }

    el('category').on('change', () => renderCharges());
    el('charge').on('change', function () {
        const charge = current();
        // Picking a charge from "all categories" settles which category it is.
        if (charge && charge.category && !el('category').val()) {
            el('category').val(String(charge.category.id));
            renderCharges(charge.id);
            return;
        }
        syncMode();
    });
    el('amount').add(el('chargeTotal')).on('input', updateAfter);
    el('payFull').on('click', function () {
        const charge = current();
        if (charge) { el('amount').val(charge.due_amount); updateAfter(); }
    });
    el('client').on('change', () => loadCharges());

    el('save').on('click', function () {
        const clientId = opts.clientId();
        if (!clientId) { Swal.fire('Missing client', 'Please select a client.', 'warning'); return; }

        const mode = el('charge').val();
        const payload = {
            payment_category_id: el('category').val(),
            amount:              el('amount').val(),
            payment_date:        el('date').val(),
            payment_method:      el('method').val(),
            transaction_number:  el('txn').val(),
            remarks:             el('remarks').val(),
        };
        if (opts.sendClient) payload.client_id = clientId;

        if (mode === 'new') {
            payload.charge_total    = el('chargeTotal').val();
            payload.charge_title    = el('chargeTitle').val();
            payload.charge_due_date = el('chargeDue').val();
        } else if (mode) {
            payload.invoice_id = mode;
        } else {
            payload.status = el('status').val();
        }

        const btn = $(this).prop('disabled', true);
        $.post(opts.storeUrl(clientId), payload)
            .done(function () {
                bootstrap.Modal.getOrCreateInstance($m[0]).hide();
                Swal.fire({ icon: 'success', title: 'Payment saved', timer: 1300, showConfirmButton: false });
                if (opts.onSaved) opts.onSaved();
            })
            .fail(x => Swal.fire({ icon: 'error', title: 'Could not save', html: rpErrorText(x) }))
            .always(() => btn.prop('disabled', false));
    });

    return {
        setCategories(list) { categories = list || []; fillCategories(); },

        /** preset: { categoryId, chargeId } — both optional */
        open(preset) {
            preset = preset || {};
            $m.find('input[type=number], input[type=text], textarea').val('');
            el('chargeDue').val('');
            el('date').val(rpToday());
            el('method').val('');
            el('status').val('Paid');
            el('after').empty();
            fillCategories();
            el('category').val(preset.categoryId ? String(preset.categoryId) : '');

            loadCharges().always(() => renderCharges(preset.chargeId));
            bootstrap.Modal.getOrCreateInstance($m[0]).show();
        },
    };
};

function rpMoney(n) {
    return '৳' + Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

/** Today in the viewer's own timezone — toISOString() would give UTC's date. */
function rpToday() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

/** Validation errors as readable lines rather than a bare "422". */
function rpErrorText(x) {
    const errors = x.responseJSON && x.responseJSON.errors;
    if (errors) {
        return Object.values(errors).flat().map(m => $('<div>').text(m).html()).join('<br>');
    }
    return (x.responseJSON && x.responseJSON.message) || 'Something went wrong.';
}
</script>
@endpush
@endonce
