<?php
declare(strict_types=1);

namespace EcLoginExt\Install;

use Shopware\Components\Model\ModelManager;
use Shopware\Models\Mail\Mail;

/**
 * Class MailTemplateInstaller
 *
 * Handles the installation of email templates for account lockout notifications
 */
class MailTemplateInstaller
{
    public function __construct(private ModelManager $modelManager, private string $path)
    {
    }

    /**
     * Create email templates for account unlock notifications
     */
    public function installEmailTemplates(): void
    {
        /** Check if template already exists */
        $repository = $this->modelManager->getRepository(Mail::class);
        $existingTemplate = $repository->findOneBy(['name' => 'sECSECURELOGINLOCKOUT']);

        if (!$existingTemplate) {
            $textContent = $this->getEmailTemplate();
            $htmlContent = $this->getEmailTemplateHtml();
            
            // Debug logging
            error_log("EcLoginExt: Installing mail template with content lengths - Text: " . strlen($textContent) . ", HTML: " . strlen($htmlContent));
            
            $mail = new Mail();
            $mail->setName('sECSECURELOGINLOCKOUT');
            $mail->setFromName('{config name=shopName}');
            $mail->setFromMail('{config name=mail}');
            $mail->setSubject('Konto vorübergehend gesperrt');
            $mail->setContent($textContent);
            $mail->setContentHtml($htmlContent);
            $mail->setIsHtml(true);
            
            $this->modelManager->persist($mail);
            $this->modelManager->flush();
        }
    }

    /**
     * Get plain text email template from file
     */
    private function getEmailTemplate(): string
    {
        $templatePath = $this->path . '/Views/mail/lockout-notification.txt';
        
        if (!file_exists($templatePath)) {
            error_log("EcLoginExt: Template file not found: $templatePath");
            return 'Default plain text template content here.';
        }
        
        return file_get_contents($templatePath) ?: '';
    }

    /**
     * Get HTML email template from file
     */
    private function getEmailTemplateHtml(): string
    {
        $templatePath = $this->path . '/Views/mail/lockout-notification.html';
        
        if (!file_exists($templatePath)) {
            error_log("EcLoginExt: Template file not found: $templatePath");
            return 'Default HTML template content here.';
        }
        
        return file_get_contents($templatePath) ?: '';
    }
}