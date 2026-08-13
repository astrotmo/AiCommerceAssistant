<?php declare(strict_types=1);

/**
 * Controller to handle contact form submissions for AICA - AI Commerce Assistant.
 *
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
*/

namespace AiCommerceAssistant\Storefront\Controller;

use AiCommerceAssistant\Service\ChatTrackingService;
use AiCommerceAssistant\Service\ContactSubmissionMailService;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Class FormularController
 *
 * Handles JSON or form-encoded contact submissions from the storefront widget.
 *
 * Accepted fields are `name`, `email`, `phone`, `company`, `message`, `productRef`,
 * `quantity`, `deliveryZip`, `sourceContext` (or input alias `reason`), and the
 * `website` honeypot. Both trusted-AJAX headers are required. Valid submissions are
 * rate-limited by normalized email plus client IP. Mail delivery and analytics
 * persistence are best-effort: a validated request still returns `success: true`,
 * with optional `internalMailStatus`/`confirmationMailStatus`, when delivery fails.
 */
class FormularController extends AbstractController
{
    private const MAX_NAME_LENGTH = 120;

    private const MAX_EMAIL_LENGTH = 254;

    private const MAX_PHONE_LENGTH = 40;

    private const MAX_COMPANY_LENGTH = 120;

    private const MAX_PRODUCT_REF_LENGTH = 120;

    private const MAX_QUANTITY_LENGTH = 40;

    private const MAX_DELIVERY_ZIP_LENGTH = 20;

    private const MAX_SOURCE_CONTEXT_LENGTH = 255;

    private const MAX_MESSAGE_LENGTH = 5000;

    public function __construct(
        private readonly ContactSubmissionMailService $contactSubmissionMailService,
        private readonly RateLimiter $rateLimiter,
        private readonly ?ChatTrackingService $chatTrackingService = null,
    ) {
    }

    #[Route(
        path: '/aica/contact',
        name: 'frontend.aica.contact',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']],
    )]
    /**
     * @return JsonResponse JSON `{success: bool, error?: string, retryAfter?: int,
     *     internalMailStatus?: string, confirmationMailStatus?: string}`
     */
    public function send(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        if (!$this->isTrustedAjaxRequest($request)) {
            return new JsonResponse(['success' => false, 'error' => 'UNTRUSTED_REQUEST'], 400);
        }

        $payload = $this->readPayload($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'error' => 'INVALID_PAYLOAD'], 400);
        }

        // Honeypot field: bots often fill hidden fields. We silently accept to avoid feedback loops.
        if (trim((string) ($payload['website'] ?? '')) !== '') {
            return new JsonResponse(['success' => true]);
        }

        $name = trim((string) ($payload['name'] ?? 'Shop-Kunde'));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $company = trim((string) ($payload['company'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $productRef = trim((string) ($payload['productRef'] ?? ''));
        $quantity = trim((string) ($payload['quantity'] ?? ''));
        $deliveryZip = trim((string) ($payload['deliveryZip'] ?? ''));
        $sourceContext = trim((string) ($payload['sourceContext'] ?? ($payload['reason'] ?? '')));

        if (
            !$this->isWithinLength($name, self::MAX_NAME_LENGTH)
            || !$this->isWithinLength($email, self::MAX_EMAIL_LENGTH)
            || !$this->isWithinLength($phone, self::MAX_PHONE_LENGTH)
            || !$this->isWithinLength($company, self::MAX_COMPANY_LENGTH)
            || !$this->isWithinLength($productRef, self::MAX_PRODUCT_REF_LENGTH)
            || !$this->isWithinLength($quantity, self::MAX_QUANTITY_LENGTH)
            || !$this->isWithinLength($deliveryZip, self::MAX_DELIVERY_ZIP_LENGTH)
            || !$this->isWithinLength($sourceContext, self::MAX_SOURCE_CONTEXT_LENGTH)
            || !$this->isWithinLength($message, self::MAX_MESSAGE_LENGTH)
        ) {
            return new JsonResponse(['success' => false, 'error' => 'PAYLOAD_TOO_LARGE'], 400);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['success' => false, 'error' => 'INVALID_EMAIL'], 400);
        }

        if ($message === '') {
            return new JsonResponse(['success' => false, 'error' => 'EMPTY_MESSAGE'], 400);
        }

        $rateKey = strtolower($email) . '-' . ($request->getClientIp() ?? 'unknown');

        try {
            $this->rateLimiter->ensureAccepted(RateLimiter::CONTACT_FORM, $rateKey);
        } catch (RateLimitExceededException $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => 'RATE_LIMITED',
                'retryAfter' => $exception->getWaitTime(),
            ], 429);
        }

        $contactData = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
            'message' => $message,
            'productRef' => $productRef,
            'quantity' => $quantity,
            'deliveryZip' => $deliveryZip,
            'sourceContext' => $sourceContext,
        ];

        $sessionId = null;
        $customerId = $salesChannelContext->getCustomerId() ?: null;

        if ($this->chatTrackingService !== null) {
            try {
                // Resolve once so the same session reference is used for mails and persistence.
                $sessionId = $this->chatTrackingService->touchSession($salesChannelContext, $request);
            } catch (\Throwable) {
                $sessionId = null;
            }
        }

        $internalMailStatus = $this->contactSubmissionMailService->sendInternalNotification(
            $contactData,
            $salesChannelContext,
            $sessionId,
            $customerId,
        );

        $confirmationMailStatus = $this->contactSubmissionMailService->sendCustomerConfirmation(
            $contactData,
            $salesChannelContext,
            $sessionId,
            $customerId,
        );

        if ($this->chatTrackingService !== null) {
            try {
                $this->chatTrackingService->storeContactSubmission(
                    $salesChannelContext,
                    $request,
                    $contactData,
                    $internalMailStatus['recipientEmail'] ?? 'not-configured',
                    (bool) $confirmationMailStatus['sent'],
                    (bool) $internalMailStatus['sent'],
                    (bool) $confirmationMailStatus['attempted'],
                    (bool) $internalMailStatus['attempted'],
                    $this->toNullableString($confirmationMailStatus['error'] ?? null),
                    $this->toNullableString($internalMailStatus['error'] ?? null),
                    $sessionId,
                );
            } catch (\Throwable) {
                // Persistence is best-effort and must not affect the storefront flow.
            }
        }

        $response = ['success' => true];

        if (!(bool) $internalMailStatus['sent']) {
            $response['internalMailStatus'] = 'not-sent';
        }

        if ((bool) $confirmationMailStatus['attempted'] && !(bool) $confirmationMailStatus['sent']) {
            $response['confirmationMailStatus'] = 'not-sent';
        }

        return new JsonResponse($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(Request $request): ?array
    {
        $rawContent = trim((string) $request->getContent());

        if ($rawContent !== '') {
            $payload = json_decode($rawContent, true);
            if (\is_array($payload)) {
                return $payload;
            }
        }

        $payload = $request->request->all();

        return \is_array($payload) && $payload !== [] ? $payload : null;
    }

    private function isTrustedAjaxRequest(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            && $request->headers->get('X-AICA') === '1';
    }

    private function isWithinLength(string $value, int $maxLength): bool
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value) <= $maxLength;
        }

        return strlen($value) <= $maxLength;
    }

    private function toNullableString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
