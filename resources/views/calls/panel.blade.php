{{--
    App-wide 1:1 audio calling.

    Included once from layouts/app.blade.php so an incoming call reaches the
    user on any page, not only /chat. Reverb carries signalling; the audio
    itself is a direct WebRTC peer connection and never touches the server.
--}}

<audio id="callRemoteAudio" autoplay playsinline></audio>

{{-- Incoming call --}}
<div id="callIncoming" class="call-overlay" aria-hidden="true">
    <div class="call-card">
        <div class="call-avatar" id="callInAvatar">?</div>
        <div class="call-name" id="callInName">—</div>
        <div class="call-sub">Incoming audio call</div>
        <div class="call-actions">
            <button class="call-btn call-btn-reject" id="callReject" title="Reject">
                <i class="bi bi-telephone-x-fill"></i>
            </button>
            <button class="call-btn call-btn-accept" id="callAccept" title="Accept">
                <i class="bi bi-telephone-fill"></i>
            </button>
        </div>
    </div>
</div>

{{-- Active / outgoing call --}}
<div id="callActive" class="call-dock" aria-hidden="true">
    <div class="call-dock-avatar" id="callActiveAvatar">?</div>
    <div class="call-dock-body">
        <div class="call-dock-name" id="callActiveName">—</div>
        <div class="call-dock-status" id="callActiveStatus">Calling…</div>
    </div>
    <button class="call-dock-btn" id="callMute" title="Mute microphone">
        <i class="bi bi-mic-fill" id="callMuteIcon"></i>
    </button>
    <button class="call-dock-btn call-dock-end" id="callEnd" title="End call">
        <i class="bi bi-telephone-x-fill"></i>
    </button>
</div>

{{-- Shown only when the browser blocks audio until the user interacts --}}
<button id="callUnblockAudio" class="call-unblock" aria-hidden="true">
    <i class="bi bi-volume-up me-1"></i>Tap to enable call audio
</button>

{{-- Inline rather than @push('styles'): this partial is included in the body,
     and the styles stack has already been rendered in <head> by then. --}}
<style>
    .call-overlay {
        position: fixed; inset: 0; z-index: 20000; display: none;
        align-items: center; justify-content: center;
        background: rgba(0, 0, 0, .55); backdrop-filter: blur(3px);
    }
    .call-overlay.show { display: flex; }
    .call-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow-lg);
        padding: 1.6rem 2rem; text-align: center; min-width: 280px; max-width: 92vw;
    }
    .call-avatar, .call-dock-avatar {
        border-radius: 50%; background: var(--primary); color: #fff;
        display: grid; place-items: center; font-weight: 700;
    }
    .call-avatar { width: 68px; height: 68px; font-size: 1.6rem; margin: 0 auto .8rem; }
    .call-name { font-size: 1.05rem; font-weight: 700; color: var(--text); }
    .call-sub { font-size: .75rem; color: var(--text3); margin-top: 2px; }
    .call-actions { display: flex; gap: 1.4rem; justify-content: center; margin-top: 1.4rem; }
    .call-btn {
        width: 54px; height: 54px; border-radius: 50%; border: none; color: #fff;
        font-size: 1.15rem; cursor: pointer; display: grid; place-items: center;
    }
    .call-btn-accept { background: #16a34a; }
    .call-btn-reject { background: #dc3545; }
    .call-btn:active { transform: scale(.94); }

    .call-dock {
        position: fixed; right: 18px; bottom: 18px; z-index: 19000; display: none;
        align-items: center; gap: .7rem; padding: .6rem .8rem;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow-lg); min-width: 260px;
    }
    .call-dock.show { display: flex; }
    .call-dock-avatar { width: 38px; height: 38px; font-size: .9rem; flex-shrink: 0; }
    .call-dock-body { flex: 1; min-width: 0; }
    .call-dock-name { font-size: .82rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .call-dock-status { font-size: .68rem; color: var(--text3); }
    .call-dock-btn {
        width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
        background: var(--surface2); border: 1px solid var(--border);
        color: var(--text2); cursor: pointer; display: grid; place-items: center;
    }
    .call-dock-btn.is-muted { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .call-dock-end { background: #dc3545; border-color: #dc3545; color: #fff; }

    /* Toasts also live bottom-right, so lift them clear while the call dock
       is on screen instead of letting the two overlap. */
    body.call-active .swal2-container.swal2-bottom-end,
    body.call-active .swal2-container.swal2-bottom-right {
        bottom: 84px;
    }

    .call-unblock {
        position: fixed; left: 50%; transform: translateX(-50%); bottom: 76px;
        z-index: 19500; display: none; border: none; cursor: pointer;
        background: var(--primary); color: #fff; font-size: .74rem;
        padding: .45rem .9rem; border-radius: 999px; box-shadow: var(--shadow-md);
    }
    .call-unblock.show { display: block; }

    @media (max-width: 576px) {
        .call-dock { left: 12px; right: 12px; bottom: 12px; }
    }
</style>

@push('scripts')
<script>
/**
 * Call client.
 *
 * Signalling goes over the personal Reverb channel the layout already
 * subscribes to; Echo caches channels by name, so asking for it again returns
 * the same subscription rather than opening a second one.
 */
window.DfcpCall = (function () {
    'use strict';

    var ME = window.CURRENT_USER_ID;
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Everything about the current call. Reset wholesale by cleanup() so no
    // state can survive into the next one.
    var s = null;
    var bound = false;
    var iceConfig = null;

    function blank() {
        return {
            uuid: null, peerId: null, peerName: '', isCaller: false,
            pc: null, localStream: null,
            pendingIce: [],        // candidates that arrived before the remote description
            remoteReady: false,    // setRemoteDescription() has resolved
            muted: false,
            answeredAt: null, tick: null, ringTimer: null, connectTimer: null,
            icePromise: null,      // resolves with the ICE config for THIS call
            ending: false,
        };
    }

    // ── DOM helpers ───────────────────────────────────────────────────────
    var $in = document.getElementById('callIncoming');
    var $dock = document.getElementById('callActive');
    var $audio = document.getElementById('callRemoteAudio');
    var $unblock = document.getElementById('callUnblockAudio');

    function initials(name) { return (name || '?').trim().charAt(0).toUpperCase(); }

    function showIncoming(name) {
        document.getElementById('callInName').textContent = name;
        document.getElementById('callInAvatar').textContent = initials(name);
        $in.classList.add('show');
        $in.setAttribute('aria-hidden', 'false');
    }
    function hideIncoming() {
        $in.classList.remove('show');
        $in.setAttribute('aria-hidden', 'true');
    }
    function showDock(name) {
        document.getElementById('callActiveName').textContent = name;
        document.getElementById('callActiveAvatar').textContent = initials(name);
        $dock.classList.add('show');
        $dock.setAttribute('aria-hidden', 'false');
        // Tells the toast styling to move out of the dock's way.
        document.body.classList.add('call-active');
    }
    function hideDock() {
        $dock.classList.remove('show');
        $dock.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('call-active');
    }
    function status(text) {
        var el = document.getElementById('callActiveStatus');
        if (el) el.textContent = text;
    }
    function toast(icon, title, text) {
        if (window.Swal) Swal.fire({ icon: icon, title: title, text: text || '', timer: 2600, showConfirmButton: false });
    }

    // ── Duration ──────────────────────────────────────────────────────────
    function startTimer() {
        stopTimer();
        s.answeredAt = Date.now();
        s.tick = setInterval(function () {
            var secs = Math.floor((Date.now() - s.answeredAt) / 1000);
            status(('0' + Math.floor(secs / 60)).slice(-2) + ':' + ('0' + (secs % 60)).slice(-2));
        }, 1000);
    }
    function stopTimer() {
        if (s && s.tick) { clearInterval(s.tick); s.tick = null; }
    }

    /**
     * Negotiation can stall without ever reaching 'failed' — a lost answer, a
     * backgrounded tab that never sent its offer. Without this the UI sits on
     * "Connecting…" forever and the call row stays open, marking both users
     * busy long after they gave up.
     */
    function armConnectWatchdog() {
        if (!s) return;
        if (s.connectTimer) clearTimeout(s.connectTimer);

        s.connectTimer = setTimeout(function () {
            if (s && (!s.pc || s.pc.connectionState !== 'connected')) {
                hangup('connect_timeout', iceFailureMessage());
            }
        }, 30000);
    }

    // ── Transport ─────────────────────────────────────────────────────────
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                return { ok: r.ok, status: r.status, body: j };
            });
        });
    }

    function sendSignal(type, payload) {
        if (!s || !s.uuid) return Promise.resolve();
        return post('/calls/' + s.uuid + '/signal', { type: type, payload: payload });
    }

    /**
     * ICE servers are fetched per call, not cached for the session: TURN
     * credentials are short-lived by design, and a stale one fails exactly
     * when the network is hard enough to need a relay.
     *
     * Callers must await this before building a peer connection. Creating one
     * while the fetch is still in flight yields a connection with no ICE
     * servers at all — not even STUN — which then fails on anything but a LAN.
     */
    function loadIce() {
        return fetch('/calls/ice', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (cfg) { iceConfig = cfg; return cfg; })
            .catch(function () {
                // Never block a call on this; host candidates still work on a LAN.
                iceConfig = { iceServers: [], iceTransportPolicy: 'all', hasTurn: false };
                return iceConfig;
            });
    }

    /** Explain an ICE failure instead of leaving the user with "Call failed". */
    function iceFailureMessage() {
        return (iceConfig && iceConfig.hasTurn)
            ? 'Call failed'
            : 'Call failed — no relay';
    }

    /** Peer connections are only ever built once the ICE config has arrived. */
    function withIce(fn) {
        var wait = (s && s.icePromise) ? s.icePromise : loadIce();

        return wait.then(fn);
    }

    // ── Microphone ────────────────────────────────────────────────────────
    function getMic() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject({ name: 'Unsupported' });
        }
        return navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    }

    function micError(err) {
        var name = (err && err.name) || '';
        if (name === 'NotAllowedError' || name === 'SecurityError') {
            return 'Your browser blocked microphone access. Allow the microphone for this site, then try again.';
        }
        if (name === 'NotFoundError' || name === 'OverconstrainedError') {
            return 'No microphone was detected on this device.';
        }
        if (name === 'NotReadableError') {
            return 'Your microphone is already in use by another application.';
        }
        if (name === 'Unsupported') {
            return 'This browser does not support audio calls. Note that microphone access also requires HTTPS.';
        }
        return 'Microphone permission is required for audio calls.';
    }

    // ── Peer connection ───────────────────────────────────────────────────
    function createPeer() {
        var pc = new RTCPeerConnection({
            iceServers: (iceConfig && iceConfig.iceServers) || [],
            iceTransportPolicy: (iceConfig && iceConfig.iceTransportPolicy) || 'all',
        });

        pc.onicecandidate = function (e) {
            if (e.candidate) sendSignal('ice', e.candidate.toJSON ? e.candidate.toJSON() : e.candidate);
        };

        pc.ontrack = function (e) {
            if ($audio.srcObject !== e.streams[0]) {
                $audio.srcObject = e.streams[0];
                var p = $audio.play();
                // Autoplay policy can refuse until the user interacts. Rather
                // than a silent call, surface a one-tap unblock button.
                if (p && p.catch) {
                    p.catch(function () {
                        $unblock.classList.add('show');
                        $unblock.setAttribute('aria-hidden', 'false');
                    });
                }
            }
        };

        pc.onconnectionstatechange = function () {
            if (!s) return;
            if (pc.connectionState === 'connected') {
                window.AppSound && window.AppSound.stopAllCallTones();
                if (s.connectTimer) { clearTimeout(s.connectTimer); s.connectTimer = null; }
                if (!s.answeredAt) startTimer();
            } else if (pc.connectionState === 'disconnected') {
                status('Reconnecting…');
            } else if (pc.connectionState === 'failed') {
                // ICE exhausted every candidate pair — almost always a NAT that
                // needs a relay. hangup(), not finish(): the server must record
                // the outcome and release both users, otherwise the row stays
                // "accepted" and they are both reported busy for hours.
                hangup('ice_failed', iceFailureMessage());
            }
        };

        pc.oniceconnectionstatechange = function () {
            if (s && pc.iceConnectionState === 'failed' && pc.restartIce) {
                try { pc.restartIce(); } catch (e) {}
            }
        };

        pc.onsignalingstatechange = function () {
            if (s && pc.signalingState === 'closed') stopTimer();
        };

        return pc;
    }

    function attachMic(pc, stream) {
        stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
    }

    /** Apply the remote SDP, then release any candidates that arrived early. */
    function applyRemote(desc) {
        return s.pc.setRemoteDescription(new RTCSessionDescription(desc)).then(function () {
            s.remoteReady = true;
            var queued = s.pendingIce.splice(0, s.pendingIce.length);
            return Promise.all(queued.map(function (c) {
                return s.pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {});
            }));
        });
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────
    function startCall(userId, userName) {
        if (s) { toast('info', 'You are already on a call'); return; }

        s = blank();
        s.isCaller = true;
        s.peerId = userId;
        s.peerName = userName || 'User';

        showDock(s.peerName);
        status('Calling…');

        post('/calls/to/' + userId).then(function (res) {
            if (!res.ok) {
                var msg = (res.body && res.body.message) || 'Could not start the call.';
                finish(res.status === 409 ? 'User is busy' : 'Call failed');
                toast('info', msg);
                return;
            }

            // Glare: they were already ringing us. Answer that call instead of
            // leaving two half-calls in flight.
            if (res.body.glare) {
                var existing = res.body.call;
                cleanup();
                s = blank();
                s.uuid = existing.uuid;
                s.peerId = userId;
                s.peerName = userName || 'User';
                s.isCaller = false;
                acceptCall();
                return;
            }

            s.uuid = res.body.call.uuid;
            status('Ringing…');
            window.AppSound && window.AppSound.startRingback();

            // Client-side safety net; the server's reconciler is the authority.
            var timeout = (iceConfig && iceConfig.ringTimeout) || 30;
            s.ringTimer = setTimeout(function () {
                if (s && !s.answeredAt) hangup('no_answer', 'No answer');
            }, (timeout + 5) * 1000);
        });

        // Kicked off immediately so the config is ready by the time the callee
        // answers and negotiation starts.
        s.icePromise = loadIce();
    }

    function onIncoming(e) {
        // Already busy locally — the server also guards this, but a second
        // overlay must never appear over a live call.
        if (s) return;

        s = blank();
        s.uuid = e.call_uuid;
        s.peerId = e.caller_id;
        s.peerName = e.caller_name || 'User';
        s.isCaller = false;

        showIncoming(s.peerName);
        window.AppSound && window.AppSound.startRing();

        // A ringing phone is exactly the case worth interrupting for, so this
        // is sticky (stays until dismissed) and fires even if the tab is
        // visible — the user may be on another monitor.
        if (window.AppNotify) {
            window.AppNotify.notify({
                title: 'Incoming call',
                body: s.peerName + ' is calling you',
                tag: 'call-' + s.uuid,
                sticky: true,
                force: true,
                onClick: function () { window.focus(); },
            });
        }

        s.icePromise = loadIce();
    }

    function acceptCall() {
        if (!s) return;
        window.AppSound && window.AppSound.stopAllCallTones();
        if (window.AppNotify) window.AppNotify.closeAll();
        hideIncoming();
        showDock(s.peerName);
        status('Connecting…');
        armConnectWatchdog();

        // Prompt for the mic inside the click, so the permission dialog is
        // attached to a user gesture.
        getMic().then(function (stream) {
            s.localStream = stream;
            return post('/calls/' + s.uuid + '/accept');
        }).then(function (res) {
            if (!res || !res.ok) {
                finish((res && res.body && res.body.message) || 'Call unavailable');
            }
            // The caller now sends the offer; we answer it in onSignal().
        }).catch(function (err) {
            toast('error', 'Microphone', micError(err));
            post('/calls/' + s.uuid + '/reject');
            finish('Call ended', 'mic_denied');
        });
    }

    function rejectCall() {
        if (!s) return;
        var uuid = s.uuid;
        window.AppSound && window.AppSound.stopAllCallTones();
        hideIncoming();
        post('/calls/' + uuid + '/reject');
        cleanup();
    }

    function hangup(reason, label) {
        if (!s || s.ending) return;
        s.ending = true;
        var uuid = s.uuid;
        if (uuid) post('/calls/' + uuid + '/end', { reason: reason || null });
        finish(label || 'Call ended');
    }

    /** Caller side: the callee picked up, so begin negotiation. */
    function onAccepted() {
        if (!s || !s.isCaller) return;
        window.AppSound && window.AppSound.stopAllCallTones();
        status('Connecting…');

        armConnectWatchdog();

        getMic().then(function (stream) {
            s.localStream = stream;
            return withIce(function () {
                s.pc = createPeer();
                attachMic(s.pc, stream);
                return s.pc.createOffer();
            });
        }).then(function (offer) {
            return s.pc.setLocalDescription(offer).then(function () {
                return sendSignal('offer', { type: offer.type, sdp: offer.sdp });
            });
        }).catch(function (err) {
            toast('error', 'Microphone', micError(err));
            hangup('mic_denied', 'Call failed');
        });
    }

    function onSignal(e) {
        // Anything for a different or finished call is dropped, never fed to
        // the live peer connection.
        if (!s || e.call_uuid !== s.uuid) return;

        if (e.type === 'offer') {
            // Callee side: build the peer only now, with the mic already granted
            // and the ICE config guaranteed to have arrived.
            withIce(function () {
                if (!s.pc) {
                    s.pc = createPeer();
                    if (s.localStream) attachMic(s.pc, s.localStream);
                }
                return applyRemote(e.payload);
            })
                .then(function () { return s.pc.createAnswer(); })
                .then(function (answer) {
                    return s.pc.setLocalDescription(answer).then(function () {
                        return sendSignal('answer', { type: answer.type, sdp: answer.sdp });
                    });
                })
                .catch(function () { hangup('negotiation_failed', 'Call failed'); });
            return;
        }

        if (e.type === 'answer') {
            if (!s.pc) return;
            applyRemote(e.payload).catch(function () { hangup('negotiation_failed', 'Call failed'); });
            return;
        }

        if (e.type === 'ice') {
            if (!s.pc || !s.remoteReady) {
                // Candidates routinely beat the description they belong to.
                s.pendingIce.push(e.payload);
                return;
            }
            s.pc.addIceCandidate(new RTCIceCandidate(e.payload)).catch(function () {});
        }
    }

    function onStatus(e) {
        if (!s || e.call_uuid !== s.uuid) return;

        switch (e.status) {
            case 'accepted':  onAccepted(); break;
            case 'rejected':  finish('Call rejected'); break;
            case 'busy':      finish('User is busy'); break;
            case 'missed':    finish(s.isCaller ? 'No answer' : 'Missed call'); break;
            case 'cancelled': finish('Call cancelled'); break;
            case 'failed':    finish('Call failed'); break;
            case 'ended':     finish('Call ended'); break;
        }
    }

    /** Show a final label briefly, then tear everything down. */
    function finish(label, reason) {
        window.AppSound && window.AppSound.stopAllCallTones();
        stopTimer();
        hideIncoming();
        status(label);
        setTimeout(function () { hideDock(); cleanup(); }, 1400);
    }

    /**
     * Release every resource. Missing any one of these is how a call leaves the
     * mic light on, or leaks a peer connection into the next call.
     */
    function cleanup() {
        if (!s) return;

        // A sticky "incoming call" popup must not outlive the call itself.
        if (window.AppNotify) window.AppNotify.closeAll();

        if (s.ringTimer) clearTimeout(s.ringTimer);
        if (s.connectTimer) clearTimeout(s.connectTimer);
        stopTimer();

        if (s.localStream) {
            s.localStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
        }

        if (s.pc) {
            s.pc.onicecandidate = null;
            s.pc.ontrack = null;
            s.pc.onconnectionstatechange = null;
            s.pc.oniceconnectionstatechange = null;
            s.pc.onsignalingstatechange = null;
            try { s.pc.close(); } catch (e) {}
        }

        try { $audio.srcObject = null; } catch (e) {}
        $unblock.classList.remove('show');
        $unblock.setAttribute('aria-hidden', 'true');

        s = null;
    }

    function toggleMute() {
        if (!s || !s.localStream) return;
        s.muted = !s.muted;
        // Toggle the track rather than tearing the stream down: re-acquiring
        // the mic mid-call re-prompts on some browsers and drops audio.
        s.localStream.getAudioTracks().forEach(function (t) { t.enabled = !s.muted; });

        document.getElementById('callMute').classList.toggle('is-muted', s.muted);
        document.getElementById('callMuteIcon').className = s.muted ? 'bi bi-mic-mute-fill' : 'bi bi-mic-fill';
    }

    // ── Wiring ────────────────────────────────────────────────────────────
    function bind() {
        if (bound) return;          // listeners must be registered exactly once
        bound = true;

        document.getElementById('callAccept').addEventListener('click', acceptCall);
        document.getElementById('callReject').addEventListener('click', rejectCall);
        document.getElementById('callEnd').addEventListener('click', function () { hangup('hangup'); });
        document.getElementById('callMute').addEventListener('click', toggleMute);
        $unblock.addEventListener('click', function () {
            $audio.play().catch(function () {});
            $unblock.classList.remove('show');
        });

        if (window.Echo && ME) {
            // Same channel the layout already uses; Echo returns the cached
            // subscription rather than opening a second one.
            var channel = window.Echo.private('App.Models.User.' + ME);
            channel.listen('.call.ringing', onIncoming);
            channel.listen('.call.signal', onSignal);
            channel.listen('.call.status', onStatus);
        }

        // A full page navigation tears down the peer connection, so tell the
        // server rather than leaving a "ringing"/"accepted" row stranded.
        // sendBeacon survives unload where fetch does not.
        window.addEventListener('pagehide', endViaBeacon);
        window.addEventListener('beforeunload', function (e) {
            if (!s) return;
            endViaBeacon();
            e.preventDefault();
            e.returnValue = '';
            return '';
        });
    }

    function endViaBeacon() {
        if (!s || !s.uuid) return;
        try {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('reason', 'page_unload');
            navigator.sendBeacon('/calls/' + s.uuid + '/end', fd);
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    return { start: startCall, hangup: hangup, isActive: function () { return !!s; } };
})();
</script>
@endpush
