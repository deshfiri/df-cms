<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ICE servers
    |--------------------------------------------------------------------------
    |
    | STUN lets peers discover their public address, which is enough when at
    | least one side is directly reachable. TURN relays the audio when it is
    | not — symmetric NAT, restrictive corporate firewalls and a lot of mobile
    | carriers. STUN alone will appear to work on a shared office network and
    | then fail in the field, so production must configure TURN.
    |
    */

    'stun_url' => env('WEBRTC_STUN_URL', 'stun:stun.l.google.com:19302'),

    'turn_url'  => env('WEBRTC_TURN_URL'),
    'turn_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBRTC_TURN_URLS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | TURN authentication
    |--------------------------------------------------------------------------
    |
    | Preferred: coturn's shared-secret mode (`use-auth-secret`). The server
    | mints a username/credential pair valid for a few minutes, so the browser
    | never receives a long-lived secret and a leaked pair expires by itself.
    | The static username/password path exists only as a fallback and hands the
    | client a permanent credential — see DEPLOYMENT.md for the tradeoff.
    |
    */

    'turn_secret' => env('WEBRTC_TURN_SECRET'),

    'turn_username'   => env('WEBRTC_TURN_USERNAME'),
    'turn_credential' => env('WEBRTC_TURN_CREDENTIAL'),

    /** How long a minted TURN credential stays valid (seconds). */
    'turn_ttl' => (int) env('WEBRTC_TURN_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Call behaviour
    |--------------------------------------------------------------------------
    */

    /** Unanswered ringing calls become "missed" after this many seconds. */
    'ring_timeout' => (int) env('WEBRTC_RING_TIMEOUT', 30),

    /**
     * Safety net for calls whose participants vanished (tab closed with no
     * beacon, laptop lid shut). The reconciler force-ends answered calls that
     * have run longer than this.
     */
    'max_duration' => (int) env('WEBRTC_MAX_CALL_SECONDS', 14400),

    /**
     * Force every candidate through TURN. Diagnostic only — it proves the relay
     * path works. Never leave enabled in production: it routes all audio
     * through your server and adds latency.
     */
    'force_relay' => (bool) env('WEBRTC_FORCE_RELAY', false),

];
