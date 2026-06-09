<?php declare(strict_types=1);
/**
 * AICA - AI Commerce Assistant Plugin for Shopware
 *
 * Registers a chat interface powered by AI to assist users within the Shopware platform.
 * 
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
*/

namespace AiCommerceAssistant;

use AiCommerceAssistant\Setup\ContactMailTemplateSetup;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class AiCommerceAssistant extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->getContactMailTemplateSetup()->install($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove or deactivate the data created by the plugin
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getContactMailTemplateSetup()->install($updateContext->getContext());
    }

    public function postInstall(InstallContext $installContext): void
    {
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    private function getContactMailTemplateSetup(): ContactMailTemplateSetup
    {
        if ($this->container->has(ContactMailTemplateSetup::class)) {
            return $this->container->get(ContactMailTemplateSetup::class);
        }

        return new ContactMailTemplateSetup(
            $this->container->get('mail_template_type.repository'),
            $this->container->get('mail_template.repository'),
            $this->container->get('language.repository'),
        );
    }
}
