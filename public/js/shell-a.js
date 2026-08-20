/*
 * Application shell — alert sounds, desktop notifications, message toast
 *
 * Extracted from layouts/app.blade.php so it is cached once instead of
 * re-sent inside every page. Values that vary per request or per session
 * (CSRF token, route URLs, asset paths) are read from window.DFCP, which
 * the layout emits inline just before this file loads.
 *
 * Edit this file directly; the layout busts the cache from its mtime.
 */

/*
 * Alert sounds. Two distinct cues so people can tell them apart without looking:
 * message_alert for chat, notification for everything else (bell items).
 * Per-user on/off, remembered in localStorage, toggled from the profile menu.
 */
        window.AppSound = (function () {
            var KEY = 'dfcp_sound', LEGACY_KEY = 'dfcp_chat_sound';
            var src = {
                message:      window.DFCP.sounds.message,
                notification: window.DFCP.sounds.notification,
            };
            var cache = {};

            function enabled() {
                var v = localStorage.getItem(KEY);
                // Carry over the preference from when this was chat-only.
                if (v === null) { v = localStorage.getItem(LEGACY_KEY); }
                return v !== 'off';
            }
            function setEnabled(on) { localStorage.setItem(KEY, on ? 'on' : 'off'); }

            function audio(kind) {
                if (!cache[kind]) {
                    var a = new Audio(src[kind]);
                    a.preload = 'auto';
                    a.volume = 0.6;
                    cache[kind] = a;
                }
                return cache[kind];
            }

            // Browsers refuse audio until the user has interacted with the page, so
            // prime both clips silently on the first click — after that .play() works.
            document.addEventListener('click', function () {
                Object.keys(src).forEach(function (kind) {
                    var a = audio(kind);
                    a.muted = true;
                    var p = a.play();
                    var reset = function () { try { a.pause(); a.currentTime = 0; } catch (e) {} a.muted = false; };
                    if (p && p.then) { p.then(reset).catch(reset); } else { reset(); }
                });
            }, { once: true });

            function play(kind) {
                if (!enabled() || !src[kind]) return;
                try {
                    var a = audio(kind);
                    a.currentTime = 0;
                    var p = a.play();
                    // Autoplay still blocked (no gesture yet) — stay silent, never throw.
                    if (p && p.catch) { p.catch(function () {}); }
                } catch (e) {}
            }

            // ── Call tones ────────────────────────────────────────────────
            // Kept inside this module rather than as a second audio system, so
            // the one "Alert sounds" switch governs everything that makes noise.
            var ringEl = null, ringCtx = null, ringbackTimer = null;

            /** Loop a clip until stopped — the incoming-call ringtone. */
            function startRing() {
                stopRing();
                if (!enabled()) return;
                try {
                    var a = new Audio(src.notification);
                    a.loop = true;
                    a.volume = 0.55;
                    ringEl = a;
                    var p = a.play();
                    if (p && p.catch) { p.catch(function () {}); }
                } catch (e) {}
            }
            function stopRing() {
                if (!ringEl) return;
                try { ringEl.pause(); ringEl.currentTime = 0; } catch (e) {}
                ringEl = null;
            }

            /** Outgoing ringback: a synthesised two-tone burst every 3s. */
            function startRingback() {
                stopRingback();
                if (!enabled()) return;
                var beep = function () {
                    try {
                        if (!ringCtx) { ringCtx = new (window.AudioContext || window.webkitAudioContext)(); }
                        if (ringCtx.state === 'suspended') { ringCtx.resume(); }
                        var t = ringCtx.currentTime;
                        [440, 480].forEach(function (freq) {
                            var o = ringCtx.createOscillator(), g = ringCtx.createGain();
                            o.type = 'sine';
                            o.frequency.value = freq;
                            o.connect(g); g.connect(ringCtx.destination);
                            g.gain.setValueAtTime(0.0001, t);
                            g.gain.exponentialRampToValueAtTime(0.05, t + 0.02);
                            g.gain.exponentialRampToValueAtTime(0.0001, t + 1.0);
                            o.start(t); o.stop(t + 1.05);
                        });
                    } catch (e) {}
                };
                beep();
                ringbackTimer = setInterval(beep, 3000);
            }
            function stopRingback() {
                if (ringbackTimer) { clearInterval(ringbackTimer); ringbackTimer = null; }
            }

            return {
                enabled: enabled,
                setEnabled: setEnabled,
                play: play,
                message: function () { play('message'); },
                notification: function () { play('notification'); },
                startRing: startRing,
                stopRing: stopRing,
                startRingback: startRingback,
                stopRingback: stopRingback,
                stopAllCallTones: function () { stopRing(); stopRingback(); },
            };
        })();

        // Back-compat for anything still calling the old chat-only helper.
        window.ChatSound = {
            enabled: window.AppSound.enabled,
            setEnabled: window.AppSound.setEnabled,
            play: window.AppSound.message,
        };

        /**
         * OS-level notifications — the popup you get when the browser is
         * minimised or behind another window. The in-page toast cannot reach
         * you there; only the Notifications API can.
         *
         * Note the hard limit: this needs the tab to still be open. If the
         * browser is fully closed, nothing arrives without a service worker and
         * Web Push, which would need its own infrastructure.
         */
        window.AppNotify = (function () {
            var KEY = 'dfcp_chat_desktop';
            var supported = ('Notification' in window);
            var live = [];   // keep refs so sticky notifications can be closed

            function permission() { return supported ? Notification.permission : 'unsupported'; }
            function enabledPref() { return localStorage.getItem(KEY) !== 'off'; }

            function request() {
                if (!supported || Notification.permission !== 'default') {
                    return Promise.resolve(permission());
                }

                return Notification.requestPermission();
            }

            function setEnabled(on) {
                localStorage.setItem(KEY, on ? 'on' : 'off');
                if (on) request();
            }

            /** True when the app is not the window the user is looking at. */
            function appIsHidden() {
                return document.hidden || !document.hasFocus();
            }

            /**
             * opts: title (required), body, tag, url, onClick,
             *       sticky  - stays until dismissed instead of auto-closing,
             *       force   - show even when the app is on screen.
             *
             * Deliberately not written as a JSDoc object type: a doubled brace
             * is Blade echo syntax and compiles this file into invalid PHP.
             */
            function notify(opts) {
                if (!supported || !enabledPref() || Notification.permission !== 'granted') return null;
                // Don't duplicate what the user can already see on screen,
                // unless the caller insists (an incoming call, say).
                if (!opts.force && !appIsHidden()) return null;

                try {
                    var n = new Notification(opts.title, {
                        body: opts.body || '',
                        tag: opts.tag || 'dfcp',
                        renotify: true,
                        requireInteraction: !!opts.sticky,   // stays until dismissed
                    });

                    n.onclick = function () {
                        window.focus();
                        try { n.close(); } catch (e) {}

                        if (opts.onClick) { opts.onClick(); return; }
                        if (opts.url) { window.location.href = opts.url; }
                    };

                    live.push(n);
                    if (!opts.sticky) {
                        setTimeout(function () { try { n.close(); } catch (e) {} }, 7000);
                    }

                    return n;
                } catch (e) {
                    return null;
                }
            }

            /** Close anything still on screen — e.g. once a call is answered. */
            function closeAll() {
                live.forEach(function (n) { try { n.close(); } catch (e) {} });
                live = [];
            }

            /** Chat message shape, kept for the existing caller. */
            function show(e) {
                var preview = e.body || '';
                if (!preview && e.attachment) {
                    preview = e.attachment.is_image ? '📷 Photo' : '📎 ' + e.attachment.name;
                }

                return notify({
                    title: (e.sender_name || 'Someone') + ' sent you a message',
                    body: preview,
                    tag: 'chat-' + e.conversation_id,
                    onClick: function () {
                        if (window.ChatOpenThread) { window.ChatOpenThread(e.sender_id, e.sender_name); }
                        else { window.location.href = '/chat?user=' + encodeURIComponent(e.sender_id); }
                    },
                });
            }

            // Ask on the first interaction. Browsers reject a permission prompt
            // that is not tied to a user gesture, so this cannot run on load.
            if (supported) {
                document.addEventListener('click', function () {
                    if (enabledPref() && Notification.permission === 'default') request();
                }, { once: true });
            }

            return {
                supported: supported,
                permission: permission,
                request: request,
                enabledPref: enabledPref,
                setEnabled: setEnabled,
                notify: notify,
                show: show,
                closeAll: closeAll,
            };
        })();

        // Existing name kept so nothing that already calls it breaks.
        window.ChatNotify = window.AppNotify;

        document.addEventListener('DOMContentLoaded', function () {
            var s = document.getElementById('soundToggle');
            if (s) {
                s.checked = window.AppSound.enabled();
                s.addEventListener('change', function () {
                    window.AppSound.setEnabled(s.checked);
                    if (s.checked) window.AppSound.notification();   // audible confirmation
                });
            }
            var d = document.getElementById('chatDesktopToggle');
            var hint = document.getElementById('desktopNotifyHint');

            // Popups failing silently is almost always a blocked permission, and
            // nothing in the UI used to say so. Show the actual state.
            function paintNotifyState() {
                if (!d) return;
                var state = window.AppNotify.permission();

                if (state === 'unsupported') {
                    d.checked = false; d.disabled = true;
                    if (hint) hint.textContent = 'Not supported by this browser';
                } else if (state === 'denied') {
                    d.checked = false; d.disabled = true;
                    if (hint) hint.textContent = 'Blocked — allow notifications in your browser’s site settings';
                } else if (state === 'default') {
                    d.checked = window.AppNotify.enabledPref();
                    d.disabled = false;
                    if (hint) hint.textContent = 'Turn on to allow pop-ups when minimised';
                } else {
                    d.checked = window.AppNotify.enabledPref();
                    d.disabled = false;
                    if (hint) hint.textContent = d.checked ? 'Pop-ups appear when the window is minimised' : '';
                }
            }

            if (d) {
                paintNotifyState();
                d.addEventListener('change', function () {
                    window.AppNotify.setEnabled(d.checked);
                    // requestPermission resolves after the browser prompt, so
                    // repaint once the answer is known.
                    window.AppNotify.request().then(paintNotifyState).catch(paintNotifyState);
                });
            }
        });

/*
 * In-page alert for a new chat message. Complements rather than duplicates
 * the desktop notification, which only fires when the tab is NOT focused —
 * this is what you see while you are actually using the app.
 */
        function showMessageToast(e) {
            if (!window.Swal || !e) return;

            var body = (e.body || '').trim();
            // An attachment can arrive with no caption at all.
            if (!body && e.attachment) {
                body = e.attachment.is_image ? '📷 Photo' : '📎 ' + e.attachment.name;
            }
            if (body.length > 70) body = body.slice(0, 70) + '…';

            Swal.fire({
                toast: true,
                position: 'bottom-end',
                icon: 'info',
                title: (e.sender_name || 'Someone') + ' sent you a message',
                text: body,
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true,
                customClass: { popup: 'chat-toast' },
                didOpen: function (toast) {
                    toast.style.cursor = 'pointer';
                    toast.addEventListener('click', function () {
                        Swal.close();
                        // Already on the chat page: switch threads in place
                        // rather than reloading and losing the socket.
                        if (window.ChatOpenThread) {
                            window.ChatOpenThread(e.sender_id, e.sender_name);
                        } else {
                            window.location.href = '/chat?user=' + encodeURIComponent(e.sender_id);
                        }
                    });
                },
            });
        }
