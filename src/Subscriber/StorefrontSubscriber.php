<?php declare(strict_types=1);

/**
 * Subscriber to inject AICA - AI Commerce Assistant configuration into storefront pages.
 *
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
*/

namespace AiCommerceAssistant\Subscriber;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Class to handle storefront events for AICA - AI Commerce Assistant.
 *
 * This class listens to the PageLoadedEvent and injects the necessary configuration
 * 
 * @author Paul Nöth
 * @package AiCommerceAssistant\Subscriber
 */
class StorefrontSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PREFIX = 'AiCommerceAssistant.config.';

    private const PRODUCT_PRICE_VISIBILITY = 'productPriceVisibility';

    private const PRODUCT_PRICE_MODE = 'productPriceMode';

    private const SHOW_PRODUCT_PRICE_HINT = 'showProductPriceHint';

    private const PRICE_VISIBILITY_ALL = 'all';

    private const PRICE_VISIBILITY_CUSTOMERS = 'customers';

    private const PRICE_VISIBILITY_NONE = 'none';

    private const PRICE_MODE_CALCULATED = 'calculated';

    private const PRICE_MODE_ADVANCED_FIRST = 'advanced-first';

    /**
     *  @var SystemConfigService 
     */
    private SystemConfigService $config;

    /**
     * Constructer that initializes the StorefrontSubscriber with the system configuration service.
     * 
     * @param SystemConfigService $config The system configuration service to retrieve plugin settings.
     */
    public function __construct(SystemConfigService $config)
    {
        $this->config = $config;
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     * 
     * @return array The array of subscribed events.
     */
    public static function getSubscribedEvents(): array
    {
        return [PageLoadedEvent::class => 'onPageLoaded'];
    }

    /**
     * Event handler for the PageLoadedEvent.
     * 
     * This method retrieves the AICA - AI Commerce Assistant configuration settings and adds them
     * as an extension to the loaded page.
     * 
     * @param PageLoadedEvent $event The event object containing page and context information.
     */
    public function onPageLoaded(PageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        $chatUrl         = '/aica/chat';
        $llmModel        = (string) ($this->config->get('AiCommerceAssistant.config.llmModel', $salesChannelId) ?? 'llama3.1:8b');
        $welcomeMessage  = (string) ($this->config->get('AiCommerceAssistant.config.welcomeMessage', $salesChannelId) ?? 'Hallo, wie kann ich behilflich sein?');
        $autoOpen        = (bool)   ($this->config->get('AiCommerceAssistant.config.autoOpen', $salesChannelId) ?? false);
        $buttonLabel     = (string) ($this->config->get('AiCommerceAssistant.config.buttonLabel', $salesChannelId) ?? 'Chat');
        $position        = (string) ($this->config->get('AiCommerceAssistant.config.position', $salesChannelId) ?? 'bottom-right');
        $debugEnabled    = (bool)   ($this->config->get('AiCommerceAssistant.config.enableDebugMode', $salesChannelId) ?? false);
        $priceVisibility = $this->getProductPriceVisibility($salesChannelId);
        $priceMode = $this->getProductPriceMode($salesChannelId);
        $showPriceHint = $this->getBoolConfig(self::SHOW_PRODUCT_PRICE_HINT, $salesChannelId, true);
        $customer = $event->getSalesChannelContext()->getCustomer();
        $customerLoggedIn = $customer !== null && !$customer->getGuest();
        $canShowProductPrices = $priceVisibility === self::PRICE_VISIBILITY_ALL
            || ($priceVisibility === self::PRICE_VISIBILITY_CUSTOMERS && $customerLoggedIn);

        $event->getPage()->addExtension('aica', new ArrayEntity([
            'chatUrl'        => $chatUrl,
            'llmModel'       => $llmModel,
            'welcomeMessage' => $welcomeMessage,
            'autoOpen'       => $autoOpen,
            'buttonLabel'    => $buttonLabel,
            'position'       => $position,
            'debugEnabled'   => $debugEnabled,
            'productPriceVisibility' => $priceVisibility,
            'productPriceMode' => $priceMode,
            'showProductPriceHint' => $showPriceHint,
            'canShowProductPrices' => $canShowProductPrices,
        ]));
    }

    private function getProductPriceVisibility(?string $salesChannelId): string
    {
        $value = $this->config->get(self::CONFIG_PREFIX . self::PRODUCT_PRICE_VISIBILITY, $salesChannelId);

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

    private function getProductPriceMode(?string $salesChannelId): string
    {
        $value = $this->config->get(self::CONFIG_PREFIX . self::PRODUCT_PRICE_MODE, $salesChannelId);

        if (!\is_string($value)) {
            return self::PRICE_MODE_ADVANCED_FIRST;
        }

        $value = trim($value);

        return \in_array($value, [
            self::PRICE_MODE_CALCULATED,
            self::PRICE_MODE_ADVANCED_FIRST,
        ], true) ? $value : self::PRICE_MODE_ADVANCED_FIRST;
    }

    private function getBoolConfig(string $configName, ?string $salesChannelId, bool $default): bool
    {
        $value = $this->config->get(self::CONFIG_PREFIX . $configName, $salesChannelId);

        if ($value === null) {
            return $default;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'ja', 'on'], true);
        }

        return (bool) $value;
    }
}
