<?php declare(strict_types=1);

namespace AiCommerceAssistant\Service;

use AiCommerceAssistant\Setup\ContactMailTemplateSetup;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Mime\Email;

class ContactSubmissionMailService
{
    public const CONFIG_CONTACT_RECIPIENT_EMAIL = 'AiCommerceAssistant.config.contactRecipientEmail';

    public const CONFIG_CONFIRMATION_MAIL_ENABLED = 'AiCommerceAssistant.config.enableContactConfirmationMail';

    public const CONFIG_CONTACT_MAIL_SENDER_NAME = 'AiCommerceAssistant.config.contactMailSenderName';

    public const CONFIG_DEBUG_LOG_CONTACT_SUBMISSIONS = 'AiCommerceAssistant.config.debugLogContactSubmissions';

    public function __construct(
        private readonly MailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $contactData
     *
     * @return array{attempted: bool, sent: bool, error: ?string, recipientEmail: ?string}
     */
    public function sendInternalNotification(
        array $contactData,
        SalesChannelContext $salesChannelContext,
        ?string $sessionId,
        ?string $customerId,
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $recipientEmail = $this->resolveInternalRecipientEmail($salesChannelId);

        if ($recipientEmail === null) {
            return [
                'attempted' => false,
                'sent' => false,
                'error' => 'NO_RECIPIENT_CONFIGURED',
                'recipientEmail' => null,
            ];
        }

        $shopName = $this->resolveShopName($salesChannelId);
        $templateData = $this->buildTemplateData(
            $contactData,
            $salesChannelContext,
            $sessionId,
            $customerId,
            $shopName,
        );

        $result = $this->sendTemplateMailByType(
            ContactMailTemplateSetup::INTERNAL_TEMPLATE_TYPE_TECHNICAL_NAME,
            [$recipientEmail => $shopName],
            $salesChannelContext,
            $templateData,
        );

        if ($this->isDebugLoggingEnabled($salesChannelId)) {
            $this->logger->info('AICA - AI Commerce Assistant internal contact notification mail status.', [
                'sessionId' => $sessionId,
                'customerId' => $customerId,
                'recipientEmail' => $recipientEmail,
                'attempted' => $result['attempted'],
                'sent' => $result['sent'],
                'error' => $result['error'],
            ]);
        }

        $result['recipientEmail'] = $recipientEmail;

        return $result;
    }

    /**
     * @param array<string, string> $contactData
     *
     * @return array{attempted: bool, sent: bool, error: ?string}
     */
    public function sendCustomerConfirmation(
        array $contactData,
        SalesChannelContext $salesChannelContext,
        ?string $sessionId,
        ?string $customerId,
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        if (!$this->isConfirmationMailEnabled($salesChannelId)) {
            return [
                'attempted' => false,
                'sent' => false,
                'error' => null,
            ];
        }

        $recipientEmail = trim((string) ($contactData['email'] ?? ''));
        if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'attempted' => false,
                'sent' => false,
                'error' => 'INVALID_RECIPIENT_EMAIL',
            ];
        }

        $recipientName = trim((string) ($contactData['name'] ?? ''));
        if ($recipientName === '') {
            $recipientName = $recipientEmail;
        }

        $shopName = $this->resolveShopName($salesChannelId);
        $templateData = $this->buildTemplateData(
            $contactData,
            $salesChannelContext,
            $sessionId,
            $customerId,
            $shopName,
        );

        $result = $this->sendTemplateMailByType(
            ContactMailTemplateSetup::CONFIRMATION_TEMPLATE_TYPE_TECHNICAL_NAME,
            [$recipientEmail => $recipientName],
            $salesChannelContext,
            $templateData,
        );

        if ($this->isDebugLoggingEnabled($salesChannelId)) {
            $this->logger->info('AICA - AI Commerce Assistant confirmation mail status.', [
                'sessionId' => $sessionId,
                'customerId' => $customerId,
                'recipientEmail' => $recipientEmail,
                'attempted' => $result['attempted'],
                'sent' => $result['sent'],
                'error' => $result['error'],
            ]);
        }

        return $result;
    }

    public function resolveInternalRecipientEmail(?string $salesChannelId): ?string
    {
        $configured = trim((string) ($this->systemConfigService->get(self::CONFIG_CONTACT_RECIPIENT_EMAIL, $salesChannelId) ?? ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        $coreMail = trim((string) ($this->systemConfigService->get('core.basicInformation.email', $salesChannelId) ?? ''));
        if ($coreMail !== '' && filter_var($coreMail, FILTER_VALIDATE_EMAIL) !== false) {
            return $coreMail;
        }

        $senderMail = trim((string) ($this->systemConfigService->get('core.mailerSettings.senderAddress', $salesChannelId) ?? ''));
        if ($senderMail !== '' && filter_var($senderMail, FILTER_VALIDATE_EMAIL) !== false) {
            return $senderMail;
        }

        return null;
    }

    private function isConfirmationMailEnabled(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfigService->get(self::CONFIG_CONFIRMATION_MAIL_ENABLED, $salesChannelId) ?? true);
    }

    private function isDebugLoggingEnabled(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfigService->get(self::CONFIG_DEBUG_LOG_CONTACT_SUBMISSIONS, $salesChannelId) ?? false);
    }

    private function resolveShopName(?string $salesChannelId): string
    {
        $shopName = trim((string) ($this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId) ?? ''));

        return $shopName !== '' ? $shopName : 'Shop';
    }

    private function resolveSenderName(?string $salesChannelId, ?string $templateSenderName): string
    {
        $configuredSenderName = trim((string) ($this->systemConfigService->get(self::CONFIG_CONTACT_MAIL_SENDER_NAME, $salesChannelId) ?? ''));
        if ($configuredSenderName !== '') {
            return $configuredSenderName;
        }

        $templateSenderName = trim((string) $templateSenderName);
        if ($templateSenderName !== '') {
            return $templateSenderName;
        }

        return $this->resolveShopName($salesChannelId);
    }

    /**
     * @param array<string, string> $contactData
     *
     * @return array<string, mixed>
     */
    private function buildTemplateData(
        array $contactData,
        SalesChannelContext $salesChannelContext,
        ?string $sessionId,
        ?string $customerId,
        string $shopName,
    ): array {
        $salesChannelName = $salesChannelContext->getSalesChannel()?->getName() ?? $shopName;

        return [
            'shopName' => $shopName,
            'contact' => [
                'name' => $contactData['name'] ?? '',
                'email' => $contactData['email'] ?? '',
                'phone' => $contactData['phone'] ?? '',
                'company' => $contactData['company'] ?? '',
                'message' => $contactData['message'] ?? '',
                'productRef' => $contactData['productRef'] ?? '',
                'sourceContext' => $contactData['sourceContext'] ?? '',
                'quantity' => $contactData['quantity'] ?? '',
                'deliveryZip' => $contactData['deliveryZip'] ?? '',
            ],
            'submission' => [
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                'salesChannelName' => $salesChannelName,
                'sessionId' => $sessionId,
                'customerId' => $customerId,
            ],
            'salesChannel' => [
                'id' => $salesChannelContext->getSalesChannelId(),
                'name' => $salesChannelName,
            ],
        ];
    }

    /**
     * @param array<string, string> $recipients
     * @param array<string, mixed> $templateData
     *
     * @return array{attempted: bool, sent: bool, error: ?string}
     */
    private function sendTemplateMailByType(
        string $templateTypeTechnicalName,
        array $recipients,
        SalesChannelContext $salesChannelContext,
        array $templateData,
    ): array {
        $template = $this->resolveTemplateByTypeTechnicalName($templateTypeTechnicalName, $salesChannelContext);
        if ($template === null) {
            return [
                'attempted' => true,
                'sent' => false,
                'error' => 'MAIL_TEMPLATE_NOT_FOUND',
            ];
        }

        $subject = trim((string) $template->getTranslation('subject'));
        $contentPlain = trim((string) $template->getTranslation('contentPlain'));
        $contentHtml = trim((string) $template->getTranslation('contentHtml'));

        if ($subject === '' || $contentPlain === '' || $contentHtml === '') {
            return [
                'attempted' => true,
                'sent' => false,
                'error' => 'MAIL_TEMPLATE_INCOMPLETE',
            ];
        }

        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $mailData = [
            'recipients' => $recipients,
            'senderName' => $this->resolveSenderName($salesChannelId, (string) $template->getTranslation('senderName')),
            'subject' => $subject,
            'contentPlain' => $contentPlain,
            'contentHtml' => $contentHtml,
            'salesChannelId' => $salesChannelId,
        ];

        try {
            $result = $this->mailService->send($mailData, $salesChannelContext->getContext(), $templateData);
            if ($result instanceof Email) {
                return [
                    'attempted' => true,
                    'sent' => true,
                    'error' => null,
                ];
            }

            return [
                'attempted' => true,
                'sent' => false,
                'error' => 'MAIL_SEND_RETURNED_NULL',
            ];
        } catch (ConstraintViolationException $exception) {
            return [
                'attempted' => true,
                'sent' => false,
                'error' => $this->buildConstraintViolationMessage($exception),
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('AICA - AI Commerce Assistant could not send contact mail.', [
                'templateType' => $templateTypeTechnicalName,
                'errorMessage' => $exception->getMessage(),
            ]);

            return [
                'attempted' => true,
                'sent' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function resolveTemplateByTypeTechnicalName(
        string $templateTypeTechnicalName,
        SalesChannelContext $salesChannelContext,
    ): ?MailTemplateEntity {
        $context = $salesChannelContext->getContext();

        $typeCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', $templateTypeTechnicalName))
            ->setLimit(1);

        $templateTypeId = $this->mailTemplateTypeRepository->searchIds($typeCriteria, $context)->firstId();
        if (!\is_string($templateTypeId) || $templateTypeId === '') {
            return null;
        }

        $templateCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('mailTemplateTypeId', $templateTypeId))
            ->addSorting(new FieldSorting('systemDefault', FieldSorting::DESCENDING))
            ->setLimit(1);

        /** @var MailTemplateEntity|null $template */
        $template = $this->mailTemplateRepository->search($templateCriteria, $context)->first();

        return $template;
    }

    private function buildConstraintViolationMessage(ConstraintViolationException $exception): string
    {
        $messages = [];
        foreach ($exception->getViolations() as $violation) {
            $messages[] = trim(sprintf('%s %s', (string) $violation->getPropertyPath(), (string) $violation->getMessage()));
        }

        $messages = array_filter($messages, static fn (string $message): bool => $message !== '');

        if ($messages === []) {
            return 'MAIL_VALIDATION_FAILED';
        }

        return implode(' | ', $messages);
    }
}
