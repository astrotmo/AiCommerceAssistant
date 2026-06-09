<?php declare(strict_types=1);

namespace AiCommerceAssistant\Tests\Storefront\Controller;

use AiCommerceAssistant\Storefront\Controller\ChatContextController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ChatContextControllerTest extends TestCase
{
    public function testRejectsUntrustedRequest(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/aica/context-token', 'GET'));

        $controller = new ChatContextController('secret-value', $requestStack);
        $context = $this->createSalesChannelContextMock();

        $response = $controller->contextToken($context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['error' => 'UNTRUSTED_REQUEST'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsMissingSecret(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->createTrustedRequest());

        $controller = new ChatContextController('', $requestStack);
        $context = $this->createSalesChannelContextMock();

        $response = $controller->contextToken($context);

        static::assertSame(500, $response->getStatusCode());
        static::assertSame(['error' => 'CHAT_AUTH_SECRET not configured'], json_decode((string) $response->getContent(), true));
    }

    public function testIssuedTokenContainsOnlyAnonymousClaims(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->createTrustedRequestWithSession());

        $controller = new ChatContextController('secret-value', $requestStack);
        $context = $this->createSalesChannelContextMock();

        $response = $controller->contextToken($context);

        static::assertSame(200, $response->getStatusCode());
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $json = json_decode((string) $response->getContent(), true);
        $payload = $this->decodeJwtPayload($json['token']);

        static::assertSame('sales-channel-id', $payload['salesChannelId']);
        static::assertArrayHasKey('sid', $payload);
        static::assertArrayHasKey('iat', $payload);
        static::assertArrayHasKey('exp', $payload);
        static::assertArrayHasKey('jti', $payload);
        static::assertArrayNotHasKey('sub', $payload);
        static::assertArrayNotHasKey('loggedIn', $payload);
        static::assertIsString($payload['sid']);
        static::assertNotSame('', $payload['sid']);
    }

    public function testSessionIdStaysStableAcrossCallsWithinSameSession(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->createTrustedRequestWithSession());

        $controller = new ChatContextController('secret-value', $requestStack);
        $context = $this->createSalesChannelContextMock();

        $first = json_decode((string) $controller->contextToken($context)->getContent(), true);
        $second = json_decode((string) $controller->contextToken($context)->getContent(), true);

        $payload1 = $this->decodeJwtPayload($first['token']);
        $payload2 = $this->decodeJwtPayload($second['token']);

        static::assertSame($payload1['sid'], $payload2['sid']);
    }

    private function createSalesChannelContextMock(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getToken')->willReturn('context-token-for-test');

        return $context;
    }

    private function createTrustedRequest(): Request
    {
        $request = Request::create('/aica/context-token', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('X-AICA', '1');

        return $request;
    }

    private function createTrustedRequestWithSession(): Request
    {
        $request = $this->createTrustedRequest();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        static::assertCount(3, $parts);

        $base64 = strtr($parts[1], '-_', '+/');
        $padding = (4 - (strlen($base64) % 4)) % 4;
        $decoded = base64_decode($base64 . str_repeat('=', $padding), true);

        static::assertNotFalse($decoded);

        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }
}

