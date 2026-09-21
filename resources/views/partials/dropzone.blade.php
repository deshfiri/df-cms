{{--
    Drag-and-drop wrapper for a plain file input.

    The input keeps its id, name, accept rules and every handler already bound to
    it — dropping a file just fills it in and fires `change`, so the upload code
    behind it does not change at all.

    Usage (after the input exists):
        makeDropzone('#taskFileInput', { hint: 'Up to 20 MB' });
--}}
@once
@push('styles')
<style>
    .dzone {
        border: 1.5px dashed var(--border);
        border-radius: var(--radius);
        background: var(--surface2);
        padding: .8rem .9rem;
        text-align: center;
        cursor: pointer;
        transition: border-color .12s, background .12s;
    }
    .dzone:hover { border-color: var(--primary); }
    .dzone.is-over {
        border-color: var(--primary);
        background: rgba(var(--primary-rgb), .08);
    }
    .dzone.is-disabled { opacity: .6; pointer-events: none; }
    .dzone > input[type="file"] { display: none !important; }

    .dzone-icon { display: block; font-size: 1.25rem; color: var(--text3); line-height: 1; margin-bottom: .3rem; }
    .dzone.is-over .dzone-icon { color: var(--primary); }
    .dzone-text { font-size: .78rem; color: var(--text2); }
    .dzone-text u { text-underline-offset: 2px; }
    .dzone-hint { font-size: .68rem; color: var(--text3); margin-top: 2px; }

    .dzone-picked {
        display: flex; align-items: center; justify-content: center; gap: .45rem;
        font-size: .78rem; color: var(--text); word-break: break-all;
    }
    .dzone-picked .bi-file-earmark-check { color: var(--c-green); }
    .dzone-clear { background: none; border: 0; padding: 0; color: var(--text3); line-height: 1; }
    .dzone-clear:hover { color: var(--c-red); }
</style>
@endpush

@push('scripts')
<script>
(function () {
    // A file dropped outside a drop zone would otherwise navigate away from the
    // page and lose whatever was half-written on it.
    ['dragover', 'drop'].forEach(function (type) {
        window.addEventListener(type, function (e) {
            if (!e.target.closest || !e.target.closest('.dzone')) e.preventDefault();
        });
    });

    function human(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    /**
     * @param {string|Element} input   the file input to wrap
     * @param {object} [options]       {text, hint}
     */
    window.makeDropzone = function (input, options) {
        const el = typeof input === 'string' ? document.querySelector(input) : input;
        if (!el || el.dataset.dzone === '1') return;
        options = options || {};

        const zone = document.createElement('div');
        zone.className = 'dzone';
        zone.innerHTML =
            '<div class="dzone-idle">'
            + '<i class="bi bi-cloud-arrow-up dzone-icon"></i>'
            + '<div class="dzone-text">' + (options.text || (el.multiple
                ? 'Drag &amp; drop files here, or <u>browse</u> — several at once is fine'
                : 'Drag &amp; drop a file here, or <u>browse</u>')) + '</div>'
            + (options.hint ? '<div class="dzone-hint">' + options.hint + '</div>' : '')
            + '</div>'
            + '<div class="dzone-picked" hidden></div>';

        el.parentNode.insertBefore(zone, el);
        zone.appendChild(el);
        el.dataset.dzone = '1';

        const idle   = zone.querySelector('.dzone-idle');
        const picked = zone.querySelector('.dzone-picked');

        function show() {
            const files = el.files ? Array.from(el.files) : [];
            const file = files[0];
            idle.hidden = !!file;
            picked.hidden = !file;
            if (file) {
                const total = files.reduce((sum, f) => sum + f.size, 0);
                picked.innerHTML = '<i class="bi bi-file-earmark-check"></i>'
                    + '<span>' + (files.length > 1 ? files.length + ' files' : $('<div>').text(file.name).html()) + '</span>'
                    + '<span style="color:var(--text3)">' + human(total) + '</span>'
                    + '<button type="button" class="dzone-clear" title="Remove"><i class="bi bi-x-lg"></i></button>';
            }
        }

        zone.addEventListener('click', function (e) {
            if (e.target.closest('.dzone-clear')) {
                e.stopPropagation();
                el.value = '';
                // Native event, so handlers bound either way hear about it.
                el.dispatchEvent(new Event('change', { bubbles: true }));
                show();
                return;
            }
            el.click();
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (!el.disabled) zone.classList.add('is-over');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            zone.addEventListener(type, function (e) {
                if (e.target === zone || !zone.contains(e.relatedTarget)) zone.classList.remove('is-over');
            });
        });

        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('is-over');
            if (el.disabled) return;

            const files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) return;

            // As many files as the browse dialog would allow for this input.
            const transfer = new DataTransfer();
            Array.from(el.multiple ? files : [files[0]]).forEach(f => transfer.items.add(f));
            el.files = transfer.files;

            show();
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Native listener: page code often calls $(input).off('change') when it
        // rebinds its own upload handler, which would take a jQuery one with it.
        el.addEventListener('change', show);

        // Mirrors the input being disabled while an upload is in flight.
        new MutationObserver(function () {
            zone.classList.toggle('is-disabled', el.disabled);
        }).observe(el, { attributes: true, attributeFilter: ['disabled'] });

        show();
    };
})();
</script>
@endpush
@endonce
