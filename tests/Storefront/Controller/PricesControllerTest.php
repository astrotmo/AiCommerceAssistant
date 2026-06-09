<?php declare(strict_types=1);

namespace AiCommerceAssistant\Tests\Storefront\Controller;

use AiCommerceAssistant\Storefront\Controller\PricesController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class PricesControllerTest extends TestCase
{
    public function testRejectsUntrustedRequest(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository);
        $context = $this->createCustomerContext();

        $request = Request::create('/aica/prices', 'POST', [], [], [], [], json_encode([
            'productIds' => ['018f58d95ce17037af93f4e36073f9ea'],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->prices($request, $context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['error' => 'UNTRUSTED_REQUEST'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsGuestUser(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel');
        $context->method('getCustomer')->willReturn(null);

        $request = $this->createTrustedJsonRequest([
            'productIds' => ['018f58d95ce17037af93f4e36073f9ea'],
        ]);

        $response = $controller->prices($request, $context);

        static::assertSame(401, $response->getStatusCode());
        static::assertSame(['error' => 'NOT_LOGGED_IN'], json_decode((string) $response->getContent(), true));
    }

    public function testAllowsGuestValidationWhenPricesAreVisibleForEveryone(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository, 'all');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel');
        $context->method('getCustomer')->willReturn(null);

        $request = $this->createTrustedJsonRequest([
            'productIds' => ['not-a-uuid'],
        ]);

        $response = $controller->prices($request, $context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['error' => 'INVALID_PRODUCT_IDS'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsWhenPricesAreDisabled(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository, 'none');
        $context = $this->createCustomerContext();

        $request = $this->createTrustedJsonRequest([
            'productIds' => ['018f58d95ce17037af93f4e36073f9ea'],
        ]);

        $response = $controller->prices($request, $context);

        static::assertSame(403, $response->getStatusCode());
        static::assertSame(['error' => 'PRICES_DISABLED'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsTooManyProductIds(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository);
        $context = $this->createCustomerContext();

        $ids = [];
        for ($i = 0; $i < 51; $i += 1) {
            $ids[] = sprintf('018f58d95ce17037af93f4e36073f9%02x', $i);
        }

        $request = $this->createTrustedJsonRequest(['productIds' => $ids]);
        $response = $controller->prices($request, $context);

        static::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        static::assertSame('TOO_MANY_PRODUCT_IDS', $data['error']);
        static::assertSame(50, $data['max']);
    }

    public function testRejectsInvalidProductIds(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository);
        $context = $this->createCustomerContext();

        $request = $this->createTrustedJsonRequest([
            'productIds' => ['not-a-uuid', '  ', 42],
        ]);

        $response = $controller->prices($request, $context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['error' => 'INVALID_PRODUCT_IDS'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsInvalidPayload(): void
    {
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(static::never())->method('search');

        $controller = $this->createController($repository);
        $context = $this->createCustomerContext();

        $request = Request::create('/aica/prices', 'POST', [], [], [], [], 'not-json');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('X-AICA', '1');

        $response = $controller->prices($request, $context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['error' => 'INVALID_PAYLOAD'], json_decode((string) $response->getContent(), true));
    }

    private function createTrustedJsonRequest(array $payload): Request
    {
        $request = Request::create(
            '/aica/prices',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('X-AICA', '1');

        return $request;
    }

    private function createCustomerContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel');
        $context->method('getCustomer')->willReturn(new CustomerEntity());

        return $context;
    }

    private function createController(SalesChannelRepository $repository, ?string $priceVisibility = null): PricesController
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($priceVisibility);

        return new PricesController($repository, $config);
    }
}
