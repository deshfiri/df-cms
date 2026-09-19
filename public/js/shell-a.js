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
 * Alert sounds. What plays for each event (chat message, task, workflow item,
 * meeting, incoming call, anything else in the bell) and how loud is set for
 * everyone in Settings → Sounds and arrives in window.DFCP.sounds:
 *
 *   enabled  false when an admin has switched sounds off for the whole app
 *   events   { message: { on, src, tone, volume, loop }, … }
 *
 * Each event plays either a clip (src) or a tone synthesised here (tone), so
 * the built-in tones cost no download. On top of that each person can mute
 * everything for themselves from the profile menu, remembered in localStorage.
 */
        window.AppSound = (function () {
            var KEY = 'dfcp_sound', LEGACY_KEY = 'dfcp_chat_sound';
            var conf = (window.DFCP && window.DFCP.sounds) || {};
            var events = conf.events || {};
            var cache = {};

            function allowed() { return conf.enabled !== false; }

            function enabled() {
                var v = null;
                try {
                    v = localStorage.getItem(KEY);
                    // Carry over the preference from when this was chat-only.
                    if (v === null) { v = localStorage.getItem(LEGACY_KEY); }
                } catch (e) {}
                return v !== 'off';
            }
            function setEnabled(on) { try { localStorage.setItem(KEY, on ? 'on' : 'off'); } catch (e) {} }

            // ── Synthesised tones ─────────────────────────────────────────
            // Each note: f (Hz, or several for a chord), at / dur (seconds),
            // wave, peak gain at full volume, and optionally `to`, a pitch glide.
            var TONES = {
                chime:  [{ f: 880, at: 0, dur: 0.4, gain: 0.28 }, { f: 1318.5, at: 0.16, dur: 0.6, gain: 0.24 }],
                ping:   [{ f: 1568, at: 0, dur: 0.7, gain: 0.26 }],
                pop:    [{ f: 620, to: 180, at: 0, dur: 0.14, wave: 'triangle', gain: 0.45 }],
                triple: [0, 0.15, 0.3].map(function (at) { return { f: 1000, at: at, dur: 0.09, wave: 'square', gain: 0.08 }; }),
                ring:   [{ f: [440, 480], at: 0, dur: 0.45, gain: 0.12 }, { f: [440, 480], at: 0.6, dur: 0.45, gain: 0.12 }],
            };
            var TONE_REPEAT_MS = 2600;   // how often a looping tone (the call ringtone) repeats
            var actx = null;

            function context() {
                try {
                    if (!actx) {
                        var Ctx = window.AudioContext || window.webkitAudioContext;
                        if (!Ctx) return null;
                        actx = new Ctx();
                    }
                    if (actx.state === 'suspended') { actx.resume(); }
                    return actx;
                } catch (e) { return null; }
            }

            function synth(name, volume) {
                var notes = TONES[name], c = notes && context();
                if (!c || !(volume > 0)) return;
                try {
                    var t0 = c.currentTime + 0.02;
                    notes.forEach(function (n) {
                        [].concat(n.f).forEach(function (freq) {
                            var o = c.createOscillator(), g = c.createGain();
                            var start = t0 + n.at, end = start + n.dur;
                            o.type = n.wave || 'sine';
                            o.frequency.setValueAtTime(freq, start);
                            if (n.to) { o.frequency.exponentialRampToValueAtTime(n.to, end); }
                            // Exponential ramps cannot reach zero, hence the floor.
                            g.gain.setValueAtTime(0.0001, start);
                            g.gain.exponentialRampToValueAtTime(Math.max(0.0002, n.gain * volume), start + 0.012);
                            g.gain.exponentialRampToValueAtTime(0.0001, end);
                            o.connect(g); g.connect(c.destination);
                            o.start(start); o.stop(end + 0.05);
                        });
                    });
                } catch (e) {}
            }

            // ── Recorded clips ────────────────────────────────────────────
            function audio(src) {
                if (!cache[src]) {
                    var a = new Audio(src);
                    a.preload = 'auto';
                    cache[src] = a;
                }
                return cache[src];
            }

            function playClip(src, volume, reuse) {
                try {
                    var a = reuse ? audio(src) : new Audio(src);
                    a.volume = Math.max(0, Math.min(1, volume));
                    a.currentTime = 0;
                    var p = a.play();
                    // Autoplay still blocked (no gesture yet) — stay silent, never throw.
                    if (p && p.catch) { p.catch(function () {}); }
                    return a;
                } catch (e) { return null; }
            }

            // Browsers refuse audio until the user has interacted with the page, so
            // prime every clip silently on the first click — after that .play()
            // works. The same click unlocks the tone synthesiser.
            document.addEventListener('click', function () {
                if (!allowed()) return;
                var seen = {};
                Object.keys(events).forEach(function (kind) {
                    var src = events[kind] && events[kind].src;
                    if (!src || seen[src] || !events[kind].on) return;
                    seen[src] = true;
                    var a = audio(src);
                    a.muted = true;
                    var p = a.play();
                    var reset = function () { try { a.pause(); a.currentTime = 0; } catch (e) {} a.muted = false; };
                    if (p && p.then) { p.then(reset).catch(reset); } else { reset(); }
                });
                if (Object.keys(events).some(function (k) { return events[k] && events[k].tone; })) { context(); }
            }, { once: true });

            /** Play one event's sound, if it has one and nobody has muted it. */
            function play(kind) {
                var e = events[kind] || events.notification;
                if (!allowed() || !enabled() || !e || !e.on) return;
                if (e.tone) { synth(e.tone, e.volume); }
                else if (e.src) { playClip(e.src, e.volume, true); }
            }

            /**
             * Play a sound as configured on the settings page, ignoring the mutes:
             * spec is { src, tone, volume }. Used by Settings → Sounds' Test buttons.
             */
            function preview(spec) {
                if (!spec) return;
                var volume = spec.volume == null ? 0.6 : spec.volume;
                if (spec.tone) { synth(spec.tone, volume); }
                else if (spec.src) { playClip(spec.src, volume, false); }
            }

            // ── Call tones ────────────────────────────────────────────────
            // Kept inside this module rather than as a second audio system, so
            // the same switches govern everything that makes noise.
            var ringEl = null, ringTimer = null, ringbackTimer = null;

            /** Repeat the incoming-call sound until stopped. */
            function startRing() {
                stopRing();
                var e = events.call;
                if (!allowed() || !enabled() || !e || !e.on) return;
                if (e.tone) {
                    synth(e.tone, e.volume);
                    ringTimer = setInterval(function () { synth(e.tone, e.volume); }, TONE_REPEAT_MS);
                    return;
                }
                if (!e.src) return;
                try {
                    var a = new Audio(e.src);
                    a.loop = true;
                    a.volume = Math.max(0, Math.min(1, e.volume));
                    ringEl = a;
                    var p = a.play();
                    if (p && p.catch) { p.catch(function () {}); }
                } catch (err) {}
            }
            function stopRing() {
                if (ringTimer) { clearInterval(ringTimer); ringTimer = null; }
                if (!ringEl) return;
                try { ringEl.pause(); ringEl.currentTime = 0; } catch (e) {}
                ringEl = null;
            }

            /** Outgoing ringback: a synthesised two-tone burst every 3s. */
            function startRingback() {
                stopRingback();
                if (!allowed() || !enabled()) return;
                var beep = function () {
                    try {
                        var c = context();
                        if (!c) return;
                        var t = c.currentTime;
                        [440, 480].forEach(function (freq) {
                            var o = c.createOscillator(), g = c.createGain();
                            o.type = 'sine';
                            o.frequency.value = freq;
                            o.connect(g); g.connect(c.destination);
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
                allowed: allowed,
                enabled: enabled,
                setEnabled: setEnabled,
                play: play,
                preview: preview,
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
                    title: e.is_group
                        ? (e.sender_name || 'Someone') + ' in ' + (e.conversation_name || 'a group')
                        : (e.sender_name || 'Someone') + ' sent you a message',
                    body: preview,
                    tag: 'chat-' + e.conversation_id,
                    onClick: function () { openChatFor(e); },
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
            if (s && !window.AppSound.allowed()) {
                // Switched off for everyone in Settings → Sounds.
                s.checked = false;
                s.disabled = true;
                var row = s.closest('label');
                if (row) { row.title = 'Sounds are turned off for everyone by an administrator'; }
            } else if (s) {
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
        /** Open the thread a chat event belongs to: the group, or the 1:1 with its sender. */
        function openChatFor(e) {
            if (e.is_group) {
                if (window.ChatOpenGroup) { window.ChatOpenGroup(e.conversation_id, e.conversation_name); }
                else { window.location.href = '/chat?group=' + encodeURIComponent(e.conversation_id); }
                return;
            }
            if (window.ChatOpenThread) { window.ChatOpenThread(e.sender_id, e.sender_name); }
            else { window.location.href = '/chat?user=' + encodeURIComponent(e.sender_id); }
        }

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
                title: e.is_group
                    ? (e.sender_name || 'Someone') + ' in ' + (e.conversation_name || 'a group')
                    : (e.sender_name || 'Someone') + ' sent you a message',
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
                        openChatFor(e);
                    });
                },
            });
        }
