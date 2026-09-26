{{--
    Typing @ in a comment box offers a dropdown of mentionable people; picking
    one (or arrowing to it and hitting Enter/Tab) inserts their exact name.
    Notifications are decided server-side by re-scanning the saved comment for
    those exact names — this widget is purely a typing aid, not the source of
    truth for who gets notified.

    Usage, after the input/textarea exists:
        makeMentionAutocomplete('#taskCommentInput', [{id: 1, name: 'Jane Doe'}, ...]);
--}}
@push('styles')
<style>
    .mention-menu { position: absolute; z-index: 1060; min-width: 180px; max-width: 280px; max-height: 220px; overflow-y: auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-md); }
    .mention-menu-item { padding: .4rem .65rem; font-size: .8rem; color: var(--text2); cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mention-menu-item.active, .mention-menu-item:hover { background: var(--surface2); color: var(--text); }
    .mention { color: var(--primary); font-weight: 600; }
</style>
@endpush
@push('scripts')
<script>
    window.makeMentionAutocomplete = function (input, users) {
        const el = typeof input === 'string' ? document.querySelector(input) : input;
        if (!el || el._mentionBound) return;
        el._mentionBound = true;
        users = users || [];

        const $el = $(el);
        const $menu = $('<div class="mention-menu d-none"></div>').appendTo('body');
        let active = -1;
        let range = null; // {start, end} of the "at-mention text" being replaced

        function currentFragment() {
            const pos = el.selectionStart;
            const before = el.value.slice(0, pos);
            const m = before.match(/@([^\s@]*)$/);
            if (!m) return null;
            return { start: pos - m[0].length, end: pos, query: m[1].toLowerCase() };
        }

        function matches(query) {
            return users.filter(u => u.name.toLowerCase().includes(query)).slice(0, 6);
        }

        function render(list) {
            $menu.empty();
            list.forEach((u, i) => {
                $('<div class="mention-menu-item"></div>')
                    .toggleClass('active', i === active)
                    .text(u.name)
                    .attr('data-index', i)
                    .appendTo($menu);
            });
            if (!list.length) { close(); return; }
            const rect = el.getBoundingClientRect();
            $menu.css({ top: (window.scrollY + rect.bottom + 4) + 'px', left: (window.scrollX + rect.left) + 'px' }).removeClass('d-none');
        }

        function close() {
            $menu.addClass('d-none').empty();
            range = null;
            active = -1;
        }

        function pick(u) {
            if (!range) return;
            const before = el.value.slice(0, range.start);
            const after = el.value.slice(range.end);
            el.value = before + '@' + u.name + ' ' + after;
            const caret = (before + '@' + u.name + ' ').length;
            el.setSelectionRange(caret, caret);
            el.focus();
            close();
        }

        $el.on('input click keyup', function () {
            const frag = currentFragment();
            if (!frag) { close(); return; }
            range = { start: frag.start, end: frag.end };
            active = -1;
            render(matches(frag.query));
        });

        $el.on('keydown', function (e) {
            if ($menu.hasClass('d-none')) return;
            const items = $menu.children();
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                active = Math.min(active + 1, items.length - 1);
                items.removeClass('active').eq(active).addClass('active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                active = Math.max(active - 1, 0);
                items.removeClass('active').eq(active).addClass('active');
            } else if ((e.key === 'Enter' || e.key === 'Tab') && active >= 0) {
                e.preventDefault();
                const list = matches(currentFragment()?.query ?? '');
                if (list[active]) pick(list[active]);
            } else if (e.key === 'Escape') {
                close();
            }
        });

        $(document).on('mousedown', '.mention-menu-item', function () {
            const list = matches(currentFragment()?.query ?? '');
            const u = list[$(this).data('index')];
            if (u) pick(u);
        });

        $el.on('blur', function () {
            // Let a click on the menu register before it disappears.
            setTimeout(close, 150);
        });
    };
</script>
@endpush
