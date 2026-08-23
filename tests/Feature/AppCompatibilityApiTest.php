<?php

namespace Tests\Feature;

use App\Exceptions\UnsupportedFrontendVersion;
use App\Http\Middleware\EnsureSupportedFrontendVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AppCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_compatibility_endpoint_returns_version_status(): void
    {
        config(['lonepawn.frontend_min_supported_version' => '1.2.0']);

        $this->getJson('/api/app/compatibility', [
            'X-LonePawn-App-Version' => '1.1.0',
        ])->assertOk()->assertJsonPath('data.installed_version', '1.1.0')
            ->assertJsonPath('data.minimum_supported_version', '1.2.0')
            ->assertJsonPath('data.is_supported', false);
    }

    public function test_middleware_allows_reads_and_supported_writes(): void
    {
        config(['lonepawn.frontend_min_supported_version' => '1.2.0']);
        $middleware = app(EnsureSupportedFrontendVersion::class);
        $next = fn () => new Response('allowed');

        $readResponse = $middleware->handle(Request::create('/api/tenant/customers', 'GET'), $next);
        $writeResponse = $middleware->handle(Request::create(
            '/api/tenant/customers',
            'POST',
            server: ['HTTP_X_LONEPAWN_APP_VERSION' => '1.2.0'],
        ), $next);

        $this->assertSame('allowed', $readResponse->getContent());
        $this->assertSame('allowed', $writeResponse->getContent());
    }

    public function test_middleware_rejects_outdated_writes_and_allows_logout(): void
    {
        config(['lonepawn.frontend_min_supported_version' => '1.2.0']);
        $middleware = app(EnsureSupportedFrontendVersion::class);
        $next = fn () => new Response('allowed');

        try {
            $middleware->handle(Request::create(
                '/api/tenant/customers',
                'POST',
                server: ['HTTP_X_LONEPAWN_APP_VERSION' => '1.1.0'],
            ), $next);
            $this->fail('Unsupported frontend version was not rejected.');
        } catch (UnsupportedFrontendVersion $exception) {
            $response = $exception->render(Request::create('/api/tenant/customers', 'POST'));
            $this->assertSame(426, $response->getStatusCode());
            $this->assertSame('UNSUPPORTED_FRONTEND_VERSION', $response->getData(true)['data']['code']);
        }

        $logoutResponse = $middleware->handle(Request::create('/api/tenant/logout', 'POST'), $next);
        $this->assertSame('allowed', $logoutResponse->getContent());
    }
}
