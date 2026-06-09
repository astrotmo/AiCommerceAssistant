<?php declare(strict_types=1);

namespace AiCommerceAssistant\Tests\Storefront\Controller;

use AiCommerceAssistant\Storefront\Controller\FormularController;
use AiCommerceAssistant\Service\ContactSubmissionMailService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class FormularControllerTest extends TestCase
{
    public function testRejectsUntrustedRequest(): void
    {
        $contactSubmissionMailService = $this->createMock(ContactSubmissionMailService::class);
        $contactSubmissionMailService->expects(static::never())->method('sendInternalNotification');
        $contactSubmissionMailService->expects(static::never())->method('sendCustomerConfirmation');

        $controller = new FormularController(
            $contactSubmissionMailService,
            $this->createMock(RateLimiter::class),
        );

        $context = $this->createSalesChannelContextMock();
        $request = Request::create('/aica/contact', 'POST', [], [], [], [], json_encode([
            'email' => 'john@example.test',
            'message' => 'Hi',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->send($request, $context);

        static::assertSame(400, $response->getStatusCode());
        static::assertSame(['success' => false, 'error' => 'UNTRUSTED_REQUEST'], json_decode((string) $response->getContent(), true));
    }

    public function testHoneypotReturnsSuccessWithoutSendingMail(): void
    {
        $contactSubmissionMailService = $this->createMock(ContactSubmissionMailService::class);
        $contactSubmissionMailService->expects(static::never())->method('sendInternalNotification');
        $contactSubmissionMailService->expects(static::never())->method('sendCustomerConfirmation');

        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(static::never())->method('ensureAccepted');

        $controller = new FormularController(
            $contactSubmissionMailService,
            $rateLimiter,
        );

        $context = $this->createSalesChannelContextMock();
        $request = $this->createTrustedJsonRequest([
            'name' => 'John',
            'email' => 'john@example.test',
            'message' => 'Hello',
            'website' => 'http://spam.invalid',
        ]);

        $response = $controller->send($request, $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['success' => true], json_decode((string) $response->getContent(), true));
    }

    public function testReturnsRateLimitedWhenLimiterBlocksRequest(): void
    {
        $contactSubmissionMailService = $this->createMock(ContactSubmissionMailService::class);
        $contactSubmissionMailService->expects(static::never())->method('sendInternalNotification');
        $contactSubmissionMailService->expects(static::never())->method('sendCustomerConfirmation');

        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter
            ->expects(static::once())
            ->method('ensureAccepted')
            ->willThrowException(new RateLimitExceededException(time() + 60));

        $controller = new FormularController(
            $contactSubmissionMailService,
            $rateLimiter,
        );

        $context = $this->createSalesChannelContextMock();
        $request = $this->createTrustedJsonRequest([
            'name' => 'John',
            'email' => 'john@example.test',
            'message' => 'Hello',
        ]);

        $response = $controller->send($request, $context);

        static::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        static::assertSame(false, $data['success']);
        static::assertSame('RATE_LIMITED', $data['error']);
        static::assertArrayHasKey('retryAfter', $data);
    }

    public function testValidRequestSendsMail(): void
    {
        $contactSubmissionMailService = $this->createMock(ContactSubmissionMailService::class);
        $contactSubmissionMailService
            ->expects(static::once())
            ->method('sendInternalNotification')
            ->with(
                static::callback(function (array $data): bool {
                    return $data['email'] === 'john@example.test'
                        && $data['name'] === 'John Doe'
                        && $data['message'] === 'Need pricing'
                        && $data['productRef'] === 'SKU-1';
                }),
                $context = $this->createSalesChannelContextMock(),
                null,
                null,
            )
            ->willReturn([
                'attempted' => true,
                'sent' => true,
                'error' => null,
                'recipientEmail' => 'sales@example.test',
            ]);

        $contactSubmissionMailService
            ->expects(static::once())
            ->method('sendCustomerConfirmation')
            ->willReturn([
                'attempted' => true,
                'sent' => true,
                'error' => null,
            ]);

        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(static::once())->method('ensureAccepted');

        $controller = new FormularController(
            $contactSubmissionMailService,
            $rateLimiter,
        );

        $request = $this->createTrustedJsonRequest([
            'name' => 'John Doe',
            'email' => 'john@example.test',
            'message' => 'Need pricing',
            'productRef' => 'SKU-1',
        ]);

        $response = $controller->send($request, $context);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['success' => true], json_decode((string) $response->getContent(), true));
    }

    private function createTrustedJsonRequest(array $payload): Request
    {
        $request = Request::create(
            '/aica/contact',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.10'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('X-AICA', '1');

        return $request;
    }

    private function createSalesChannelContextMock(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getCustomerId')->willReturn(null);

        return $context;
    }
}
