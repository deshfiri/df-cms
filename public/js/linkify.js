/*
 * Clickable links, everywhere
 *
 * Any web address shown as plain text — a comment, a task brief, a client
 * note, a table cell, a chat message — becomes a link that opens in a new tab.
 * Runs once when the page loads and again on anything added later (DataTables
 * redraws, AJAX refreshes, chat, dialogs), so no view has to opt in.
 *
 * Safe by construction: it only ever splits existing text nodes and builds the
 * links with the DOM API. Nothing typed by a user is parsed as HTML, and only
 * http(s):// and www. addresses are linked — never javascript: or data:.
 *
 * Left alone: existing links and buttons, form fields, code/pre blocks (the
 * redirect URIs and webhooks on settings pages are there to be copied), Select2
 * widgets, editable areas, and anything marked data-no-linkify.
 *
 * Loaded by layouts/app and layouts/portal. Edit this file directly; the
 * layouts bust the cache from its mtime. Call window.linkify(el) to run it on
 * a node by hand.
 */
(function () {
    'use strict';

    // Cheap pre-check, so the full match only runs on text that could hold an address.
    var QUICK = /(?:https?:\/\/|www\.)/i;
    var URL_RE = /\b(?:https?:\/\/|www\.)[^\s<>"'`]+/gi;
    var BARE = /^(?:https?:\/\/|www\.)$/i;

    var SKIP = [
        'a', 'button', 'script', 'style', 'noscript', 'template', 'iframe', 'svg',
        'textarea', 'input', 'select', 'option',
        'code', 'pre', 'kbd', 'samp',
        '[contenteditable=""]', '[contenteditable="true"]',
        '.select2-container', '[data-no-linkify]',
    ].join(',');

    function skipped(el) {
        return !el || el.nodeType !== 1 || !!el.closest(SKIP);
    }

    function count(text, ch) {
        return text.split(ch).length - 1;
    }

    // "(see https://x.com/a)." — the closing punctuation belongs to the sentence,
    // but a bracket the address itself opened stays: https://en.wikipedia.org/wiki/Foo_(bar)
    function trimTrail(url) {
        while (url.length) {
            var last = url.charAt(url.length - 1);
            var pairs = { ')': '(', ']': '[', '}': '{' };
            if ('.,;:!?'.indexOf(last) !== -1 || (pairs[last] && count(url, pairs[last]) < count(url, last))) {
                url = url.slice(0, -1);
            } else {
                break;
            }
        }
        return url;
    }

    // Inside a clickable row or card, following the link should not also fire the row.
    function stop(e) {
        e.stopPropagation();
    }

    function makeLink(url) {
        var a = document.createElement('a');
        a.href = /^www\./i.test(url) ? 'https://' + url : url;
        a.textContent = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.className = 'auto-link';
        a.addEventListener('click', stop);
        return a;
    }

    function linkifyText(node) {
        var text = node.nodeValue;
        if (!text || !QUICK.test(text) || !node.parentNode || skipped(node.parentNode)) return;

        var frag = document.createDocumentFragment();
        var last = 0, found = false, m;

        URL_RE.lastIndex = 0;
        while ((m = URL_RE.exec(text))) {
            var url = trimTrail(m[0]);
            if (BARE.test(url)) continue;

            if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
            frag.appendChild(makeLink(url));
            last = m.index + url.length;
            found = true;
        }

        if (!found) return;
        if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(frag, node);
    }

    function scan(root) {
        if (!root) return;
        if (root.nodeType === 3) {
            linkifyText(root);
            return;
        }
        if (root.nodeType !== 1 || skipped(root)) return;

        // Collected first: replacing nodes mid-walk would derail the walker.
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                return QUICK.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            },
        });
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(linkifyText);
    }

    function start() {
        var style = document.createElement('style');
        // A fixed, recognizable "link blue" — dynamic across light/dark mode
        // via --c-blue, but deliberately not var(--primary): the admin's
        // configurable brand color can match a bubble/background it sits on
        // (e.g. a green theme rendering a chat link unreadable on a green
        // bubble) and a link's color shouldn't be at the mercy of that pick.
        style.textContent = '.auto-link{color:var(--c-blue,#2563eb);text-decoration:underline;text-underline-offset:2px;overflow-wrap:anywhere}'
            + '.auto-link:hover{opacity:.85}';
        document.head.appendChild(style);

        scan(document.body);

        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.type === 'characterData') {
                    linkifyText(m.target);
                } else {
                    m.addedNodes.forEach(scan);
                }
            });
        }).observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    window.linkify = scan;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
