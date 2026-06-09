<?php declare(strict_types=1);

namespace AiCommerceAssistant\Setup;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class ContactMailTemplateSetup
{
    public const INTERNAL_TEMPLATE_TYPE_TECHNICAL_NAME = 'aica.contact_internal_notification';

    public const CONFIRMATION_TEMPLATE_TYPE_TECHNICAL_NAME = 'aica.contact_user_confirmation';

    public function __construct(
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $languageRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $languageIds = $this->resolveLanguageIds($context);

        $internalTypeId = $this->ensureTemplateType(
            self::INTERNAL_TEMPLATE_TYPE_TECHNICAL_NAME,
            'AICA internal contact notification',
            'AICA interne Kontaktbenachrichtigung',
            $languageIds,
            $context,
        );

        $this->ensureTemplate(
            $internalTypeId,
            $languageIds,
            [
                'en' => [
                    'subject' => '[AICA] New contact request from {{ contact.email }}',
                    'description' => 'Internal notification for AICA contact form submissions',
                    'html' => $this->getInternalHtmlContentEn(),
                    'plain' => $this->getInternalPlainContentEn(),
                ],
                'de' => [
                    'subject' => '[AICA] Neue Kontaktanfrage von {{ contact.email }}',
                    'description' => 'Interne Benachrichtigung für Kontaktanfragen aus AICA',
                    'html' => $this->getInternalHtmlContentDe(),
                    'plain' => $this->getInternalPlainContentDe(),
                ],
            ],
            $context,
        );

        $confirmationTypeId = $this->ensureTemplateType(
            self::CONFIRMATION_TEMPLATE_TYPE_TECHNICAL_NAME,
            'AICA contact confirmation',
            'AICA Kontaktbestätigung',
            $languageIds,
            $context,
        );

        $this->ensureTemplate(
            $confirmationTypeId,
            $languageIds,
            [
                'en' => [
                    'subject' => 'We received your inquiry',
                    'description' => 'Confirmation mail for AICA contact requests',
                    'html' => $this->getConfirmationHtmlContentEn(),
                    'plain' => $this->getConfirmationPlainContentEn(),
                ],
                'de' => [
                    'subject' => 'Wir haben Ihre Anfrage erhalten',
                    'description' => 'Bestätigungsmail für AICA Kontaktanfragen',
                    'html' => $this->getConfirmationHtmlContentDe(),
                    'plain' => $this->getConfirmationPlainContentDe(),
                ],
            ],
            $context,
        );
    }

    /**
     * @param array{system: string, en: string, de: string} $languageIds
     */
    private function ensureTemplateType(
        string $technicalName,
        string $englishName,
        string $germanName,
        array $languageIds,
        Context $context,
    ): string {
        $typeId = $this->getMailTemplateTypeId($technicalName, $context);
        if ($typeId !== null) {
            return $typeId;
        }

        $typeId = Uuid::randomHex();

        $this->mailTemplateTypeRepository->upsert([
            [
                'id' => $typeId,
                'technicalName' => $technicalName,
                'availableEntities' => [
                    'customer' => 'customer',
                    'salesChannel' => 'sales_channel',
                ],
                'translations' => [
                    $languageIds['system'] => ['name' => $englishName],
                    $languageIds['en'] => ['name' => $englishName],
                    $languageIds['de'] => ['name' => $germanName],
                ],
            ],
        ], $context);

        return $typeId;
    }

    /**
     * @param array{system: string, en: string, de: string} $languageIds
     * @param array{
     *     en: array{subject: string, description: string, html: string, plain: string},
     *     de: array{subject: string, description: string, html: string, plain: string}
     * } $content
     */
    private function ensureTemplate(string $mailTemplateTypeId, array $languageIds, array $content, Context $context): void
    {
        $existingTemplateId = $this->getSystemDefaultTemplateId($mailTemplateTypeId, $context)
            ?? $this->getFirstTemplateId($mailTemplateTypeId, $context);

        if ($existingTemplateId !== null) {
            return;
        }

        $this->mailTemplateRepository->upsert([
            [
                'id' => Uuid::randomHex(),
                'mailTemplateTypeId' => $mailTemplateTypeId,
                'systemDefault' => true,
                'translations' => [
                    $languageIds['system'] => [
                        'senderName' => '{{ shopName }}',
                        'subject' => $content['en']['subject'],
                        'description' => $content['en']['description'],
                        'contentHtml' => $content['en']['html'],
                        'contentPlain' => $content['en']['plain'],
                    ],
                    $languageIds['en'] => [
                        'senderName' => '{{ shopName }}',
                        'subject' => $content['en']['subject'],
                        'description' => $content['en']['description'],
                        'contentHtml' => $content['en']['html'],
                        'contentPlain' => $content['en']['plain'],
                    ],
                    $languageIds['de'] => [
                        'senderName' => '{{ shopName }}',
                        'subject' => $content['de']['subject'],
                        'description' => $content['de']['description'],
                        'contentHtml' => $content['de']['html'],
                        'contentPlain' => $content['de']['plain'],
                    ],
                ],
            ],
        ], $context);
    }

    /**
     * @return array{system: string, en: string, de: string}
     */
    private function resolveLanguageIds(Context $context): array
    {
        $systemLanguageId = Defaults::LANGUAGE_SYSTEM;
        $englishLanguageId = $this->getLanguageIdByLocaleCode('en-GB', $context) ?? $systemLanguageId;
        $germanLanguageId = $this->getLanguageIdByLocaleCode('de-DE', $context) ?? $systemLanguageId;

        return [
            'system' => $systemLanguageId,
            'en' => $englishLanguageId,
            'de' => $germanLanguageId,
        ];
    }

    private function getLanguageIdByLocaleCode(string $localeCode, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addAssociation('locale')
            ->addFilter(new EqualsFilter('locale.code', $localeCode))
            ->setLimit(1);

        $languageId = $this->languageRepository->searchIds($criteria, $context)->firstId();

        return \is_string($languageId) && $languageId !== '' ? $languageId : null;
    }

    private function getMailTemplateTypeId(string $technicalName, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', $technicalName))
            ->setLimit(1);

        $templateTypeId = $this->mailTemplateTypeRepository->searchIds($criteria, $context)->firstId();

        return \is_string($templateTypeId) && $templateTypeId !== '' ? $templateTypeId : null;
    }

    private function getSystemDefaultTemplateId(string $mailTemplateTypeId, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('mailTemplateTypeId', $mailTemplateTypeId))
            ->addFilter(new EqualsFilter('systemDefault', true))
            ->setLimit(1);

        $templateId = $this->mailTemplateRepository->searchIds($criteria, $context)->firstId();

        return \is_string($templateId) && $templateId !== '' ? $templateId : null;
    }

    private function getFirstTemplateId(string $mailTemplateTypeId, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('mailTemplateTypeId', $mailTemplateTypeId))
            ->setLimit(1);

        $templateId = $this->mailTemplateRepository->searchIds($criteria, $context)->firstId();

        return \is_string($templateId) && $templateId !== '' ? $templateId : null;
    }

    private function getInternalPlainContentEn(): string
    {
        return <<<'TWIG'
A new contact request was submitted via the storefront AICA.

Time: {{ submission.createdAt }}
Sales channel: {{ submission.salesChannelName }}
Session ID: {{ submission.sessionId|default('n/a') }}
Customer ID: {{ submission.customerId|default('n/a') }}

Name: {{ contact.name|default('n/a') }}
Email: {{ contact.email }}
Phone: {{ contact.phone|default('n/a') }}
Company: {{ contact.company|default('n/a') }}
Product reference: {{ contact.productRef|default('n/a') }}
Reason: {{ contact.sourceContext|default('n/a') }}
Quantity: {{ contact.quantity|default('n/a') }}
Delivery ZIP: {{ contact.deliveryZip|default('n/a') }}

Message:
{{ contact.message }}
TWIG;
    }

    private function getInternalHtmlContentEn(): string
    {
        return <<<'TWIG'
<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.45;">
    <p>A new contact request was submitted via the storefront AICA.</p>
    <p>
        <strong>Time:</strong> {{ submission.createdAt }}<br>
        <strong>Sales channel:</strong> {{ submission.salesChannelName }}<br>
        <strong>Session ID:</strong> {{ submission.sessionId|default('n/a') }}<br>
        <strong>Customer ID:</strong> {{ submission.customerId|default('n/a') }}
    </p>
    <p>
        <strong>Name:</strong> {{ contact.name|default('n/a') }}<br>
        <strong>Email:</strong> {{ contact.email }}<br>
        <strong>Phone:</strong> {{ contact.phone|default('n/a') }}<br>
        <strong>Company:</strong> {{ contact.company|default('n/a') }}<br>
        <strong>Product reference:</strong> {{ contact.productRef|default('n/a') }}<br>
        <strong>Reason:</strong> {{ contact.sourceContext|default('n/a') }}<br>
        <strong>Quantity:</strong> {{ contact.quantity|default('n/a') }}<br>
        <strong>Delivery ZIP:</strong> {{ contact.deliveryZip|default('n/a') }}
    </p>
    <p><strong>Message:</strong><br>{{ contact.message|nl2br }}</p>
</div>
TWIG;
    }

    private function getInternalPlainContentDe(): string
    {
        return <<<'TWIG'
Es wurde eine neue Kontaktanfrage über den Storefront AICA gesendet.

Zeitpunkt: {{ submission.createdAt }}
Verkaufskanal: {{ submission.salesChannelName }}
Sitzungs-ID: {{ submission.sessionId|default('k. A.') }}
Kunden-ID: {{ submission.customerId|default('k. A.') }}

Name: {{ contact.name|default('k. A.') }}
E-Mail: {{ contact.email }}
Telefon: {{ contact.phone|default('k. A.') }}
Firma: {{ contact.company|default('k. A.') }}
Produktbezug: {{ contact.productRef|default('k. A.') }}
Kontext/Grund: {{ contact.sourceContext|default('k. A.') }}
Menge: {{ contact.quantity|default('k. A.') }}
PLZ Lieferort: {{ contact.deliveryZip|default('k. A.') }}

Nachricht:
{{ contact.message }}
TWIG;
    }

    private function getInternalHtmlContentDe(): string
    {
        return <<<'TWIG'
<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.45;">
    <p>Es wurde eine neue Kontaktanfrage über den Storefront AICA gesendet.</p>
    <p>
        <strong>Zeitpunkt:</strong> {{ submission.createdAt }}<br>
        <strong>Verkaufskanal:</strong> {{ submission.salesChannelName }}<br>
        <strong>Sitzungs-ID:</strong> {{ submission.sessionId|default('k. A.') }}<br>
        <strong>Kunden-ID:</strong> {{ submission.customerId|default('k. A.') }}
    </p>
    <p>
        <strong>Name:</strong> {{ contact.name|default('k. A.') }}<br>
        <strong>E-Mail:</strong> {{ contact.email }}<br>
        <strong>Telefon:</strong> {{ contact.phone|default('k. A.') }}<br>
        <strong>Firma:</strong> {{ contact.company|default('k. A.') }}<br>
        <strong>Produktbezug:</strong> {{ contact.productRef|default('k. A.') }}<br>
        <strong>Kontext/Grund:</strong> {{ contact.sourceContext|default('k. A.') }}<br>
        <strong>Menge:</strong> {{ contact.quantity|default('k. A.') }}<br>
        <strong>PLZ Lieferort:</strong> {{ contact.deliveryZip|default('k. A.') }}
    </p>
    <p><strong>Nachricht:</strong><br>{{ contact.message|nl2br }}</p>
</div>
TWIG;
    }

    private function getConfirmationPlainContentEn(): string
    {
        return <<<'TWIG'
Hello {{ contact.name|default('there') }},

thank you for your inquiry. We have received your message and will get back to you as soon as possible.

Summary:
- Product reference: {{ contact.productRef|default('n/a') }}
- Reason: {{ contact.sourceContext|default('n/a') }}

Your message:
{{ contact.message }}

Best regards,
{{ shopName }}
TWIG;
    }

    private function getConfirmationHtmlContentEn(): string
    {
        return <<<'TWIG'
<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.45;">
    <p>Hello {{ contact.name|default('there') }},</p>
    <p>thank you for your inquiry. We have received your message and will get back to you as soon as possible.</p>
    <p>
        <strong>Summary:</strong><br>
        Product reference: {{ contact.productRef|default('n/a') }}<br>
        Reason: {{ contact.sourceContext|default('n/a') }}
    </p>
    <p><strong>Your message:</strong><br>{{ contact.message|nl2br }}</p>
    <p>Best regards,<br>{{ shopName }}</p>
</div>
TWIG;
    }

    private function getConfirmationPlainContentDe(): string
    {
        return <<<'TWIG'
Hallo {{ contact.name|default('') }},

vielen Dank für Ihre Anfrage. Wir haben Ihre Nachricht erhalten und melden uns schnellstmöglich bei Ihnen.

Zusammenfassung:
- Produktbezug: {{ contact.productRef|default('k. A.') }}
- Kontext/Grund: {{ contact.sourceContext|default('k. A.') }}

Ihre Nachricht:
{{ contact.message }}

Viele Grüße
{{ shopName }}
TWIG;
    }

    private function getConfirmationHtmlContentDe(): string
    {
        return <<<'TWIG'
<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.45;">
    <p>Hallo {{ contact.name|default('') }},</p>
    <p>vielen Dank für Ihre Anfrage. Wir haben Ihre Nachricht erhalten und melden uns schnellstmöglich bei Ihnen.</p>
    <p>
        <strong>Zusammenfassung:</strong><br>
        Produktbezug: {{ contact.productRef|default('k. A.') }}<br>
        Kontext/Grund: {{ contact.sourceContext|default('k. A.') }}
    </p>
    <p><strong>Ihre Nachricht:</strong><br>{{ contact.message|nl2br }}</p>
    <p>Viele Grüße<br>{{ shopName }}</p>
</div>
TWIG;
    }
}
