<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ProductReference;

use AiCommerceAssistant\Core\Content\ChatMessage\ChatMessageEntity;
use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ProductReferenceEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $sessionId = null;

    protected ?string $messageId = null;

    protected string $source;

    protected ?string $productId = null;

    protected ?string $productNumber = null;

    protected ?string $productName = null;

    protected ?string $referenceText = null;

    protected ?ChatSessionEntity $session = null;

    protected ?ChatMessageEntity $message = null;

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductNumber(): ?string
    {
        return $this->productNumber;
    }

    public function setProductNumber(?string $productNumber): void
    {
        $this->productNumber = $productNumber;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): void
    {
        $this->productName = $productName;
    }

    public function getReferenceText(): ?string
    {
        return $this->referenceText;
    }

    public function setReferenceText(?string $referenceText): void
    {
        $this->referenceText = $referenceText;
    }

    public function getSession(): ?ChatSessionEntity
    {
        return $this->session;
    }

    public function setSession(?ChatSessionEntity $session): void
    {
        $this->session = $session;
    }

    public function getMessage(): ?ChatMessageEntity
    {
        return $this->message;
    }

    public function setMessage(?ChatMessageEntity $message): void
    {
        $this->message = $message;
    }
}
