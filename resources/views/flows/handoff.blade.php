{{--
    Shared hand-off dialog, used by My Queue and the item page.

    Fetches the destination stage's members so the sender can address the work
    to one specific person — or leave it open for the whole stage to claim.
    Resolves true when the move happened, false when cancelled or refused.
--}}
<script>
(function () {
    const esc = s => $('<div>').text(s == null ? '' : s).html();

    function personPicker(stage, verb) {
        if (!stage.users.length) {
            return '<div style="font-size:.74rem;color:#f59e0b;text-align:left;margin-bottom:.7rem">'
                 + '<i class="bi bi-exclamation-triangle me-1"></i>Nobody is assigned to <strong>' + esc(stage.name)
                 + '</strong> yet — it will wait there until an admin assigns someone.</div>';
        }

        let html = '<label class="form-label fw-semibold small d-block text-start mb-1">' + verb + '</label>'
                 + '<select id="fhUser" class="form-select form-select-sm mb-3">'
                 + '<option value="">Anyone on ' + esc(stage.name) + ' — first to claim it</option>';

        stage.users.forEach(function (u) {
            html += '<option value="' + u.id + '">' + esc(u.name)
                 +  (u.load ? ' · ' + u.load + ' item' + (u.load > 1 ? 's' : '') + ' in hand' : ' · free')
                 +  '</option>';
        });

        return html + '</select>';
    }

    function body(stage, arrow, verb, noteLabel, notePlaceholder) {
        return '<div style="font-size:.78rem;color:var(--text3);margin-bottom:.7rem;text-align:left">'
             +   arrow + ' <strong style="color:var(--text2)">' + esc(stage.name) + '</strong></div>'
             + personPicker(stage, verb)
             + '<label class="form-label fw-semibold small d-block text-start mb-1">' + noteLabel + '</label>'
             + '<textarea id="fhNote" class="form-control form-control-sm" rows="3" maxlength="2000" placeholder="'
             +   notePlaceholder + '"></textarea>';
    }

    function post(url, payload) {
        return $.post(url, payload)
            .then(() => true)
            .catch(function (x) {
                Swal.fire('Error', x.responseJSON?.message || 'Could not move the item.', 'error');
                return false;
            });
    }

    /**
     * @param {number} itemId
     * @param {'advance'|'back'} mode
     * @returns {Promise<boolean>} true once the item actually moved
     */
    window.flowHandoff = function (itemId, mode) {
        return $.get('/flow-items/' + itemId + '/handoff').then(function (data) {
            const stage = mode === 'advance' ? data.next : data.previous;

            // Last stage: forwarding finishes the item, so there is nobody to pick.
            if (mode === 'advance' && !stage) {
                return Swal.fire({
                    title: 'Complete this item?',
                    text: 'This is the final stage — sending it forward marks the item completed.',
                    icon: 'question',
                    input: 'textarea',
                    inputPlaceholder: 'Closing note (optional)…',
                    showCancelButton: true,
                    confirmButtonText: 'Complete item',
                }).then(res => res.isConfirmed
                    ? post('/flow-items/' + itemId + '/advance', { note: res.value || '' })
                    : false);
            }

            if (!stage) {
                Swal.fire('Nowhere to send it', 'This is the first stage — there is no earlier stage to return to.', 'info');
                return false;
            }

            const back = mode === 'back';

            return Swal.fire({
                title: back ? 'Send back a stage?' : 'Send to next stage',
                html: back
                    ? body(stage, '<i class="bi bi-arrow-left"></i> Back to', 'Who should fix it?', 'Reason <span style="color:#dc3545">*</span>', 'Why is it going back? (required)')
                    : body(stage, '<i class="bi bi-arrow-right"></i> Next stage:', 'Send to', 'Note <span style="color:var(--text3)">(optional)</span>', 'Anything the next person should know…'),
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: back ? 'Send back' : 'Send forward',
                confirmButtonColor: back ? '#dc3545' : undefined,
                preConfirm: function () {
                    const note = ($('#fhNote').val() || '').trim();
                    if (back && !note) {
                        Swal.showValidationMessage('A reason is required when sending work back.');
                        return false;
                    }
                    return { assign_to: $('#fhUser').val() || '', note: note };
                },
            }).then(function (res) {
                if (!res.isConfirmed) return false;

                return back
                    ? post('/flow-items/' + itemId + '/send-back', { reason: res.value.note, assign_to: res.value.assign_to })
                    : post('/flow-items/' + itemId + '/advance', { note: res.value.note, assign_to: res.value.assign_to });
            });
        }).catch(function () {
            Swal.fire('Error', 'Could not load the next stage.', 'error');
            return false;
        });
    };
})();
</script>
