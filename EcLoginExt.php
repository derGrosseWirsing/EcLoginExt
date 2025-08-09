<?php

declare(strict_types=1);

namespace EcLoginExt;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;
use Shopware\Models\Mail\Mail;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
        // Set plugin directory parameter for subscribers
        $container->setParameter('ec_login_ext.plugin_dir', $this->getPath());
        parent::build($container);
    }

    public function install(InstallContext $context): void
    {
        $this->createAttributes();
        $this->createEmailTemplates();
        parent::install($context);
    }


    public function uninstall(UninstallContext $context): void
    {
        if (!$context->keepUserData()) {
            $this->removeAttributes();
        }
        parent::uninstall($context);
    }

    public function update(UpdateContext $context): void
    {
        $this->createAttributes();
        parent::update($context);
    }

    /**
     * Create custom attributes for tracking failed login attempts
     */
    private function createAttributes(): void
    {
        $service = Shopware()->Container()->get('shopware_attribute.crud_service');

        // Current failed attempts counter (resets on successful login/unlock)
        $service->update('s_user_attributes', 'ec_current_failed_attempts', 'integer', [
            'displayInBackend' => true,
            'label' => 'Current Failed Login Attempts',
            'helpText' => 'Current number of failed login attempts since last successful login',
            'defaultValue' => 0
        ]);

        // Total failed attempts counter (never resets)
        $service->update('s_user_attributes', 'ec_total_failed_attempts', 'integer', [
            'displayInBackend' => true,
            'label' => 'Total Failed Login Attempts',
            'helpText' => 'Total number of failed login attempts (cumulative)',
            'defaultValue' => 0
        ]);

        // Unlock token for email-based account recovery
        $service->update('s_user_attributes', 'ec_unlock_token', 'string', [
            'displayInBackend' => false,
            'label' => 'Account Unlock Token',
            'helpText' => 'Security token for email-based account unlock',
            'defaultValue' => null
        ]);

        // Locked until date
        $service->update('s_user_attributes', 'ec_locked_until', 'datetime', [
            'displayInBackend' => false,
            'label' => 'User locked until',
            'helpText' => 'User locked until',
            'defaultValue' => null
        ]);

        // Token expiration timestamp
        $service->update('s_user_attributes', 'ec_unlock_token_expires', 'datetime', [
            'displayInBackend' => false,
            'label' => 'Unlock Token Expires',
            'helpText' => 'Expiration date for unlock token',
            'defaultValue' => null
        ]);

        // Regenerate models
        Shopware()->Container()->get('models')->generateAttributeModels(['s_user_attributes']);
    }

    /**
     * Remove custom attributes
     */
    private function removeAttributes(): void
    {
        $service = Shopware()->Container()->get('shopware_attribute.crud_service');

        $service->delete('s_user_attributes', 'ec_current_failed_attempts');
        $service->delete('s_user_attributes', 'ec_total_failed_attempts');
        $service->delete('s_user_attributes', 'ec_unlock_token');
        $service->delete('s_user_attributes', 'ec_unlock_token_expires');

        Shopware()->Container()->get('models')->generateAttributeModels(['s_user_attributes']);
    }

    /**
     * Create email templates for account lockout notifications
     */
    private function createEmailTemplates(): void
    {
        $templateService = Shopware()->Container()->get('shopware.model_manager');

        // Check if template already exists
        $repository = $templateService->getRepository(Mail::class);
        $existingTemplate = $repository->findOneBy(['name' => 'sECSECURELOGINLOCKOUT']);

        if (!$existingTemplate) {
            $mail = new Mail();
            $mail->setName('sECSECURELOGINLOCKOUT');
            $mail->setFromName('{config name=shopName}');
            $mail->setFromMail('{config name=mail}');
            $mail->setSubject('Account temporarily locked');
            $mail->setContent($this->getEmailTemplate());
            $mail->setContentHtml($this->getEmailTemplateHtml());
            $mail->setIsHtml(true);
            $templateService->persist($mail);
            $templateService->flush();
        }
    }

    /**
     * Get plain text email template
     */
    private function getEmailTemplate(): string
    {
        return '{include file="string:{config name=emailheaderplain}"}
        Hello {$customerName},

Your account has been temporarily locked due to multiple failed login attempts for security reasons.

Your account will be automatically unlocked in {$lockDuration} hours.

Alternatively, you can unlock your account immediately by clicking this link:
{$unlockUrl}

If you did not attempt to log in to your account, please contact our customer service.

Best regards,
Your Shop Team

{include file="string:{config name=emailfooterplain}"}';

    }

    /**
     * Get HTML email template
     */
    private function getEmailTemplateHtml(): string
    {
        return '<div style="font-family:arial; font-size:12px;">
 {include file="string:{config name=emailheaderhtml}"}
 <p>Hello {$customerName},</p>

<p>Your account has been temporarily locked due to multiple failed login attempts for security reasons.</p>

<p>Your account will be automatically unlocked in <strong>{$lockDuration} hours</strong>.</p>

<p>Alternatively, you can unlock your account immediately by clicking this link:<br>
<a href="{$unlockUrl}" style="color: #007bff;">Unlock my account now</a></p>

<p>If you did not attempt to log in to your account, please contact our customer service.</p>

<p>Best regards,<br>
Your Shop Team

 {include file="string:{config name=emailfooterhtml}"}</p></div>';
    }
}