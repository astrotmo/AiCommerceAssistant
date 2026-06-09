<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatSession;

use AiCommerceAssistant\Core\Content\ChatMessage\ChatMessageCollection;
use AiCommerceAssistant\Core\Content\ContactSubmission\ContactSubmissionCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ChatSessionEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $salesChannelId = null;

    protected ?string $languageId = null;

    protected ?string $customerId = null;

    protected string $storefrontSessionIdentifier;

    protected ?string $guestFingerprintHash = null;

    protected ?string $hashedIp = null;

    protected ?string $hashedUserAgent = null;

    protected ?string $rawIp = null;

    protected ?string $userAgentRaw = null;

    protected ?string $modelName = null;

    protected ?int $totalInputTokens = null;

    protected ?int $totalOutputTokens = null;

    protected ?int $totalTokens = null;

    protected int $totalMessageCount = 0;

    protected ?\DateTimeInterface $firstMessageAt = null;

    protected ?\DateTimeInterface $lastMessageAt = null;

    protected ?float $averageResponseLatencyMs = null;

    protected int $responseLatencySampleCount = 0;

    protected bool $contactFormShown = false;

    protected bool $contactFormSubmitted = false;

    protected bool $debugEnabledSnapshot = false;

    protected bool $storeRawDebugPayloadsSnapshot = false;

    protected ?SalesChannelEntity $salesChannel = null;

    protected ?LanguageEntity $language = null;

    protected ?CustomerEntity $customer = null;

    protected ?ChatMessageCollection $messages = null;

    protected ?ContactSubmissionCollection $contactSubmissions = null;

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(?string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getLanguageId(): ?string
    {
        return $this->languageId;
    }

    public function setLanguageId(?string $languageId): void
    {
        $this->languageId = $languageId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getStorefrontSessionIdentifier(): string
    {
        return $this->storefrontSessionIdentifier;
    }

    public function setStorefrontSessionIdentifier(string $storefrontSessionIdentifier): void
    {
        $this->storefrontSessionIdentifier = $storefrontSessionIdentifier;
    }

    public function getGuestFingerprintHash(): ?string
    {
        return $this->guestFingerprintHash;
    }

    public function setGuestFingerprintHash(?string $guestFingerprintHash): void
    {
        $this->guestFingerprintHash = $guestFingerprintHash;
    }

    public function getHashedIp(): ?string
    {
        return $this->hashedIp;
    }

    public function setHashedIp(?string $hashedIp): void
    {
        $this->hashedIp = $hashedIp;
    }

    public function getHashedUserAgent(): ?string
    {
        return $this->hashedUserAgent;
    }

    public function setHashedUserAgent(?string $hashedUserAgent): void
    {
        $this->hashedUserAgent = $hashedUserAgent;
    }

    public function getRawIp(): ?string
    {
        return $this->rawIp;
    }

    public function setRawIp(?string $rawIp): void
    {
        $this->rawIp = $rawIp;
    }

    public function getUserAgentRaw(): ?string
    {
        return $this->userAgentRaw;
    }

    public function setUserAgentRaw(?string $userAgentRaw): void
    {
        $this->userAgentRaw = $userAgentRaw;
    }

    public function getModelName(): ?string
    {
        return $this->modelName;
    }

    public function setModelName(?string $modelName): void
    {
        $this->modelName = $modelName;
    }

    public function getTotalInputTokens(): ?int
    {
        return $this->totalInputTokens;
    }

    public function setTotalInputTokens(?int $totalInputTokens): void
    {
        $this->totalInputTokens = $totalInputTokens;
    }

    public function getTotalOutputTokens(): ?int
    {
        return $this->totalOutputTokens;
    }

    public function setTotalOutputTokens(?int $totalOutputTokens): void
    {
        $this->totalOutputTokens = $totalOutputTokens;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): void
    {
        $this->totalTokens = $totalTokens;
    }

    public function getTotalMessageCount(): int
    {
        return $this->totalMessageCount;
    }

    public function setTotalMessageCount(int $totalMessageCount): void
    {
        $this->totalMessageCount = $totalMessageCount;
    }

    public function getFirstMessageAt(): ?\DateTimeInterface
    {
        return $this->firstMessageAt;
    }

    public function setFirstMessageAt(?\DateTimeInterface $firstMessageAt): void
    {
        $this->firstMessageAt = $firstMessageAt;
    }

    public function getLastMessageAt(): ?\DateTimeInterface
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeInterface $lastMessageAt): void
    {
        $this->lastMessageAt = $lastMessageAt;
    }

    public function getAverageResponseLatencyMs(): ?float
    {
        return $this->averageResponseLatencyMs;
    }

    public function setAverageResponseLatencyMs(?float $averageResponseLatencyMs): void
    {
        $this->averageResponseLatencyMs = $averageResponseLatencyMs;
    }

    public function getResponseLatencySampleCount(): int
    {
        return $this->responseLatencySampleCount;
    }

    public function setResponseLatencySampleCount(int $responseLatencySampleCount): void
    {
        $this->responseLatencySampleCount = $responseLatencySampleCount;
    }

    public function isContactFormShown(): bool
    {
        return $this->contactFormShown;
    }

    public function setContactFormShown(bool $contactFormShown): void
    {
        $this->contactFormShown = $contactFormShown;
    }

    public function isContactFormSubmitted(): bool
    {
        return $this->contactFormSubmitted;
    }

    public function setContactFormSubmitted(bool $contactFormSubmitted): void
    {
        $this->contactFormSubmitted = $contactFormSubmitted;
    }

    public function isDebugEnabledSnapshot(): bool
    {
        return $this->debugEnabledSnapshot;
    }

    public function setDebugEnabledSnapshot(bool $debugEnabledSnapshot): void
    {
        $this->debugEnabledSnapshot = $debugEnabledSnapshot;
    }

    public function isStoreRawDebugPayloadsSnapshot(): bool
    {
        return $this->storeRawDebugPayloadsSnapshot;
    }

    public function setStoreRawDebugPayloadsSnapshot(bool $storeRawDebugPayloadsSnapshot): void
    {
        $this->storeRawDebugPayloadsSnapshot = $storeRawDebugPayloadsSnapshot;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(?SalesChannelEntity $salesChannel): void
    {
        $this->salesChannel = $salesChannel;
    }

    public function getLanguage(): ?LanguageEntity
    {
        return $this->language;
    }

    public function setLanguage(?LanguageEntity $language): void
    {
        $this->language = $language;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getMessages(): ?ChatMessageCollection
    {
        return $this->messages;
    }

    public function setMessages(?ChatMessageCollection $messages): void
    {
        $this->messages = $messages;
    }

    public function getContactSubmissions(): ?ContactSubmissionCollection
    {
        return $this->contactSubmissions;
    }

    public function setContactSubmissions(?ContactSubmissionCollection $contactSubmissions): void
    {
        $this->contactSubmissions = $contactSubmissions;
    }
}
