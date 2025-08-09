<?php
declare(strict_types=1);

namespace EcLoginExt\Install;

use Shopware\Bundle\AttributeBundle\Service\CrudService;
use Shopware\Components\Model\ModelManager;

/**
 * Class AttributesInstaller
 *
 * Handles the creation and removal of custom attributes for user accounts
 */
class AttributesInstaller
{
    public function __construct(
        private CrudService $service,
        private ModelManager $models
    )
    {
    }

    /**
     * Create custom attributes for tracking failed login attempts
     */
    public function createAttributes(): void
    {
        /** Current failed attempts counter (resets on successful login/unlock) */
        $this->service->update('s_user_attributes', 'ec_current_failed_attempts', 'integer', [
            'displayInBackend' => true,
            'label' => 'Momentane fehlgeschlagene Fehlversuche',
            'helpText' => 'Fehlgeschlagene Login-Versuche seit dem letzten erfolgreichen Login oder Entsperren des Kontos',
            'defaultValue' => 0
        ]);

        /** Total failed attempts counter (never resets) */
        $this->service->update('s_user_attributes', 'ec_total_failed_attempts', 'integer', [
            'displayInBackend' => true,
            'label' => 'Gesamte fehlgeschlagene Login-Versuche',
            'helpText' => 'Gesamte Anzahl fehlgeschlagener Login-Versuche, die jemals für diesen Benutzer durchgeführt wurden',
            'defaultValue' => 0
        ]);

        /**
         *  Unlock token for email-based account recovery
         *  Unlocking url: https://your-shop-domain.de/ecUnlock/index/token/UNLOCK_TOKEN
         * displayBackend true to allow administrators to view and manage tokens
         */
        $this->service->update('s_user_attributes', 'ec_unlock_token', 'string', [
            'displayInBackend' => true,
            'label' => 'Entsperr-Token',
            'helpText' => 'Sicherheits-Token für die Entsperrung des Kontos per E-Mail',
            'defaultValue' => null
        ]);

        /**
         * Locked until date
         * displayBackend true to allow administrators to view and manage locked accounts
         */
        $this->service->update('s_user_attributes', 'ec_locked_until', 'datetime', [
            'displayInBackend' => true,
            'label' => 'User locked until',
            'helpText' => 'User locked until',
            'defaultValue' => null
        ]);

        /**
         * Token expiration timestamp
         * displayBackend true to allow administrators to view and manage token expirations
         */
        $this->service->update('s_user_attributes', 'ec_unlock_token_expires', 'datetime', [
            'displayInBackend' => true,
            'label' => 'Unlock Token Expires',
            'helpText' => 'Expiration date for unlock token',
            'defaultValue' => null
        ]);

        $this->models->generateAttributeModels(['s_user_attributes']);
    }

    /**
     * Remove custom attributes from user accounts
     */
    public function removeAttributes(): void
    {
        $this->service->delete('s_user_attributes', 'ec_current_failed_attempts');
        $this->service->delete('s_user_attributes', 'ec_total_failed_attempts');
        $this->service->delete('s_user_attributes', 'ec_unlock_token');
        $this->service->delete('s_user_attributes', 'ec_unlock_token_expires');

        $this->models->generateAttributeModels(['s_user_attributes']);
    }

}