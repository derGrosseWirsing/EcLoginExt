<?php

declare(strict_types=1);

namespace EcLoginExt\Services;

use DateInterval;
use DateTime;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use EcLoginExt\EcLoginExt;
use Enlight_Controller_Front;
use Enlight_Event_EventManager;
use Exception;
use Shopware\Components\DependencyInjection\Container;
use Shopware\Models\Customer\Customer;
use Shopware_Components_TemplateMail;

/**
 * Class LoginSecurityService
 *
 * Handles login security features like failed login tracking, account locking,
 * and email notifications for account unlock.
 *
 * This service is designed to work with Shopware's customer model and
 * integrates with the event system to allow for custom handling of login events.
 */
class LoginSecurityService
{
    public function __construct(
        private EntityManager                    $em,
        private Shopware_Components_TemplateMail $templateMail,
        private Enlight_Controller_Front         $front,
        private Container                        $container,
        private Enlight_Event_EventManager       $eventManager
    )
    {
    }

    /**
     * Get plugin configuration
     */
    private function getConfig(): array
    {
        return $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('EcLoginExt') ?: [];
    }

    /**
     * Handle failed login attempt - called AFTER Shopware's core logic
     * @see EcLoginExt/Subscriber/AuthSubscriber
     */
    public function handleFailedLogin(string $email): array
    {
        /**
         * Skip processing if event manager notifies to skip
         * This allows other plugins to control whether we should process failed logins
         */
        if($this->shouldSkipProcessing($email)) {
            return [];
        }

        $config = $this->getFailedLoginConfig();
        /**
         * Fetch customer data by email
         * Can be filtered by other plugins using the event manager
         */
        $customerData = $this->getCustomerDataByEmail($email);
        
        if (!$customerData) {
            return ['locked' => false, 'reason' => 'customer_not_found'];
        }

        $lockState = $this->calculateLockState($customerData, $config['now']);
        $newCounts = $this->calculateNewAttemptCounts($customerData, $lockState['isAlreadyLocked']);

        return $this->processFailedLoginInTransaction(
            $customerData, 
            $lockState, 
            $newCounts, 
            $config,
            $email
        );
    }

    /**
     * Handle successful login - reset current failed attempts counter
     */
    public function handleSuccessfulLogin(Customer $customer): void
    {
        $this->em->refresh($customer);
        
        $attribute = $customer->getAttribute();
        if (!$attribute) {
            return;
        }

        $customer->setFailedLogins(0);
        
        if ($this->customerNeedsUnlock($customer->getId())) {
            $this->unlockCustomerAfterSuccess($customer, $attribute);
        }
    }

    /**
     * Unlock account using token
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    public function unlockWithToken(string $token): array
    {
        if (empty($token)) {
            return ['success' => false, 'error' => 'invalid_token'];
        }

        $customer = $this->getCustomerByUnlockToken($token);
        if (!$customer) {
            return ['success' => false, 'error' => 'token_not_found'];
        }

        $attribute = $customer->getAttribute();
        $expires = $attribute->getEcUnlockTokenExpires();

        if (!$expires || $expires < new DateTime()) {
            return ['success' => false, 'error' => 'token_expired'];
        }

        /**
         * Unlock customer using Query Builder
         * @see: https://www.doctrine-project.org/projects/doctrine-orm/en/3.5/reference/transactions-and-concurrency.html
         * @todo : If queryBuilder is a performance issue, consider using native SQL
         */
        $this->em->beginTransaction();
        try {
            // Clear Shopware's lock fields
            $this->em->createQueryBuilder()
                ->update(Customer::class, 'c')
                ->set('c.failedLogins', ':failedLogins')
                ->set('c.lockedUntil', ':lockedUntil')
                ->where('c.id = :customerId')
                ->setParameter('failedLogins', 0)
                ->setParameter('lockedUntil', null)
                ->setParameter('customerId', $customer->getId())
                ->getQuery()
                ->execute();

            if ($attribute) {
                $attribute->setEcCurrentFailedAttempts(0);
                $attribute->setEcLockedUntil(null);
                $attribute->setEcUnlockToken(null);
                $attribute->setEcUnlockTokenExpires(null);
                $this->em->persist($attribute);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (Exception $e) {
            $this->em->rollback();
            throw $e;
        }

        return ['success' => true, 'customer' => $customer];
    }

    /**
     * Send lockout notification email
     * This method constructs the email content using a template
     * and sends it to the customer when their account is locked.
     * It uses the template mail service to create and send the email.
     *
     * A filter is applied to allow other plugins to modify the mail before sending.
     *
     */
    private function sendLockoutNotification(Customer $customer, int $lockDurationHours): void
    {
        $attribute = $customer->getAttribute();

        if (!$attribute || empty($attribute->getEcUnlockToken())) {
            return;
        }

        $unlockUrl = $this->front->Router()->assemble([
            'sViewport' => 'EcUnlock',
            'action' => 'index',
            'token' => $attribute->getEcUnlockToken(),
            'module' => 'frontend'
        ]);

        $context = [
            'customerName' => $customer->getFirstname() . ' ' . $customer->getLastname(),
            'lockDuration' => $lockDurationHours,
            'unlockUrl' => $unlockUrl,
            'totalAttempts' => $attribute->getEcTotalFailedAttempts()
        ];

        try {
            $mail = $this->templateMail->createMail('sECSECURELOGINLOCKOUT', $context, $customer->getShop());

            $mail = $this->eventManager->filter('LoginSecurityService.sendLockoutNotificationMailFilter', $mail, [
                'customer' => $customer,
                'context' => $context
            ]);

            $mail->addTo($customer->getEmail());
            $mail->send();

        } catch (Exception $e) {
            error_log('EcLoginExt: Failed to send lockout notification: ' . $e->getMessage());
        }
    }

    /**
     * Get customer by unlock token
     * @todo : If queryBuilder is a performance issue, consider using native SQL
     */
    private function getCustomerByUnlockToken(string $token): ?Customer
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Customer::class, 'c')
            ->leftJoin('c.attribute', 'a')
            ->where('a.ecUnlockToken = :token')
            ->setParameter('token', $token);

        try {
            return $qb->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if customer is currently locked using our custom field
     */
    public function isCustomerLocked(Customer $customer): bool
    {
        $attribute = $customer->getAttribute();
        if (!$attribute) {
            return false;
        }

        $lockedUntil = $attribute->getEcLockedUntil();

        /** Check if our custom locked field is set and is in the future */
        if ($lockedUntil instanceof DateTime) {
            $now = new DateTime();
            return $lockedUntil > $now;
        }

        return false;
    }

    /**
     * Parse lock time from database value (handles both DateTime objects and strings)
     */
    private function parseLockTime($lockTime): ?DateTime
    {
        if (empty($lockTime)) {
            return null;
        }

        // If it's already a DateTime object, return it
        if ($lockTime instanceof DateTime) {
            return $lockTime;
        }

        // If it's a string, parse it
        if (is_string($lockTime)) {
            try {
                return new DateTime($lockTime);
            } catch (Exception $e) {
                error_log('EcLoginExt: Failed to parse lock time: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }


    /**
     * Update customer counters in a single batch operation
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    private function updateCustomerCounters(int $customerId, int $shopwareCounter, int $currentFailed, int $totalFailed): void
    {
        // Update Shopware's counter
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.failedLogins', ':count')
            ->where('c.id = :customerId')
            ->setParameter('count', $shopwareCounter)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();

        // Update our custom attributes
        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer && $customer->getAttribute()) {
            $attribute = $customer->getAttribute();
            $attribute->setEcCurrentFailedAttempts($currentFailed);
            $attribute->setEcTotalFailedAttempts($totalFailed);
            $this->em->persist($attribute);
            $this->em->flush();
        }
    }

    /**
     * Synchronize our custom lock time to Shopware's native field
     * since Shopware updates the lockedUntil field automatically at every login attempt
     * and we need to ensure it matches our custom ecLockedUntil field.
     * This is a workaround to ensure consistency between our custom field and Shopware's native lock
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    private function synchronizeNativeLockField(int $customerId, DateTime $lockedUntil): void
    {
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.lockedUntil', ':lockUntil')
            ->where('c.id = :customerId')
            ->setParameter('lockUntil', $lockedUntil)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();
    }

    /**
     * Lock customer and generate token if needed (combined operation)
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    private function lockCustomerWithToken(int $customerId, DateTime $lockUntil, bool $enableEmailUnlock, array $config): void
    {
        /**  Update Shopware's lock field  */
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.lockedUntil', ':lockUntil')
            ->where('c.id = :customerId')
            ->setParameter('lockUntil', $lockUntil)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();

        /** Set our custom lock field and generate token */
        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer && $customer->getAttribute()) {
            $attribute = $customer->getAttribute();
            $attribute->setEcLockedUntil($lockUntil);

            /** Generate unlock token if email unlock is enabled */
            if ($enableEmailUnlock) {
                $token = bin2hex(random_bytes(32));
                $expiryHours = (int)($config['unlockTokenExpiryHours'] ?? 24);
                $expires = (clone $lockUntil)->add(new DateInterval('PT' . $expiryHours . 'H'));

                $attribute->setEcUnlockToken($token);
                $attribute->setEcUnlockTokenExpires($expires);
            }

            $this->em->persist($attribute);
            $this->em->flush();
        }
    }

    /**
     * Check if processing should be skipped
     */
    private function shouldSkipProcessing(string $email): bool
    {
        $break = $this->eventManager->notifyUntil(
            'EcLoginExt.handleFailedLoginNotifyUntil',
            ['email' => $email]
        );
        return (bool) $break;
    }

    /**
     * Get configuration for failed login handling
     */
    private function getFailedLoginConfig(): array
    {
        $config = $this->getConfig();
        return [
            'maxAttempts' => (int)($config['maxAttempts'] ?? 3),
            'lockDurationHours' => (int)($config['lockDurationHours'] ?? 24),
            'enableEmailUnlock' => $config['enableEmailUnlock'] ?? true,
            'now' => new DateTime()
        ];
    }

    /**
     * Get customer data by email
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    private function getCustomerDataByEmail(string $email): ?array
    {
        $customerData = $this->em->createQueryBuilder()
            ->select([
                'c.id',
                'c.email',
                'c.firstname',
                'c.lastname',
                'ca.ecCurrentFailedAttempts',
                'ca.ecTotalFailedAttempts',
                'ca.ecLockedUntil'
            ])
            ->from(Customer::class, 'c')
            ->leftJoin('c.attribute', 'ca')
            ->where('c.email = :email')
            ->andWhere('c.accountMode = 0')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        return $this->eventManager->filter(
            'EcLoginExt.handleFailedLoginOnFilterCustomerData',
            $customerData,
            ['email' => $email]
        );
    }

    /**
     * Calculate lock state for customer
     * is already locked or not, and until when
     */
    private function calculateLockState(array $customerData, DateTime $now): array
    {
        $lockedUntil = $this->parseLockTime($customerData['ecLockedUntil'] ?? null);
        $isAlreadyLocked = $lockedUntil && $lockedUntil > $now;
        
        return [
            'lockedUntil' => $lockedUntil,
            'isAlreadyLocked' => $isAlreadyLocked
        ];
    }

    /**
     * Calculate new attempt counts
     */
    private function calculateNewAttemptCounts(array $customerData, bool $isAlreadyLocked): array
    {
        $currentFailed = (int)($customerData['ecCurrentFailedAttempts'] ?? 0);
        $totalFailed = (int)($customerData['ecTotalFailedAttempts'] ?? 0);
        
        return [
            'newTotalFailed' => $totalFailed + 1,
            'newCurrentFailed' => $isAlreadyLocked ? $currentFailed : $currentFailed + 1,
            'currentFailed' => $currentFailed
        ];
    }

    /**
     * Process failed login within transaction
     * @todo : If queryBuilder a performance issue, consider using native SQL
     */
    private function processFailedLoginInTransaction(
        array $customerData, 
        array $lockState, 
        array $newCounts, 
        array $config, 
        string $email
    ): array {
        $customerId = (int)$customerData['id'];

        /**
         * Use Doctrine's transaction management
         * @see: https://www.doctrine-project.org/projects/doctrine-orm/en/3.5/reference/transactions-and-concurrency.html
         */
        $this->em->beginTransaction();
        try {
            $this->updateCustomerCounters($customerId, 0, $newCounts['newCurrentFailed'], $newCounts['newTotalFailed']);

            if ($lockState['isAlreadyLocked']) {
                /** No new lock needed: just prevent SW to extend the lockeduntil and update total counts */
                return $this->handleAlreadyLockedCustomer($customerData, $lockState, $newCounts, $config, $email);
            }

            if ($newCounts['newCurrentFailed'] >= $config['maxAttempts']) {
                /** New lock required: lock customer and send notification */
                return $this->handleNewLockRequired($customerData, $newCounts, $config);
            }
            /** no lock: count attempts only*/
            return $this->handleFailedAttemptTracking($customerData, $newCounts, $config);
            
        } catch (Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Handle customer that is already locked
     * Core lockeduntil field is updated to match our custom ecLockedUntil field
     * since shopware updates the lockedUntil field automatically at every login attempt.
     * No mail is sent in this case, as the customer is already locked.
     * This method logs the event and notifies the event manager.
     */
    private function handleAlreadyLockedCustomer(
        array $customerData, 
        array $lockState, 
        array $newCounts, 
        array $config, 
        string $email
    ): array {
        $customerId = (int)$customerData['id'];
        $this->synchronizeNativeLockField($customerId, $lockState['lockedUntil']);
        $this->em->commit();

        $this->eventManager->notify('EcLoginExt.handleFailedLoginAlreadyLockedNotify', [
            'customerId' => $customerId,
            'email' => $email,
            'lockedUntil' => $lockState['lockedUntil'],
            'total_failed_attempts' => $newCounts['newTotalFailed'],
            'current_failed_attempts' => $newCounts['currentFailed'],
        ]);

        return $this->createLockedResponse(
            $lockState['lockedUntil'], 
            $newCounts['currentFailed'], 
            $config['maxAttempts'], 
            $newCounts['newTotalFailed'], 
            'already_locked', 
            false
        );
    }

    /**
     * Handle new lock requirement
     * This method locks the customer account, generates an unlock token if enabled,
     * and sends a notification email if configured.
     * It logs the event and notifies the event manager.
     */
    private function handleNewLockRequired(
        array $customerData, 
        array $newCounts, 
        array $config,
    ): array {
        $customerId = (int)$customerData['id'];
        $lockUntil = (clone $config['now'])->add(new DateInterval('PT' . $config['lockDurationHours'] . 'H'));
        
        $this->lockCustomerWithToken($customerId, $lockUntil, $config['enableEmailUnlock'], $this->getConfig());
        $this->em->commit();

        if ($config['enableEmailUnlock']) {
            $customer = $this->em->find(Customer::class, $customerId);
            if ($customer && $customer->getAttribute() && $customer->getAttribute()->getEcUnlockToken()) {

                /** send notification mail */
                $this->sendLockoutNotification($customer, $config['lockDurationHours']);
            }
        }

        $this->eventManager->notify('EcLoginExt.handleFailedLoginNewlyLockedNotify', [
            'customer' => $customerData,
            'total_failed_attempts' => $newCounts['newTotalFailed'],
            'current_failed_attempts' => $newCounts['newCurrentFailed'],
            'lock_until' => $lockUntil->format('Y-m-d H:i:s'),
            'max_attempts_configured' => $config['maxAttempts']
        ]);

        return $this->createLockedResponse(
            $lockUntil, 
            $newCounts['newCurrentFailed'], 
            $config['maxAttempts'], 
            $newCounts['newTotalFailed'], 
            'max_attempts_reached', 
            $config['enableEmailUnlock']
        );
    }

    /**
     * Handle failed attempt tracking (no lock required)
     */
    private function handleFailedAttemptTracking(
        array $customerData, 
        array $newCounts, 
        array $config
    ): array {
        $customerId = (int)$customerData['id'];
        $this->em->commit();

        $this->eventManager->notify('EcLoginExt.handleFailedLoginLoginFailed', [
            'customer' => $customerData,
            'total_failed_attempts' => $newCounts['newTotalFailed'],
            'current_failed_attempts' => $newCounts['newCurrentFailed'],
            'max_attempts_configured' => $config['maxAttempts']
        ]);

        return [
            'locked' => false,
            'attempts_remaining' => $config['maxAttempts'] - $newCounts['newCurrentFailed'],
            'attempts' => $newCounts['newCurrentFailed'],
            'max_attempts' => $config['maxAttempts'],
            'total_failed_attempts' => $newCounts['newTotalFailed']
        ];
    }

    /**
     * Check if customer needs to be unlocked
     */
    private function customerNeedsUnlock(int $customerId): bool
    {
        $lockData = $this->em->createQueryBuilder()
            ->select('ca.ecCurrentFailedAttempts', 'ca.ecLockedUntil')
            ->from(Customer::class, 'c')
            ->leftJoin('c.attribute', 'ca')
            ->where('c.id = :customerId')
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->getArrayResult();

        $lockInfo = !empty($lockData) ? $lockData[0] : null;
        return $lockInfo && ($lockInfo['ecCurrentFailedAttempts'] > 0 || !empty($lockInfo['ecLockedUntil']));
    }

    /**
     * Unlock customer after successful login
     */
    private function unlockCustomerAfterSuccess(Customer $customer, $attribute): void
    {
        $this->em->beginTransaction();
        try {
            $this->clearShopwareLockFields($customer->getId());
            $this->clearCustomerAttributes($customer, $attribute);
            
            $this->em->flush();
            $this->em->commit();
        } catch (Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Clear Shopware's native lock fields
     */
    private function clearShopwareLockFields(int $customerId): void
    {
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.failedLogins', ':failedLogins')
            ->set('c.lockedUntil', ':lockedUntil')
            ->where('c.id = :customerId')
            ->setParameter('failedLogins', 0)
            ->setParameter('lockedUntil', null)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();
    }

    /**
     * Clear custom attributes after successful login
     */
    private function clearCustomerAttributes(Customer $customer, $attribute): void
    {
        $customer->setFailedLogins(0);
        if ($attribute) {
            $attribute->setEcCurrentFailedAttempts(0);
            $attribute->setEcLockedUntil(null);
            $attribute->setEcUnlockToken(null);
            $attribute->setEcUnlockTokenExpires(null);
            $this->em->persist($attribute);
        }
    }

    /**
     * Create standardized locked response
     */
    private function createLockedResponse(DateTime $lockUntil, int $attempts, int $maxAttempts, int $totalFailed, string $reason, bool $unlockEmailSent): array
    {
        return [
            'locked' => true,
            'reason' => $reason,
            'lock_until' => $lockUntil,
            'unlock_email_sent' => $unlockEmailSent,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'total_failed_attempts' => $totalFailed
        ];
    }
}