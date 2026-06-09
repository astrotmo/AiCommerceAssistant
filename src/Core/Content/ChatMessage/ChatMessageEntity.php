<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatMessage;

use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionEntity;
use AiCommerceAssistant\Core\Content\ProductReference\ProductReferenceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ChatMessageEntity extends Entity
{
    use EntityIdTrait;

    protected string $sessionId;

    protected string $role;

    protected int $messageIndex;

    protected ?string $rawText = null;

    /** @var array<string, mixed>|null */
    protected ?array $renderedPayload = null;

    protected ?string $messageType = null;

    /** @var list<string>|null */
    protected ?array $blockKinds = null;

    protected ?int $tokenInput = null;

    protected ?int $tokenOutput = null;

    protected ?int $tokenTotal = null;

    protected ?int $latencyMs = null;

    protected ?string $modelName = null;

    protected ?string $requestId = null;

    protected bool $containsProductSuggestions = false;

    protected bool $containsInfoBox = false;

    protected bool $containsFormular = false;

    protected bool $containsToolPayload = false;

    protected bool $isError = false;

    /** @var array<string, mixed>|null */
    protected ?array $debugPayload = null;

    protected ?ChatSessionEntity $session = null;

    protected ?ProductReferenceCollection $productReferences = null;

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getMessageIndex(): int
    {
        return $this->messageIndex;
    }

    public function setMessageIndex(int $messageIndex): void
    {
        $this->messageIndex = $messageIndex;
    }

    public function getRawText(): ?string
    {
        return $this->rawText;
    }

    public function setRawText(?string $rawText): void
    {
        $this->rawText = $rawText;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRenderedPayload(): ?array
    {
        return $this->renderedPayload;
    }

    /**
     * @param array<string, mixed>|null $renderedPayload
     */
    public function setRenderedPayload(?array $renderedPayload): void
    {
        $this->renderedPayload = $renderedPayload;
    }

    public function getMessageType(): ?string
    {
        return $this->messageType;
    }

    public function setMessageType(?string $messageType): void
    {
        $this->messageType = $messageType;
    }

    /**
     * @return list<string>|null
     */
    public function getBlockKinds(): ?array
    {
        return $this->blockKinds;
    }

    /**
     * @param list<string>|null $blockKinds
     */
    public function setBlockKinds(?array $blockKinds): void
    {
        $this->blockKinds = $blockKinds;
    }

    public function getTokenInput(): ?int
    {
        return $this->tokenInput;
    }

    public function setTokenInput(?int $tokenInput): void
    {
        $this->tokenInput = $tokenInput;
    }

    public function getTokenOutput(): ?int
    {
        return $this->tokenOutput;
    }

    public function setTokenOutput(?int $tokenOutput): void
    {
        $this->tokenOutput = $tokenOutput;
    }

    public function getTokenTotal(): ?int
    {
        return $this->tokenTotal;
    }

    public function setTokenTotal(?int $tokenTotal): void
    {
        $this->tokenTotal = $tokenTotal;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): void
    {
        $this->latencyMs = $latencyMs;
    }

    public function getModelName(): ?string
    {
        return $this->modelName;
    }

    public function setModelName(?string $modelName): void
    {
        $this->modelName = $modelName;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function isContainsProductSuggestions(): bool
    {
        return $this->containsProductSuggestions;
    }

    public function setContainsProductSuggestions(bool $containsProductSuggestions): void
    {
        $this->containsProductSuggestions = $containsProductSuggestions;
    }

    public function isContainsInfoBox(): bool
    {
        return $this->containsInfoBox;
    }

    public function setContainsInfoBox(bool $containsInfoBox): void
    {
        $this->containsInfoBox = $containsInfoBox;
    }

    public function isContainsFormular(): bool
    {
        return $this->containsFormular;
    }

    public function setContainsFormular(bool $containsFormular): void
    {
        $this->containsFormular = $containsFormular;
    }

    public function isContainsToolPayload(): bool
    {
        return $this->containsToolPayload;
    }

    public function setContainsToolPayload(bool $containsToolPayload): void
    {
        $this->containsToolPayload = $containsToolPayload;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function setIsError(bool $isError): void
    {
        $this->isError = $isError;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDebugPayload(): ?array
    {
        return $this->debugPayload;
    }

    /**
     * @param array<string, mixed>|null $debugPayload
     */
    public function setDebugPayload(?array $debugPayload): void
    {
        $this->debugPayload = $debugPayload;
    }

    public function getSession(): ?ChatSessionEntity
    {
        return $this->session;
    }

    public function setSession(?ChatSessionEntity $session): void
    {
        $this->session = $session;
    }

    public function getProductReferences(): ?ProductReferenceCollection
    {
        return $this->productReferences;
    }

    public function setProductReferences(?ProductReferenceCollection $productReferences): void
    {
        $this->productReferences = $productReferences;
    }
}
