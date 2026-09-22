{{--
    Keeps the filter pill counts honest.

    Every DataTables response carries a `counts` block built from the same query
    the rows came from, so the numbers move with each filter, each edit and each
    refresh — no second request, and never a count that disagrees with the list.
    A quiet reload on a timer picks up what other people have changed.

    Usage, after the table is initialised:
        livePillCounts('#tasksTable', { extra: [{ selector: '#pillOverdue', key: 'overdue' }] });
--}}
@once
@push('scripts')
<script>
/**
 * @param {string} tableSelector
 * @param {object} [options]  { extra: [{selector, key, always}], every, pillSelector }
 */
window.livePillCounts = function (tableSelector, options) {
    options = options || {};
    const $table = $(tableSelector);
    if (!$table.length) return;

    function count($pill, n) {
        let $c = $pill.find('.fcnt');
        if (!$c.length) $c = $('<span class="fcnt"></span>').appendTo($pill);
        $c.text(n);
        return $c;
    }

    function paint(counts) {
        if (!counts) return;

        $(options.pillSelector || '.fpill[data-status]').each(function () {
            const status = $(this).data('status');
            count($(this), status === '' || status === undefined
                ? (counts.total || 0)
                : ((counts.status || {})[status] || 0));
        });

        (options.extra || []).forEach(function (pill) {
            const $pill = $(pill.selector);
            if (!$pill.length) return;
            const n = counts[pill.key] || 0;
            // These pills read better with no number at all when there is none.
            if (!n && !pill.always) { $pill.find('.fcnt').remove(); return; }
            count($pill, n);
        });
    }

    $table.on('xhr.dt', function (e, settings, json) { paint(json && json.counts); });

    // Somebody else's work should show up without a manual refresh — but never
    // under a dialog, or while a filter is being typed into.
    setInterval(function () {
        if (document.hidden || $('.modal.show').length) return;
        if ($(document.activeElement).is('input, textarea, select')) return;

        // false = keep the current page and scroll position.
        $table.DataTable().ajax.reload(null, false);
    }, options.every || 45000);
};
</script>
@endpush
@endonce
