{{--
    Image thumbnails, a lightbox, and "copy image" for attachments.

    Any element with data-preview-src opens the lightbox:
        <button data-preview-src="{preview url}" data-preview-name="logo.png"
                data-download-src="{download url}">…</button>

    Images are fetched through the app's own authorized preview routes, never
    straight from storage, so viewing follows exactly the same permission as
    downloading.

    Copying: the modern Clipboard API needs a secure page (HTTPS or localhost).
    Opened over plain http on the LAN it is unavailable, so the fallback selects
    the image and uses the browser's own copy command; if that is refused too, it
    says so and explains how to copy by hand — never a silent "copied".
--}}
@once
@push('styles')
<style>
    .fp-thumb {
        width: 44px; height: 44px; border-radius: 8px; overflow: hidden; flex-shrink: 0;
        border: 1px solid var(--border); background: var(--surface2); padding: 0; cursor: zoom-in;
        display: grid; place-items: center;
    }
    .fp-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .fp-thumb:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    .fp-overlay {
        position: fixed; inset: 0; z-index: 2000; display: flex; flex-direction: column;
        background: rgba(10, 12, 18, .88); backdrop-filter: blur(2px);
        padding: calc(env(safe-area-inset-top, 0px) + 12px) 16px calc(env(safe-area-inset-bottom, 0px) + 12px);
    }
    .fp-overlay[hidden] { display: none; }
    .fp-bar { display: flex; align-items: center; gap: .5rem; color: #e5e7eb; flex-wrap: wrap; }
    .fp-name { font-size: .85rem; font-weight: 600; flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .fp-btn {
        border: 1px solid rgba(255,255,255,.18); background: rgba(255,255,255,.08); color: #f3f4f6;
        border-radius: 8px; font-size: .78rem; padding: .35rem .7rem; display: inline-flex; align-items: center; gap: .35rem;
        text-decoration: none; cursor: pointer;
    }
    .fp-btn:hover { background: rgba(255,255,255,.16); color: #fff; }
    .fp-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
    .fp-stage { flex: 1 1 auto; display: grid; place-items: center; min-height: 0; padding-top: 12px; }
    .fp-stage img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; box-shadow: 0 10px 40px rgba(0,0,0,.5); background: #fff; }
    .fp-status { color: #d1d5db; font-size: .8rem; }
    .fp-copy-host { position: fixed; left: -9999px; top: 0; opacity: 0; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    let overlay, img, nameEl, downloadEl, copyEl, statusEl, lastFocus, current = null;

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'fp-overlay';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Image preview');
        overlay.innerHTML =
            '<div class="fp-bar">'
            + '<span class="fp-name"></span>'
            + '<button type="button" class="fp-btn" data-fp="copy"><i class="bi bi-clipboard"></i><span>Copy image</span></button>'
            + '<a class="fp-btn" data-fp="download" href="#"><i class="bi bi-download"></i><span>Download</span></a>'
            + '<button type="button" class="fp-btn" data-fp="close" aria-label="Close preview"><i class="bi bi-x-lg"></i></button>'
            + '</div>'
            + '<div class="fp-stage"><span class="fp-status">Loading…</span><img alt="" hidden></div>';
        document.body.appendChild(overlay);

        img        = overlay.querySelector('.fp-stage img');
        nameEl     = overlay.querySelector('.fp-name');
        downloadEl = overlay.querySelector('[data-fp="download"]');
        copyEl     = overlay.querySelector('[data-fp="copy"]');
        statusEl   = overlay.querySelector('.fp-status');

        overlay.addEventListener('click', function (e) {
            if (e.target.closest('[data-fp="close"]') || e.target === overlay || e.target.classList.contains('fp-stage')) close();
            if (e.target.closest('[data-fp="copy"]')) copy();
        });
        img.addEventListener('load', function () { statusEl.hidden = true; img.hidden = false; copyEl.disabled = false; });
        img.addEventListener('error', function () {
            img.hidden = true; statusEl.hidden = false; copyEl.disabled = true;
            statusEl.textContent = 'This image could not be loaded. Try downloading it instead.';
        });
        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            // Keep keyboard focus inside the dialog.
            if (e.key === 'Tab') {
                const focusable = Array.from(overlay.querySelectorAll('button:not([disabled]), a[href]'));
                const first = focusable[0], last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    }

    function open(src, name, downloadSrc) {
        build();
        current = { src: src, name: name || 'image' };
        lastFocus = document.activeElement;
        nameEl.textContent = current.name;
        statusEl.textContent = 'Loading…';
        statusEl.hidden = false;
        img.hidden = true;
        copyEl.disabled = true;
        img.alt = current.name;
        img.src = src;
        downloadEl.hidden = !downloadSrc;
        if (downloadSrc) downloadEl.href = downloadSrc;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        overlay.querySelector('[data-fp="close"]').focus();
    }

    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        img.removeAttribute('src');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function toast(icon, title, text) {
        if (window.Swal) Swal.fire({ toast: true, position: 'bottom-end', icon: icon, title: title, text: text, showConfirmButton: false, timer: text ? 5000 : 1800 });
    }

    /** The loaded image re-encoded as PNG — the one image type every clipboard accepts. */
    function pngBlob() {
        return new Promise(function (resolve, reject) {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);
            canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('encode')); }, 'image/png');
        });
    }

    /** Older route: select the picture itself and ask the browser to copy the selection. */
    function legacyCopy() {
        const host = document.createElement('div');
        host.className = 'fp-copy-host';
        host.contentEditable = 'true';
        const clone = new Image();
        clone.src = img.src;
        host.appendChild(clone);
        overlay.appendChild(host);

        const range = document.createRange();
        range.selectNode(clone);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }

        selection.removeAllRanges();
        host.remove();
        return ok;
    }

    function copy() {
        if (!current || img.hidden) return;

        const manual = 'Right-click the image (or long-press on a phone) and choose "Copy image".';

        if (window.isSecureContext && navigator.clipboard && window.ClipboardItem) {
            // The blob is handed over as a promise so Safari still counts the
            // write as part of the click.
            navigator.clipboard.write([new ClipboardItem({ 'image/png': pngBlob() })])
                .then(function () { toast('success', 'Image copied'); })
                .catch(function () {
                    legacyCopy() ? toast('success', 'Image copied') : toast('info', 'Copy was blocked by the browser', manual);
                });
            return;
        }

        legacyCopy()
            ? toast('success', 'Image copied')
            : toast('info', 'Copying images needs a secure (https) connection', manual);
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-preview-src]');
        if (!trigger) return;
        e.preventDefault();
        open(trigger.dataset.previewSrc, trigger.dataset.previewName, trigger.dataset.downloadSrc);
    });

    window.FilePreview = { open: open, close: close };
})();
</script>
@endpush
@endonce
