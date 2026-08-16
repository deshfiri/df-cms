<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

/**
 * End-to-end health check for the realtime stack, layer by layer, so a failure
 * points at one thing instead of "websockets are broken".
 *
 *   1. config      — driver, credential agreement, bind vs public host
 *   2. daemon      — is anything listening on the Reverb port
 *   3. direct      — WebSocket handshake straight at the daemon (no nginx)
 *   4. public      — same handshake through nginx/TLS on the public host
 *   5. push        — Laravel -> Reverb HTTP API, the /apps leg
 *
 * Run on the server after a deploy. Exits non-zero if any layer fails, so CI
 * can gate on it.
 */
class VerifyReverb extends Command
{
    protected $signature = 'reverb:verify
                            {--skip-public : Only test the local daemon, not the public HTTPS route}
                            {--timeout=10 : Socket timeout in seconds}';

    protected $description = 'Verify the Reverb realtime stack end to end (config, daemon, proxy, push)';

    private array $failures = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('Reverb verification');
        $this->line(str_repeat('─', 52));

        $key    = (string) config('broadcasting.connections.reverb.key');
        $scheme = (string) (config('broadcasting.connections.reverb.options.scheme') ?: 'https');
        $host   = (string) config('broadcasting.connections.reverb.options.host');
        $port   = (int) (config('broadcasting.connections.reverb.options.port') ?: ($scheme === 'https' ? 443 : 80));

        $bindHost = (string) config('reverb.servers.reverb.host');
        $bindPort = (int) config('reverb.servers.reverb.port');

        $this->checkConfig($key, $host, $port, $scheme, $bindHost, $bindPort);
        $this->checkDaemon($bindHost, $bindPort);
        $this->checkHandshake('direct', 'http', $bindHost === '0.0.0.0' ? '127.0.0.1' : $bindHost, $bindPort, $key, $scheme . '://' . $host);

        if ($this->option('skip-public')) {
            $this->step('public', null, 'skipped (--skip-public)');
        } else {
            $this->checkHandshake('public', $scheme, $host, $port, $key, $scheme . '://' . $host);
        }

        $this->checkPush();

        $this->line(str_repeat('─', 52));

        if ($this->failures) {
            $this->error('FAILED: ' . count($this->failures) . ' check(s) did not pass.');
            foreach ($this->failures as $failure) {
                $this->line('  • ' . $failure);
            }
            $this->line('');

            return self::FAILURE;
        }

        $this->info('All checks passed — realtime is healthy.');
        $this->line('');

        return self::SUCCESS;
    }

    private function checkConfig(string $key, string $host, int $port, string $scheme, string $bindHost, int $bindPort): void
    {
        $driver = config('broadcasting.default');
        $this->step('driver', $driver === 'reverb', 'broadcasting.default = ' . ($driver ?: 'null'));

        $this->step('credentials', filled($key) && filled(config('broadcasting.connections.reverb.secret')),
            'app key ' . (filled($key) ? 'set' : 'EMPTY — browsers cannot connect'));

        $appKey = config('reverb.apps.apps.0.key');
        $appId  = (string) config('reverb.apps.apps.0.app_id');
        $this->step('key agreement', $appKey === $key && $appId === (string) config('broadcasting.connections.reverb.app_id'),
            'reverb.apps matches broadcasting.connections');

        // The classic misconfiguration: the daemon's bind address leaking into
        // the value browsers are told to dial. Legitimate on a dev box, fatal in
        // production — so only hard-fail there.
        $publicOk = filled($host) && !in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'], true);
        $this->step('public host', $this->gradeByEnv($publicOk),
            "browsers dial {$scheme}://{$host}:{$port}" . ($publicOk ? '' : ' — loopback is not reachable from a browser'));

        $bindOk = $bindHost !== '0.0.0.0';
        $this->step('bind address', $this->gradeByEnv($bindOk),
            "daemon binds {$bindHost}:{$bindPort}" . ($bindOk ? '' : ' — port is publicly exposed, bind 127.0.0.1'));

        $origins = config('reverb.apps.apps.0.allowed_origins');
        $this->step('allowed origins', true, implode(', ', (array) $origins));
    }

    private function checkDaemon(string $bindHost, int $bindPort): void
    {
        $target = $bindHost === '0.0.0.0' ? '127.0.0.1' : $bindHost;
        $sock = @fsockopen($target, $bindPort, $errno, $errstr, (float) $this->option('timeout'));

        if ($sock) {
            fclose($sock);
            $this->step('daemon', true, "listening on {$target}:{$bindPort}");

            return;
        }

        $this->step('daemon', false, "nothing listening on {$target}:{$bindPort} — {$errstr}");
    }

    /** Perform a real RFC6455 upgrade and require 101 Switching Protocols. */
    private function checkHandshake(string $label, string $scheme, string $host, int $port, string $key, string $origin): void
    {
        if (blank($key)) {
            $this->step($label, false, 'no app key to handshake with');

            return;
        }

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $timeout   = (float) $this->option('timeout');

        $context = stream_context_create([
            'ssl' => ['SNI_enabled' => true, 'peer_name' => $host],
        ]);

        $sock = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$sock) {
            $this->step($label, false, "connect to {$scheme}://{$host}:{$port} failed — {$errstr} ({$errno})");

            return;
        }

        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $hostHeader    = $isDefaultPort ? $host : "{$host}:{$port}";
        $path          = "/app/{$key}?protocol=7&client=reverb-verify&version=8.4.0";

        fwrite($sock, implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version: 13',
            "Origin: {$origin}",
            '', '',
        ]));

        stream_set_timeout($sock, (int) $timeout);
        $status = trim((string) fgets($sock, 2048));
        fclose($sock);

        if (str_contains($status, '101')) {
            $this->step($label, true, "{$scheme}://{$host}:{$port} → {$status}");

            return;
        }

        $hint = match (true) {
            str_contains($status, '404'), str_contains($status, '200') => ' (nginx sent it to Laravel — /app is not proxied)',
            str_contains($status, '502'), str_contains($status, '504') => ' (proxy matched but cannot reach the daemon)',
            str_contains($status, '403')                               => ' (origin rejected — check allowed_origins)',
            $status === ''                                             => ' (no response — connection closed immediately)',
            default                                                    => '',
        };

        $this->step($label, false, "{$scheme}://{$host}:{$port} → " . ($status ?: 'no status line') . $hint);
    }

    /** The /apps leg: Laravel pushing an event into Reverb over HTTP. */
    private function checkPush(): void
    {
        try {
            Broadcast::connection('reverb')->broadcast(
                ['reverb-verify'],
                'verify.ping',
                ['at' => now()->toIso8601String()]
            );

            $this->step('push', true, 'Laravel → Reverb event accepted');
        } catch (\Throwable $e) {
            $this->step('push', false, 'server-side push failed — ' . class_basename($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Some settings are wrong only in production — pointing browsers at
     * 127.0.0.1 is perfectly normal on a dev box. Fail there, warn elsewhere.
     */
    private function gradeByEnv(bool $ok): ?bool
    {
        if ($ok) {
            return true;
        }

        return app()->isProduction() ? false : null;
    }

    private function step(string $label, ?bool $ok, string $detail): void
    {
        $icon = match ($ok) {
            true    => '<info>  ok  </info>',
            false   => '<fg=red> FAIL </>',
            default => '<comment> warn </comment>',
        };

        $this->line(sprintf('%s %-14s %s', $icon, $label, $detail));

        if ($ok === false) {
            $this->failures[] = "{$label}: {$detail}";
        }
    }
}
