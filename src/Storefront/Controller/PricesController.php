<?php declare(strict_types=1);

namespace AiCommerceAssistant\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enriches at most 50 valid Shopware product UUIDs with sales-channel prices.
 *
 * The route requires `X-Requested-With: XMLHttpRequest` and `X-AICA: 1`. Visibility
 * is enforced server-side from `productPriceVisibility`: `none` returns 403,
 * `customers` requires a customer on the sales-channel context, and `all` is public.
 *
 * Request: `{productIds: string[]}`. Response: `{prices: {[productId]: {
 * name, calculatedPrice, calculatedPrices, hasAdvancedPrices, currency}}}`.
 */
class PricesController extends StorefrontController
{
    private const MAX_PRODUCT_IDS = 50;

    private const CONFIG_PREFIX = 'AiCommerceAssistant.config.';

    private const PRODUCT_PRICE_VISIBILITY = 'productPriceVisibility';

    private const PRICE_VISIBILITY_ALL = 'all';

    private const PRICE_VISIBILITY_CUSTOMERS = 'customers';

    private const PRICE_VISIBILITY_NONE = 'none';

    public function __construct(
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {}

    #[Route(
        path: '/aica/prices',
        name: 'frontend.aica.prices',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']],
    )]
    public function prices(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->isTrustedAjaxRequest($request)) {
            return new JsonResponse(['error' => 'UNTRUSTED_REQUEST'], 400);
        }

        $priceVisibility = $this->getPriceVisibility($context->getSalesChannelId());
        if ($priceVisibility === self::PRICE_VISIBILITY_NONE) {
            return new JsonResponse(['error' => 'PRICES_DISABLED'], 403);
        }

        if ($priceVisibility === self::PRICE_VISIBILITY_CUSTOMERS && $context->getCustomer() === null) {
            return new JsonResponse(['error' => 'NOT_LOGGED_IN'], 401);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $ids = $payload['productIds'] ?? null;

        if (!\is_array($ids) || $ids === []) {
            return new JsonResponse(['error' => 'INVALID_PRODUCT_IDS'], 400);
        }

        if (\count($ids) > self::MAX_PRODUCT_IDS) {
            return new JsonResponse([
                'error' => 'TOO_MANY_PRODUCT_IDS',
                'max' => self::MAX_PRODUCT_IDS,
            ], 400);
        }

        $normalizedIds = [];
        foreach ($ids as $id) {
            if (!\is_string($id)) {
                continue;
            }

            $trimmedId = trim($id);
            if ($trimmedId === '' || !Uuid::isValid($trimmedId)) {
                continue;
            }

            $normalizedIds[$trimmedId] = true;
        }

        $ids = array_keys($normalizedIds);
        if ($ids === []) {
            return new JsonResponse(['error' => 'INVALID_PRODUCT_IDS'], 400);
        }

        $criteria = new Criteria($ids);

        // Helpful when debugging rule prices / advanced prices:
        $criteria->addAssociation('prices'); // rule-based advanced prices (if present)
        $criteria->addAssociation('tax');

        try {
            $result = $this->salesChannelProductRepository->search($criteria, $context);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'PRICE_LOOKUP_FAILED'], 500);
        }

        $currencyIsoCode = $this->resolveCurrencyIsoCode($context);
        $out = [];
        foreach ($result->getEntities() as $product) {
            $cp = $product->getCalculatedPrice();

            $tiers = [];
            $calculatedPrices = $product->getCalculatedPrices();
            if ($calculatedPrices) {
                foreach ($calculatedPrices as $tierPrice) {
                    $tiers[] = [
                        'unitPrice' => $tierPrice->getUnitPrice(),
                        'totalPrice' => $tierPrice->getTotalPrice(),
                        'quantity' => $tierPrice->getQuantity(),
                        'listPrice' => $tierPrice->getListPrice()?->getPrice(),
                    ];
                }
            }

            $out[$product->getId()] = [
                'name' => $product->getTranslation('name'),
                'calculatedPrice' => [
                    'unitPrice' => $cp?->getUnitPrice(),
                    'totalPrice' => $cp?->getTotalPrice(),
                    'listPrice' => $cp?->getListPrice()?->getPrice(),
                    'regulationPrice' => $cp?->getRegulationPrice()?->getPrice(),
                ],
                'calculatedPrices' => $tiers,     // tiers/advanced
                'hasAdvancedPrices' => $product->getPrices() !== null && $product->getPrices()->count() > 0,
                'currency' => $currencyIsoCode,
            ];
        }

        return new JsonResponse([
            'prices' => $out,
        ]);
    }

    private function isTrustedAjaxRequest(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            && $request->headers->get('X-AICA') === '1';
    }

    private function getPriceVisibility(?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . self::PRODUCT_PRICE_VISIBILITY, $salesChannelId);

        if (!\is_string($value)) {
            return self::PRICE_VISIBILITY_CUSTOMERS;
        }

        $value = trim($value);

        return \in_array($value, [
            self::PRICE_VISIBILITY_ALL,
            self::PRICE_VISIBILITY_CUSTOMERS,
            self::PRICE_VISIBILITY_NONE,
        ], true) ? $value : self::PRICE_VISIBILITY_CUSTOMERS;
    }

    private function resolveCurrencyIsoCode(SalesChannelContext $context): string
    {
        try {
            $currencyIsoCode = trim($context->getCurrency()->getIsoCode());

            return $currencyIsoCode !== '' ? $currencyIsoCode : 'EUR';
        } catch (\Throwable) {
            return 'EUR';
        }
    }
}
