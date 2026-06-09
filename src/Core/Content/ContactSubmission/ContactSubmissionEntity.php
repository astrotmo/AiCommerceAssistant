<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ContactSubmission;

use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ContactSubmissionEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $sessionId = null;

    protected ?string $customerId = null;

    protected string $email;

    protected ?string $name = null;

    protected ?string $phone = null;

    protected ?string $company = null;

    protected string $messageText;

    protected ?string $productReferenceText = null;

    protected ?string $sourceContext = null;

    protected string $mailTargetAddress;

    protected bool $confirmationMailAttempted = false;

    protected bool $confirmationMailSent = false;

    protected ?string $confirmationMailError = null;

    protected bool $internalNotificationMailAttempted = false;

    protected bool $internalNotificationMailSent = false;

    protected ?string $internalNotificationMailError = null;

    protected ?ChatSessionEntity $session = null;

    protected ?CustomerEntity $customer = null;

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function setMessageText(string $messageText): void
    {
        $this->messageText = $messageText;
    }

    public function getProductReferenceText(): ?string
    {
        return $this->productReferenceText;
    }

    public function setProductReferenceText(?string $productReferenceText): void
    {
        $this->productReferenceText = $productReferenceText;
    }

    public function getSourceContext(): ?string
    {
        return $this->sourceContext;
    }

    public function setSourceContext(?string $sourceContext): void
    {
        $this->sourceContext = $sourceContext;
    }

    public function getMailTargetAddress(): string
    {
        return $this->mailTargetAddress;
    }

    public function setMailTargetAddress(string $mailTargetAddress): void
    {
        $this->mailTargetAddress = $mailTargetAddress;
    }

    public function isConfirmationMailSent(): bool
    {
        return $this->confirmationMailSent;
    }

    public function isConfirmationMailAttempted(): bool
    {
        return $this->confirmationMailAttempted;
    }

    public function setConfirmationMailAttempted(bool $confirmationMailAttempted): void
    {
        $this->confirmationMailAttempted = $confirmationMailAttempted;
    }

    public function setConfirmationMailSent(bool $confirmationMailSent): void
    {
        $this->confirmationMailSent = $confirmationMailSent;
    }

    public function getConfirmationMailError(): ?string
    {
        return $this->confirmationMailError;
    }

    public function setConfirmationMailError(?string $confirmationMailError): void
    {
        $this->confirmationMailError = $confirmationMailError;
    }

    public function isInternalNotificationMailSent(): bool
    {
        return $this->internalNotificationMailSent;
    }

    public function isInternalNotificationMailAttempted(): bool
    {
        return $this->internalNotificationMailAttempted;
    }

    public function setInternalNotificationMailAttempted(bool $internalNotificationMailAttempted): void
    {
        $this->internalNotificationMailAttempted = $internalNotificationMailAttempted;
    }

    public function setInternalNotificationMailSent(bool $internalNotificationMailSent): void
    {
        $this->internalNotificationMailSent = $internalNotificationMailSent;
    }

    public function getInternalNotificationMailError(): ?string
    {
        return $this->internalNotificationMailError;
    }

    public function setInternalNotificationMailError(?string $internalNotificationMailError): void
    {
        $this->internalNotificationMailError = $internalNotificationMailError;
    }

    public function getSession(): ?ChatSessionEntity
    {
        return $this->session;
    }

    public function setSession(?ChatSessionEntity $session): void
    {
        $this->session = $session;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }
}
