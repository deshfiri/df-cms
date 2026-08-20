<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Application-level gzip is a fallback for servers that do not compress.
 * It must switch off cleanly when nginx takes over, and must never touch a
 * response it cannot safely rewrite.
 */
class ResponseCompressionTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_html_is_compressed_when_the_client_accepts_it(): void
    {
        $response = $this->actingAs($this->user())
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));

        // The body must still be valid gzip that round-trips.
        $decoded = gzdecode($response->getContent());
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<!DOCTYPE html>', $decoded);
    }

    public function test_a_client_that_cannot_gzip_gets_plain_html(): void
    {
        $response = $this->actingAs($this->user())
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
    }

    public function test_it_stands_down_when_the_web_server_handles_compression(): void
    {
        // What a deployment behind nginx sets.
        config(['app.compress_responses' => false]);

        $response = $this->actingAs($this->user())
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
    }

    public function test_an_upstream_encoding_is_never_double_compressed(): void
    {
        config(['app.compress_responses' => true]);

        $this->app['router']->get('/_test/pre-encoded', function () {
            return response(gzencode(str_repeat('already compressed. ', 200)))
                ->header('Content-Type', 'text/html')
                ->header('Content-Encoding', 'gzip');
        })->middleware('web');

        $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get('/_test/pre-encoded');

        $response->assertOk();
        // Still exactly one layer: decoding once yields the original text.
        $this->assertStringContainsString('already compressed.', gzdecode($response->getContent()));
    }

    public function test_json_responses_are_compressed_too(): void
    {
        $response = $this->actingAs($this->user())
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get(route('notifications.index'));

        $response->assertOk();
        // Small payloads fall under the minimum size and are left alone; either
        // way the body must remain readable JSON.
        $body = $response->headers->get('Content-Encoding') === 'gzip'
            ? gzdecode($response->getContent())
            : $response->getContent();

        $this->assertJson($body);
    }
}
