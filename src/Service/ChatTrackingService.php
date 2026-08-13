<?php declare(strict_types=1);

namespace AiCommerceAssistant\Service;

use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalizes storefront analytics events into the four AICA DAL entities.
 *
 * Privacy/storage contract:
 * - normalized message text, selected rendered blocks/metadata, contact data, and the
 *   stable storefront session identifier are stored independently of debug mode;
 * - sessions without a customer id receive salted SHA-256 IP/user-agent/fingerprint
 *   values, while any context with a customer id (including guest-checkout customers)
 *   is linked by that id;
 * - linking that same session to a customer later does not clear guest hashes or raw
 *   identifiers captured by an earlier anonymous upsert;
 * - raw IP storage requires debug mode plus `storeRawIpAddress`;
 * - raw user-agent storage requires debug mode;
 * - the complete structured backend payload requires debug mode plus
 *   `storeRawDebugPayloads`.
 *
 * The `%kernel.secret%` value injected as `$hashSalt` makes guest hashes installation
 * specific. Controllers treat session/contact tracking as best-effort, but direct
 * message-event calls can surface repository failures to the tracking endpoint.
 */
class ChatTrackingService
{
    private const CONFIG_DEBUG_MODE = 'AiCommerceAssistant.config.enableDebugMode';

    private const CONFIG_STORE_RAW_DEBUG_PAYLOADS = 'AiCommerceAssistant.config.storeRawDebugPayloads';

    private const CONFIG_STORE_RAW_IP = 'AiCommerceAssistant.config.storeRawIpAddress';

    private const CONFIG_MODEL = 'AiCommerceAssistant.config.llmModel';

    public function __construct(
        private readonly EntityRepository $sessionRepository,
        private readonly EntityRepository $messageRepository,
        private readonly EntityRepository $contactSubmissionRepository,
        private readonly EntityRepository $productReferenceRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly ChatSessionResolver $chatSessionResolver,
        private readonly string $hashSalt,
    ) {
    }

    public function touchSession(
        SalesChannelContext $salesChannelContext,
        ?Request $request,
        ?string $storefrontSessionIdentifier = null,
        ?string $modelName = null,
    ): ?string {
        $context = $salesChannelContext->getContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $storefrontSessionIdentifier ??= $this->chatSessionResolver->resolveStorefrontSessionIdentifier(
            $salesChannelContext,
            $request,
        );

        if ($storefrontSessionIdentifier === '') {
            return null;
        }

        $sessionId = $this->findSessionIdByIdentifier($storefrontSessionIdentifier, $context);
        $isNew = $sessionId === null;
        $sessionId ??= Uuid::randomHex();

        $settings = $this->resolveStorageSettings($salesChannelId);

        $customerId = $salesChannelContext->getCustomerId();
        if ($customerId === '') {
            $customerId = null;
        }

        $rawIp = $this->normalizeString($request?->getClientIp(), 64);
        $userAgentRaw = $this->normalizeString((string) ($request?->headers->get('User-Agent') ?? ''), 512);

        $hashedIp = null;
        $hashedUserAgent = null;
        $guestFingerprintHash = null;

        // For guests, we persist only hashed identifiers outside debug mode.
        // A later customer-linked upsert omits these keys rather than clearing
        // values already captured for the same storefront session.
        if ($customerId === null) {
            $hashedIp = $this->hashValue($rawIp);
            $hashedUserAgent = $this->hashValue($userAgentRaw);
            $guestFingerprintHash = $this->hashValue(
                implode('|', [
                    $storefrontSessionIdentifier,
                    $hashedIp ?? '',
                    $hashedUserAgent ?? '',
                ]),
            );
        }

        $resolvedModel = $this->normalizeString($modelName, 255)
            ?? $this->normalizeString((string) ($this->systemConfigService->get(self::CONFIG_MODEL, $salesChannelId) ?? ''), 255);

        $payload = [
            'id' => $sessionId,
            'salesChannelId' => $salesChannelId,
            'languageId' => $salesChannelContext->getContext()->getLanguageId(),
            'storefrontSessionIdentifier' => $storefrontSessionIdentifier,
            'modelName' => $resolvedModel,
            'debugEnabledSnapshot' => $settings['debugEnabled'],
            'storeRawDebugPayloadsSnapshot' => $settings['storeRawDebugPayloads'],
        ];

        if ($isNew) {
            $payload['totalMessageCount'] = 0;
            $payload['responseLatencySampleCount'] = 0;
            $payload['contactFormShown'] = false;
            $payload['contactFormSubmitted'] = false;
        }

        if ($customerId !== null) {
            $payload['customerId'] = $customerId;
        }

        if ($hashedIp !== null) {
            $payload['hashedIp'] = $hashedIp;
        }

        if ($hashedUserAgent !== null) {
            $payload['hashedUserAgent'] = $hashedUserAgent;
        }

        if ($guestFingerprintHash !== null) {
            $payload['guestFingerprintHash'] = $guestFingerprintHash;
        }

        if ($settings['storeRawIp'] && $rawIp !== null) {
            $payload['rawIp'] = $rawIp;
        }

        if ($settings['debugEnabled'] && $userAgentRaw !== null) {
            $payload['userAgentRaw'] = $userAgentRaw;
        }

        $payload = $this->removeNullValues($payload);

        try {
            $this->sessionRepository->upsert([$payload], $context);
        } catch (\Throwable) {
            // Race condition fallback if another request created the session just now.
            $existingId = $this->findSessionIdByIdentifier($storefrontSessionIdentifier, $context);
            if ($existingId === null) {
                return null;
            }

            $payload['id'] = $existingId;
            $this->sessionRepository->upsert([$payload], $context);
            $sessionId = $existingId;
        }

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $eventPayload
     *
     * Recognized envelope fields include `payload`, `role`, `messageIndex`,
     * `messageType`, `text`, string-valued `requestId`, `model`, `tokenUsage`/`usage`,
     * `latencyMs`, `flags`, and `productReferences`.
     * The widget's numeric request ID therefore does not survive normalization.
     */
    public function trackMessageEvent(SalesChannelContext $salesChannelContext, Request $request, array $eventPayload): ?string
    {
        $payloadObject = $this->readArray($eventPayload, 'payload');

        $modelName = $this->extractModelName($eventPayload, $payloadObject);
        $sessionId = $this->touchSession($salesChannelContext, $request, null, $modelName);
        if ($sessionId === null) {
            return null;
        }

        $settings = $this->resolveStorageSettings($salesChannelContext->getSalesChannelId());
        $context = $salesChannelContext->getContext();

        $role = $this->normalizeRole($eventPayload['role'] ?? null);
        $messageIndex = $this->extractInt($eventPayload['messageIndex'] ?? null);
        if ($messageIndex === null || $messageIndex < 1) {
            $messageIndex = $this->resolveNextMessageIndex($sessionId, $context);
        }

        $tokenUsage = $this->extractTokenUsage($eventPayload, $payloadObject);
        $latencyMs = $this->extractLatencyMs($eventPayload, $payloadObject);

        $flags = $this->extractFlags($eventPayload, $payloadObject, $role);
        $messageType = $this->normalizeString($eventPayload['messageType'] ?? null, 64)
            ?? ($payloadObject !== null ? 'structured' : 'text');

        $rawText = $this->normalizeString($eventPayload['text'] ?? null, 65000);
        $renderedPayload = $this->buildRenderedPayload($payloadObject, $tokenUsage, $latencyMs, $modelName);
        $debugPayload = $settings['storeRawDebugPayloads'] ? $payloadObject : null;

        $messageId = Uuid::randomHex();

        $messageData = [
            'id' => $messageId,
            'sessionId' => $sessionId,
            'role' => $role,
            'messageIndex' => $messageIndex,
            'rawText' => $rawText,
            'renderedPayload' => $renderedPayload,
            'messageType' => $messageType,
            'blockKinds' => $this->extractBlockKinds($payloadObject),
            'tokenInput' => $tokenUsage['input'],
            'tokenOutput' => $tokenUsage['output'],
            'tokenTotal' => $tokenUsage['total'],
            'latencyMs' => $latencyMs,
            'modelName' => $modelName,
            'requestId' => $this->normalizeString($eventPayload['requestId'] ?? null, 64),
            'containsProductSuggestions' => $flags['containsProductSuggestions'],
            'containsInfoBox' => $flags['containsInfoBox'],
            'containsFormular' => $flags['containsFormular'],
            'containsToolPayload' => $flags['containsToolPayload'],
            'isError' => $flags['isError'],
            'debugPayload' => $debugPayload,
        ];

        $this->messageRepository->create([$this->removeNullValues($messageData)], $context);

        $this->persistProductReferences(
            $sessionId,
            $messageId,
            $this->extractProductReferences($eventPayload, $payloadObject, $role),
            $context,
        );

        $this->updateSessionAggregates(
            $sessionId,
            $salesChannelContext,
            $tokenUsage['input'],
            $tokenUsage['output'],
            $tokenUsage['total'],
            $latencyMs,
            $modelName,
        );

        return $messageId;
    }

    public function markContactFormShown(SalesChannelContext $salesChannelContext, Request $request): void
    {
        $sessionId = $this->touchSession($salesChannelContext, $request);
        if ($sessionId === null) {
            return;
        }

        $this->sessionRepository->upsert([
            [
                'id' => $sessionId,
                'contactFormShown' => true,
            ],
        ], $salesChannelContext->getContext());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function storeContactSubmission(
        SalesChannelContext $salesChannelContext,
        Request $request,
        array $payload,
        string $mailTargetAddress,
        bool $confirmationMailSent,
        bool $internalNotificationMailSent,
        bool $confirmationMailAttempted = false,
        bool $internalNotificationMailAttempted = true,
        ?string $confirmationMailError = null,
        ?string $internalNotificationMailError = null,
        ?string $sessionId = null,
    ): ?string {
        $context = $salesChannelContext->getContext();

        $sessionId ??= $this->touchSession($salesChannelContext, $request);

        $contactSubmissionId = Uuid::randomHex();
        $contactData = [
            'id' => $contactSubmissionId,
            'sessionId' => $sessionId,
            'customerId' => $salesChannelContext->getCustomerId() ?: null,
            'email' => $this->normalizeString($payload['email'] ?? null, 254),
            'name' => $this->normalizeString($payload['name'] ?? null, 120),
            'phone' => $this->normalizeString($payload['phone'] ?? null, 40),
            'company' => $this->normalizeString($payload['company'] ?? null, 120),
            'messageText' => $this->normalizeString($payload['message'] ?? null, 5000),
            'productReferenceText' => $this->normalizeString($payload['productRef'] ?? null, 255),
            'sourceContext' => $this->normalizeString($payload['sourceContext'] ?? ($payload['reason'] ?? null), 255),
            'mailTargetAddress' => $this->normalizeString($mailTargetAddress, 255),
            'confirmationMailAttempted' => $confirmationMailAttempted,
            'confirmationMailSent' => $confirmationMailSent,
            'confirmationMailError' => $this->normalizeString($confirmationMailError, 4000),
            'internalNotificationMailAttempted' => $internalNotificationMailAttempted,
            'internalNotificationMailSent' => $internalNotificationMailSent,
            'internalNotificationMailError' => $this->normalizeString($internalNotificationMailError, 4000),
        ];

        if ($contactData['email'] === null || $contactData['messageText'] === null || $contactData['mailTargetAddress'] === null) {
            return null;
        }

        $this->contactSubmissionRepository->create([$this->removeNullValues($contactData)], $context);

        if ($sessionId !== null) {
            $this->sessionRepository->upsert([
                [
                    'id' => $sessionId,
                    'contactFormShown' => true,
                    'contactFormSubmitted' => true,
                ],
            ], $context);
        }

        $productReference = $contactData['productReferenceText'];
        if (\is_string($productReference) && $productReference !== '') {
            $this->persistProductReferences(
                $sessionId,
                null,
                [[
                    'source' => 'contact',
                    'referenceText' => $productReference,
                ]],
                $context,
            );
        }

        return $contactSubmissionId;
    }

    private function resolveNextMessageIndex(string $sessionId, Context $context): int
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('sessionId', $sessionId))
            ->addSorting(new FieldSorting('messageIndex', FieldSorting::DESCENDING))
            ->setLimit(1);

        $entity = $this->messageRepository->search($criteria, $context)->first();
        if ($entity === null || !method_exists($entity, 'getMessageIndex')) {
            return 1;
        }

        $index = $entity->getMessageIndex();

        return (int) $index + 1;
    }

    private function updateSessionAggregates(
        string $sessionId,
        SalesChannelContext $salesChannelContext,
        ?int $tokenInput,
        ?int $tokenOutput,
        ?int $tokenTotal,
        ?int $latencyMs,
        ?string $modelName,
    ): void {
        $context = $salesChannelContext->getContext();

        $criteria = new Criteria([$sessionId]);
        /** @var ChatSessionEntity|null $session */
        $session = $this->sessionRepository->search($criteria, $context)->first();
        if ($session === null) {
            return;
        }

        $now = new \DateTimeImmutable();

        $totalInputTokens = $this->addNullableInts($session->getTotalInputTokens(), $tokenInput);
        $totalOutputTokens = $this->addNullableInts($session->getTotalOutputTokens(), $tokenOutput);
        $totalTokens = $this->addNullableInts($session->getTotalTokens(), $tokenTotal);

        if ($totalTokens === null && ($tokenInput !== null || $tokenOutput !== null)) {
            $totalTokens = ($totalInputTokens ?? 0) + ($totalOutputTokens ?? 0);
        }

        $update = [
            'id' => $sessionId,
            'totalMessageCount' => $session->getTotalMessageCount() + 1,
            'firstMessageAt' => $session->getFirstMessageAt() ?? $now,
            'lastMessageAt' => $now,
            'totalInputTokens' => $totalInputTokens,
            'totalOutputTokens' => $totalOutputTokens,
            'totalTokens' => $totalTokens,
        ];

        if ($modelName !== null) {
            $update['modelName'] = $modelName;
        }

        $customerId = $salesChannelContext->getCustomerId();
        if (\is_string($customerId) && $customerId !== '') {
            $update['customerId'] = $customerId;
        }

        if ($latencyMs !== null) {
            $sampleCount = max(0, $session->getResponseLatencySampleCount());
            $avg = $session->getAverageResponseLatencyMs() ?? 0.0;
            $newSampleCount = $sampleCount + 1;
            $newAverage = (($avg * $sampleCount) + $latencyMs) / $newSampleCount;

            $update['responseLatencySampleCount'] = $newSampleCount;
            $update['averageResponseLatencyMs'] = $newAverage;
        }

        $this->sessionRepository->upsert([$this->removeNullValues($update)], $context);
    }

    /**
     * @param list<array<string, mixed>> $references
     */
    private function persistProductReferences(?string $sessionId, ?string $messageId, array $references, Context $context): void
    {
        if ($references === []) {
            return;
        }

        $payload = [];
        foreach ($references as $reference) {
            $entry = [
                'id' => Uuid::randomHex(),
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'source' => $this->normalizeString($reference['source'] ?? null, 32) ?? 'assistant',
                'productId' => $this->normalizeString($reference['productId'] ?? null, 64),
                'productNumber' => $this->normalizeString($reference['productNumber'] ?? null, 128),
                'productName' => $this->normalizeString($reference['productName'] ?? null, 255),
                'referenceText' => $this->normalizeString($reference['referenceText'] ?? null, 255),
            ];

            if (
                $entry['productId'] === null
                && $entry['productNumber'] === null
                && $entry['productName'] === null
                && $entry['referenceText'] === null
            ) {
                continue;
            }

            $payload[] = $this->removeNullValues($entry);
        }

        if ($payload === []) {
            return;
        }

        $this->productReferenceRepository->create($payload, $context);
    }

    /**
     * @param array<string, mixed> $eventPayload
     * @param array<string, mixed>|null $payloadObject
     *
     * @return list<array<string, mixed>>
     */
    private function extractProductReferences(array $eventPayload, ?array $payloadObject, string $role): array
    {
        $references = [];

        $manualRefs = $this->readArray($eventPayload, 'productReferences');
        if ($manualRefs !== null) {
            foreach ($manualRefs as $manualRef) {
                if (!\is_array($manualRef)) {
                    continue;
                }

                $references[] = [
                    'source' => $this->normalizeString($manualRef['source'] ?? null, 32) ?? $role,
                    'productId' => $this->normalizeString($manualRef['productId'] ?? ($manualRef['id'] ?? null), 64),
                    'productNumber' => $this->normalizeString($manualRef['productNumber'] ?? null, 128),
                    'productName' => $this->normalizeString($manualRef['productName'] ?? ($manualRef['name'] ?? null), 255),
                    'referenceText' => $this->normalizeString($manualRef['referenceText'] ?? null, 255),
                ];
            }
        }

        if ($payloadObject !== null) {
            $blocks = $payloadObject['blocks'] ?? null;
            if (\is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (!\is_array($block) || ($block['kind'] ?? null) !== 'product_list') {
                        continue;
                    }

                    $products = $block['products'] ?? null;
                    if (!\is_array($products)) {
                        continue;
                    }

                    foreach ($products as $product) {
                        if (!\is_array($product)) {
                            continue;
                        }

                        $references[] = [
                            'source' => $role,
                            'productId' => $this->normalizeString($product['id'] ?? null, 64),
                            'productNumber' => $this->normalizeString($product['productNumber'] ?? null, 128),
                            'productName' => $this->normalizeString($product['name'] ?? null, 255),
                            'referenceText' => null,
                        ];
                    }
                }
            }
        }

        $deduplicated = [];
        $seen = [];

        foreach ($references as $reference) {
            $key = implode('|', [
                (string) ($reference['source'] ?? ''),
                (string) ($reference['productId'] ?? ''),
                (string) ($reference['productNumber'] ?? ''),
                (string) ($reference['productName'] ?? ''),
                (string) ($reference['referenceText'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $reference;
        }

        return $deduplicated;
    }

    /**
     * @param array<string, mixed>|null $payloadObject
     * @param array{input: ?int, output: ?int, total: ?int} $tokenUsage
     *
     * @return array<string, mixed>|null
     */
    private function buildRenderedPayload(
        ?array $payloadObject,
        array $tokenUsage,
        ?int $latencyMs,
        ?string $modelName,
    ): ?array {
        if ($payloadObject === null) {
            return null;
        }

        $stored = [];
        foreach (['type', 'message', 'reply', 'blocks', 'status', 'error'] as $key) {
            if (array_key_exists($key, $payloadObject)) {
                $stored[$key] = $payloadObject[$key];
            }
        }

        $meta = [];
        if ($tokenUsage['input'] !== null) {
            $meta['inputTokens'] = $tokenUsage['input'];
        }
        if ($tokenUsage['output'] !== null) {
            $meta['outputTokens'] = $tokenUsage['output'];
        }
        if ($tokenUsage['total'] !== null) {
            $meta['totalTokens'] = $tokenUsage['total'];
        }
        if ($latencyMs !== null) {
            $meta['latencyMs'] = $latencyMs;
        }
        if ($modelName !== null) {
            $meta['model'] = $modelName;
        }

        if ($meta !== []) {
            $stored['meta'] = $meta;
        }

        if ($stored === []) {
            return null;
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $eventPayload
     * @param array<string, mixed>|null $payloadObject
     *
     * @return array{containsProductSuggestions: bool, containsInfoBox: bool, containsFormular: bool, containsToolPayload: bool, isError: bool}
     */
    private function extractFlags(array $eventPayload, ?array $payloadObject, string $role): array
    {
        $flags = [
            'containsProductSuggestions' => false,
            'containsInfoBox' => false,
            'containsFormular' => false,
            'containsToolPayload' => $role === 'tool',
            'isError' => (bool) ($eventPayload['isError'] ?? false),
        ];

        $flagPayload = $this->readArray($eventPayload, 'flags');
        if ($flagPayload !== null) {
            $flags['containsProductSuggestions'] = (bool) ($flagPayload['containsProductSuggestions'] ?? $flags['containsProductSuggestions']);
            $flags['containsInfoBox'] = (bool) ($flagPayload['containsInfoBox'] ?? $flags['containsInfoBox']);
            $flags['containsFormular'] = (bool) ($flagPayload['containsFormular'] ?? $flags['containsFormular']);
            $flags['containsToolPayload'] = (bool) ($flagPayload['containsToolPayload'] ?? $flags['containsToolPayload']);
            $flags['isError'] = (bool) ($flagPayload['isError'] ?? $flags['isError']);
        }

        if ($payloadObject !== null) {
            if (isset($payloadObject['error'])) {
                $flags['isError'] = true;
            }

            foreach (['tool', 'toolResult', 'toolResults', 'toolCalls', 'toolResponse'] as $toolKey) {
                if (array_key_exists($toolKey, $payloadObject)) {
                    $flags['containsToolPayload'] = true;
                    break;
                }
            }

            $blocks = $payloadObject['blocks'] ?? null;
            if (\is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (!\is_array($block)) {
                        continue;
                    }

                    $kind = $this->normalizeString($block['kind'] ?? null, 64);
                    if ($kind === 'product_list') {
                        $flags['containsProductSuggestions'] = true;
                    }
                    if ($kind === 'info_box') {
                        $flags['containsInfoBox'] = true;
                    }
                    if ($kind === 'formular') {
                        $flags['containsFormular'] = true;
                    }
                    if (str_starts_with((string) $kind, 'tool')) {
                        $flags['containsToolPayload'] = true;
                    }
                }
            }
        }

        return $flags;
    }

    /**
     * @param array<string, mixed>|null $payloadObject
     *
     * @return list<string>|null
     */
    private function extractBlockKinds(?array $payloadObject): ?array
    {
        if ($payloadObject === null) {
            return null;
        }

        $blocks = $payloadObject['blocks'] ?? null;
        if (!\is_array($blocks) || $blocks === []) {
            return null;
        }

        $kinds = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $kind = $this->normalizeString($block['kind'] ?? null, 64);
            if ($kind === null) {
                continue;
            }

            $kinds[$kind] = true;
        }

        return $kinds === [] ? null : array_keys($kinds);
    }

    /**
     * @param array<string, mixed> $eventPayload
     * @param array<string, mixed>|null $payloadObject
     *
     * @return array{input: ?int, output: ?int, total: ?int}
     */
    private function extractTokenUsage(array $eventPayload, ?array $payloadObject): array
    {
        $candidates = [];

        $directTokenUsage = $this->readArray($eventPayload, 'tokenUsage');
        if ($directTokenUsage !== null) {
            $candidates[] = $directTokenUsage;
        }

        $directUsage = $this->readArray($eventPayload, 'usage');
        if ($directUsage !== null) {
            $candidates[] = $directUsage;
        }

        if ($payloadObject !== null) {
            foreach (['tokenUsage', 'usage'] as $key) {
                $candidate = $this->readArray($payloadObject, $key);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            $metrics = $this->readArray($payloadObject, 'metrics');
            if ($metrics !== null) {
                foreach (['tokenUsage', 'usage'] as $key) {
                    $candidate = $this->readArray($metrics, $key);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        $input = null;
        $output = null;
        $total = null;

        foreach ($candidates as $candidate) {
            $input ??= $this->extractIntByKeys($candidate, ['input', 'inputTokens', 'input_tokens', 'promptTokens', 'prompt_tokens']);
            $output ??= $this->extractIntByKeys($candidate, ['output', 'outputTokens', 'output_tokens', 'completionTokens', 'completion_tokens']);
            $total ??= $this->extractIntByKeys($candidate, ['total', 'totalTokens', 'total_tokens']);
        }

        if ($total === null && ($input !== null || $output !== null)) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return [
            'input' => $input,
            'output' => $output,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $eventPayload
     * @param array<string, mixed>|null $payloadObject
     */
    private function extractLatencyMs(array $eventPayload, ?array $payloadObject): ?int
    {
        $latency = $this->extractInt($eventPayload['latencyMs'] ?? null)
            ?? $this->extractInt($eventPayload['latency'] ?? null)
            ?? $this->extractInt($eventPayload['responseTimeMs'] ?? null);

        if ($latency !== null) {
            return $latency;
        }

        if ($payloadObject === null) {
            return null;
        }

        foreach (['latencyMs', 'latency_ms', 'latency', 'responseLatencyMs', 'durationMs'] as $key) {
            $latency = $this->extractInt($payloadObject[$key] ?? null);
            if ($latency !== null) {
                return $latency;
            }
        }

        $metrics = $this->readArray($payloadObject, 'metrics');
        if ($metrics !== null) {
            foreach (['latencyMs', 'latency', 'responseTimeMs', 'durationMs'] as $key) {
                $latency = $this->extractInt($metrics[$key] ?? null);
                if ($latency !== null) {
                    return $latency;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $eventPayload
     * @param array<string, mixed>|null $payloadObject
     */
    private function extractModelName(array $eventPayload, ?array $payloadObject): ?string
    {
        $model = $this->normalizeString($eventPayload['model'] ?? null, 255);
        if ($model !== null) {
            return $model;
        }

        if ($payloadObject === null) {
            return null;
        }

        return $this->normalizeString($payloadObject['model'] ?? ($payloadObject['llmModel'] ?? null), 255);
    }

    private function normalizeRole(mixed $role): string
    {
        $normalized = strtolower((string) $role);

        if (!\in_array($normalized, ['user', 'assistant', 'system', 'tool'], true)) {
            return 'assistant';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private function extractIntByKeys(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $candidate = $this->extractInt($values[$key]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int) round($value);
        }

        if (\is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                return null;
            }

            return (int) round((float) $trimmed);
        }

        return null;
    }

    private function normalizeString(mixed $value, int $maxLength): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>|null
     */
    private function readArray(array $values, string $key): ?array
    {
        $value = $values[$key] ?? null;

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function removeNullValues(array $payload): array
    {
        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private function addNullableInts(?int $left, ?int $right): ?int
    {
        if ($left === null && $right === null) {
            return null;
        }

        return ($left ?? 0) + ($right ?? 0);
    }

    private function hashValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', $this->hashSalt . ':' . $value);
    }

    /**
     * @return array{debugEnabled: bool, storeRawDebugPayloads: bool, storeRawIp: bool}
     */
    private function resolveStorageSettings(string $salesChannelId): array
    {
        $debugEnabled = (bool) ($this->systemConfigService->get(self::CONFIG_DEBUG_MODE, $salesChannelId) ?? false);
        $storeRawDebugPayloads = $debugEnabled && (bool) (
            $this->systemConfigService->get(self::CONFIG_STORE_RAW_DEBUG_PAYLOADS, $salesChannelId) ?? false
        );
        $storeRawIp = $debugEnabled && (bool) (
            $this->systemConfigService->get(self::CONFIG_STORE_RAW_IP, $salesChannelId) ?? false
        );

        return [
            'debugEnabled' => $debugEnabled,
            'storeRawDebugPayloads' => $storeRawDebugPayloads,
            'storeRawIp' => $storeRawIp,
        ];
    }

    private function findSessionIdByIdentifier(string $storefrontSessionIdentifier, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('storefrontSessionIdentifier', $storefrontSessionIdentifier))
            ->setLimit(1);

        return $this->sessionRepository->searchIds($criteria, $context)->firstId();
    }
}
