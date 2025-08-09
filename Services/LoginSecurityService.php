<?php

declare(strict_types=1);

namespace EcLoginExt\Services;

use DateInterval;
use DateTime;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use EcLoginExt\EcLoginExt;
use EcLoginExt\Subscriber\AuthSubscriber;
use Enlight_Controller_Front;
use Shopware\Models\Customer\Customer;
use Shopware_Components_TemplateMail;

/**
 * Login Security Service
 * 
 * Handles security logic for failed login attempts including:
 * - Account locking based on configurable thresholds
 * - Email notifications with unlock tokens
 * - Security event logging
 */
class LoginSecurityService
{
     public function __construct(
        private EntityManager                    $em,
        private Shopware_Components_TemplateMail $templateMail,
        private Enlight_Controller_Front         $front
    ) {

    }

    /**
     * Get plugin configuration
     */
    private function getConfig(): array
    {
        return Shopware()->Container()->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('EcLoginExt') ?: [];
    }

    /**
     * Handle failed login attempt - called AFTER Shopware's core logic
     * @see EcLoginExt/Subscriber/AuthSubscriber
     */
    public function handleFailedLogin(string $email): array
    {
        $config = $this->getConfig();
        $maxAttempts = (int) ($config['maxAttempts'] ?? 3);
        $lockDurationHours = (int) ($config['lockDurationHours'] ?? 24);
        $enableEmailUnlock = $config['enableEmailUnlock'] ?? true;
        $now = new DateTime();

        // Single query to get all customer data we need
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

        if (!$customerData) {
            return ['locked' => false, 'reason' => 'customer_not_found'];
        }

        $customerId = (int) $customerData['id'];
        $currentFailed = (int) ($customerData['ecCurrentFailedAttempts'] ?? 0);
        $totalFailed = (int) ($customerData['ecTotalFailedAttempts'] ?? 0);
        
        // Parse lock time and check if customer is currently locked
        $lockedUntil = $this->parseLockTime($customerData['ecLockedUntil'] ?? null);
        $isAlreadyLocked = $lockedUntil && $lockedUntil > $now;

        // Prepare new attempt counts
        $newTotalFailed = $totalFailed + 1;
        $newCurrentFailed = $isAlreadyLocked ? $currentFailed : $currentFailed + 1;
        
        // Use transaction for atomic updates
        $this->em->beginTransaction();
        try {
            // Reset Shopware's counter and update our counters in one batch
            $this->updateCustomerCounters($customerId, 0, $newCurrentFailed, $newTotalFailed);

            if ($isAlreadyLocked) {
                // Customer already locked - sync lock time and log
                $this->synchronizeNativeLockField($customerId, $lockedUntil);
                
                $this->em->commit();
                
                $this->logSecurityEventById('login_failed_while_locked', $customerId, $customerData['email'], [
                    'total_failed_attempts' => $newTotalFailed,
                    'current_failed_attempts' => $currentFailed,
                    'lock_until' => $lockedUntil->format('Y-m-d H:i:s')
                ]);

                return $this->createLockedResponse($lockedUntil, $currentFailed, $maxAttempts, $newTotalFailed, 'already_locked', false);
            }

            // Check if we should lock the customer now
            if ($newCurrentFailed >= $maxAttempts) {
                $lockUntil = (clone $now)->add(new DateInterval('PT' . $lockDurationHours . 'H'));
                
                // Lock customer and generate token if needed
                $this->lockCustomerWithToken($customerId, $lockUntil, $enableEmailUnlock, $config);
                
                $this->em->commit();
                
                // Send notification email if enabled (after commit to avoid issues if email fails)
                if ($enableEmailUnlock) {
                    $customer = $this->em->find(Customer::class, $customerId);
                    if ($customer && $customer->getAttribute() && $customer->getAttribute()->getEcUnlockToken()) {
                        $this->sendLockoutNotification($customer, $lockDurationHours);
                    }
                }

                $this->logSecurityEventById('account_locked', $customerId, $customerData['email'], [
                    'total_failed_attempts' => $newTotalFailed,
                    'current_failed_attempts' => $newCurrentFailed,
                    'lock_until' => $lockUntil->format('Y-m-d H:i:s'),
                    'max_attempts_configured' => $maxAttempts
                ]);

                return $this->createLockedResponse($lockUntil, $newCurrentFailed, $maxAttempts, $newTotalFailed, 'max_attempts_reached', $enableEmailUnlock);
            } else {
                // Just track the attempt
                $this->em->commit();
                
                $this->logSecurityEventById('login_failed', $customerId, $customerData['email'], [
                    'total_failed_attempts' => $newTotalFailed,
                    'current_failed_attempts' => $newCurrentFailed,
                    'attempts_remaining' => $maxAttempts - $newCurrentFailed
                ]);

                return [
                    'locked' => false, 
                    'attempts_remaining' => $maxAttempts - $newCurrentFailed,
                    'attempts' => $newCurrentFailed,
                    'max_attempts' => $maxAttempts,
                    'total_failed_attempts' => $newTotalFailed
                ];
            }
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Handle successful login - reset current failed attempts counter
     */
    public function handleSuccessfulLogin(Customer $customer): void
    {
        error_log('EcLoginExt DEBUG: handleSuccessfulLogin called for customer ID: ' . $customer->getId() . ', email: ' . $customer->getEmail());
        
        // Refresh customer to ensure we have latest data
        $this->em->refresh($customer);

        $attribute = $customer->getAttribute();
        if (!$attribute) {
            return;
        }

        // Always ensure Shopware's counter stays at 0
        $customer->setFailedLogins(0);
        
        // Check if we need to unlock using our custom field with Query Builder
        $lockData = $this->em->createQueryBuilder()
            ->select('ca.ecCurrentFailedAttempts', 'ca.ecLockedUntil')
            ->from('Shopware\Models\Customer\Customer', 'c')
            ->leftJoin('c.attribute', 'ca')
            ->where('c.id = :customerId')
            ->setParameter('customerId', $customer->getId())
            ->getQuery()
            ->getArrayResult();
        
        $lockInfo = !empty($lockData) ? $lockData[0] : null;
        $needsUnlock = ($lockInfo && ($lockInfo['ecCurrentFailedAttempts'] > 0 || !empty($lockInfo['ecLockedUntil'])));
        
        if ($needsUnlock) {
            $lockedUntilValue = $lockInfo['ecLockedUntil'] ?? null;
            $lockedUntilString = $lockedUntilValue instanceof DateTime ? $lockedUntilValue->format('Y-m-d H:i:s') : ($lockedUntilValue ?? 'NULL');
            error_log('EcLoginExt DEBUG: UNLOCKING customer in handleSuccessfulLogin - was locked until: ' . $lockedUntilString);
            
            // Unlock customer using Query Builder and entity management
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
                
                // Clear our custom fields using entity management
                $customer->setFailedLogins(0);
                if ($attribute) {
                    $attribute->setEcCurrentFailedAttempts(0);
                    $attribute->setEcLockedUntil(null);
                    $attribute->setEcUnlockToken(null);
                    $attribute->setEcUnlockTokenExpires(null);
                    $this->em->persist($attribute);
                }
                
                $this->em->flush();
                $this->em->commit();
            } catch (\Exception $e) {
                $this->em->rollback();
                throw $e;
            }
            
            $this->logSecurityEvent('login_success_after_failures', $customer, null, [
                'total_failed_attempts' => $attribute->getEcTotalFailedAttempts()
            ]);
        }
    }

    /**
     * Generate secure unlock token for customer (only if not already set)
     */
    private function generateUnlockToken(Customer $customer): void
    {
        $attribute = $customer->getAttribute();
        if (!$attribute) {
            return; // No attribute entity available
        }

        // Check if token already exists and is still valid
        $existingToken = $attribute->getEcUnlockToken();
        $existingExpiry = $attribute->getEcUnlockTokenExpires();
        
        if (!empty($existingToken) && $existingExpiry && $existingExpiry > new DateTime()) {
            // Token already exists and is still valid, don't generate a new one
            return;
        }

        // Generate new token only if none exists or the existing one has expired
        $token = bin2hex(random_bytes(32)); // 64 character secure token
        $config = $this->getConfig();
        $expiryHours = (int) ($config['unlockTokenExpiryHours'] ?? 24);
        
        $expires = new DateTime();
        $expires->add(new DateInterval('PT' . $expiryHours . 'H'));
        
        $attribute->setEcUnlockToken($token);
        $attribute->setEcUnlockTokenExpires($expires);
    }

    /**
     * Unlock account using token
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

        // Unlock customer using Query Builder
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
            
            // Clear our custom fields in attributes
            $attribute = $customer->getAttribute();
            if ($attribute) {
                $attribute->setEcCurrentFailedAttempts(0);
                $attribute->setEcLockedUntil(null);
                $attribute->setEcUnlockToken(null);
                $attribute->setEcUnlockTokenExpires(null);
                $this->em->persist($attribute);
            }
            
            $this->em->flush();
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }

        return ['success' => true, 'customer' => $customer];
    }

    /**
     * Send lockout notification email
     */
    private function sendLockoutNotification(Customer $customer, int $lockDurationHours): void
    {
        $attribute = $customer->getAttribute();

        if (!$attribute || empty($attribute->getEcUnlockToken())) {
            error_log('EcLoginExt: Cannot send lockout notification - no unlock token available');
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
            $mail->addTo($customer->getEmail());
            $mail->send();
            
        } catch (\Exception $e) {
            error_log('EcLoginExt: Failed to send lockout notification: ' . $e->getMessage());
        }
    }

    /**
     * Log security events
     */
    private function logSecurityEvent(string $event, Customer $customer, array $data = []): void
    {
        $config = $this->getConfig();
        if (!($config['logSecurityEvents'] ?? true)) {
            return;
        }

        $logData = [
            'event' => $event,
            'customer_id' => $customer->getId(),
            'customer_email' => $customer->getEmail(),
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        error_log('EcLoginExt: ' . json_encode($logData));
    }

    /**
     * Get customer by email
     */
    private function getCustomerByEmail(string $email): ?Customer
    {
        return $this->em->getRepository(Customer::class)
            ->findOneBy(['email' => $email]);
    }

    /**
     * Get customer by unlock token
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
        } catch (\Exception $e) {
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
        
        // Check if our custom locked field is set and is in the future
        if ($lockedUntil instanceof DateTime) {
            $now = new DateTime();
            return $lockedUntil > $now;
        }

        return false;
    }

    /**
     * Generate unlock token using Query Builder and entity management
     */
    private function generateUnlockTokenById(int $customerId, array $config): void
    {
        // Get customer with attributes using Query Builder
        $customerData = $this->em->createQueryBuilder()
            ->select('ca.ecUnlockToken', 'ca.ecUnlockTokenExpires')
            ->from(Customer::class, 'c')
            ->leftJoin('c.attribute', 'ca')
            ->where('c.id = :customerId')
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->getArrayResult();
        
        $tokenInfo = !empty($customerData) ? $customerData[0] : null;
        
        // Check if token already exists and is still valid
        if ($tokenInfo && 
            !empty($tokenInfo['ecUnlockToken']) && 
            $tokenInfo['ecUnlockTokenExpires'] && 
            $this->parseLockTime($tokenInfo['ecUnlockTokenExpires']) > new DateTime()) {
            // Token already exists and is still valid
            return;
        }

        // Generate new token using entity management
        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer && $customer->getAttribute()) {
            $token = bin2hex(random_bytes(32));
            $expiryHours = (int) ($config['unlockTokenExpiryHours'] ?? 24);
            
            $expires = new DateTime();
            $expires->add(new DateInterval('PT' . $expiryHours . 'H'));
            
            $attribute = $customer->getAttribute();
            $attribute->setEcUnlockToken($token);
            $attribute->setEcUnlockTokenExpires($expires);
            $this->em->persist($attribute);
            $this->em->flush();
        }
    }

    /**
     * Log security events with customer ID
     */
    private function logSecurityEventById(string $event, int $customerId, string $email, array $data = []): void
    {
        $config = $this->getConfig();
        if (!($config['logSecurityEvents'] ?? true)) {
            return;
        }

        $logData = [
            'event' => $event,
            'customer_id' => $customerId,
            'customer_email' => $email,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        error_log('EcLoginExt: ' . json_encode($logData));
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
            } catch (\Exception $e) {
                error_log('EcLoginExt: Failed to parse lock time: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Update failed login counter in s_user table
     */
    private function updateFailedLoginsCounter(int $customerId, int $count): void
    {
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.failedLogins', ':count')
            ->where('c.id = :customerId')
            ->setParameter('count', $count)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();
    }

    /**
     * Update failed attempts in s_user_attributes table
     */
    private function updateFailedAttempts(int $customerId, int $currentFailed, int $totalFailed): void
    {
        // Find the customer and update attributes using entity management
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
     * Lock customer by setting both custom and Shopware lock fields
     */
    private function lockCustomer(int $customerId, DateTime $lockUntil): void
    {
        $this->em->beginTransaction();
        try {
            // Update Shopware's lock field using Query Builder
            $this->em->createQueryBuilder()
                ->update(Customer::class, 'c')
                ->set('c.lockedUntil', ':lockUntil')
                ->where('c.id = :customerId')
                ->setParameter('lockUntil', $lockUntil)
                ->setParameter('customerId', $customerId)
                ->getQuery()
                ->execute();

            // Set our custom lock field using entity management
            $customer = $this->em->find(Customer::class, $customerId);
            if ($customer && $customer->getAttribute()) {
                $attribute = $customer->getAttribute();
                $attribute->setEcLockedUntil($lockUntil);
                $this->em->persist($attribute);
                $this->em->flush();
            }

            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Update customer counters in a single batch operation
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
     */
    private function lockCustomerWithToken(int $customerId, DateTime $lockUntil, bool $enableEmailUnlock, array $config): void
    {
        // Update Shopware's lock field using Query Builder
        $this->em->createQueryBuilder()
            ->update(Customer::class, 'c')
            ->set('c.lockedUntil', ':lockUntil')
            ->where('c.id = :customerId')
            ->setParameter('lockUntil', $lockUntil)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->execute();

        // Set our custom lock field and generate token using entity management
        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer && $customer->getAttribute()) {
            $attribute = $customer->getAttribute();
            $attribute->setEcLockedUntil($lockUntil);
            
            // Generate unlock token if email unlock is enabled
            if ($enableEmailUnlock) {
                $token = bin2hex(random_bytes(32));
                $expiryHours = (int) ($config['unlockTokenExpiryHours'] ?? 24);
                $expires = (clone $lockUntil)->add(new DateInterval('PT' . $expiryHours . 'H'));
                
                $attribute->setEcUnlockToken($token);
                $attribute->setEcUnlockTokenExpires($expires);
            }
            
            $this->em->persist($attribute);
            $this->em->flush();
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