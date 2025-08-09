<?php

declare(strict_types=1);

namespace EcLoginExt;

use EcLoginExt\Install\MailTemplateInstaller;
use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use EcLoginExt\Install\AttributesInstaller;

/**
 * EcLoginExt Plugin
 *
 * Extends Shopware 5's standard login functionality with enhanced security features:
 * - Configurable failed login attempt thresholds
 * - Account locking with customizable duration
 * - Email notifications with unlock tokens
 * - Backend administrative interface
 */
class EcLoginExt extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        /** Set plugin directory parameter for subscribers **/
        $container->setParameter('ec_login_ext.plugin_dir', $this->getPath());
        parent::build($container);
    }

    public function install(InstallContext $context): void
    {
        $service = $this->container->get('shopware_attribute.crud_service');
        $models = $this->container->get('models');

        $this->createAttributes($service, $models);
        $this->createEmailTemplates($models);
        parent::install($context);
    }

    public function uninstall(UninstallContext $context): void
    {
        /** Cleanup after confirmation in backend */
        if (!$context->keepUserData()) {

            $service = $this->container->get('shopware_attribute.crud_service');
            $models = $this->container->get('models');
            $this->removeAttributes($service, $models);

        }

        parent::uninstall($context);
    }

    public function update(UpdateContext $context): void
    {
        $service = $this->container->get('shopware_attribute.crud_service');
        $models = $this->container->get('models');

        $this->createAttributes($service, $models);
        parent::update($context);
    }

    /**
     * Create custom attributes for tracking failed login attempts
     */
    private function createAttributes($service, $models): void
    {
        $attributesInstaller = new AttributesInstaller($service, $models);
        $attributesInstaller->createAttributes();
    }

    /**
     * Remove custom attributes
     */
    private function removeAttributes($service, $models): void
    {
        $attributeInstaller = new AttributesInstaller($service, $models);
        $attributeInstaller->removeAttributes();
    }

    /**
     * Create email templates for account lockout notifications
     */
    private function createEmailTemplates($models): void
    {
        $emailInstaller = new MailTemplateInstaller($models, $this->getPath());
        $emailInstaller->installEmailTemplates();
    }

}