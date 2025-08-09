<?php
declare(strict_types=1);

namespace EcLoginExt\Bundle\AccountBundle\Service;

use Doctrine\DBAL\Connection;
use Shopware\Bundle\AccountBundle\Service\CustomerUnlockServiceInterface;

/**
 * Decorator for CustomerUnlockServiceInterface
 *
 * This service extends the functionality of the default customer unlock service
 * by resetting the lockedUntil and failedlogins fields in the database.
 * It is used to ensure that when a customer is unlocked, their account status
 * is properly updated in the User-Attributes.
 */
class CustomerUnlockServiceDecorator implements CustomerUnlockServiceInterface
{

    public function __construct(
        private CustomerUnlockServiceInterface $service,
        private Connection                     $connection
    )
    {
    }

    /**
     * Complete overwrite since the original service is just one line of code
     * and we need to reset additional fields in the user attributes.
     */
    public function unlock($customerId)
    {
        $this->connection->update('s_user', ['lockedUntil' => null, 'failedlogins' => 0], ['id' => $customerId]);
        $this->connection->update('s_user_attributes',
            [
                'ec_locked_until' => null, 'ec_current_failed_attempts' => 0,
                'ec_unlock_token' => null,
                'ec_unlock_token_expires' => null
            ],
            [
                'userID' => $customerId
            ]
        );

    }
}