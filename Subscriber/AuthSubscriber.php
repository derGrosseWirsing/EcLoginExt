<?php

declare(strict_types=1);

namespace EcLoginExt\Subscriber;

use Enlight\Event\SubscriberInterface;
use EcLoginExt\Services\LoginSecurityService;
use Enlight_Template_Manager;
use Shopware\Models\Customer\Customer;

/**
 * Authentication Subscriber
 *
 * Handles login-related events including failed/successful logins
 * and account controller actions
 */
class AuthSubscriber implements SubscriberInterface
{

    public function __construct(private LoginSecurityService $loginSecurityService, private Enlight_Template_Manager $templateManager, private string $pluginDirectory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'Enlight_Controller_Action_PreDispatch_Frontend' => 'onPreDispatch',
            'Shopware_Modules_Admin_Login_Failure' => 'onLoginFailed',
            'Shopware_Modules_Admin_Login_Successful' => 'onLoginSuccessful',
            'Enlight_Controller_Action_PostDispatch_Frontend_Register' => 'onAccountAction',
            'Enlight_Controller_Action_PostDispatch_Frontend_Account' => 'onAccountAction',
            'Shopware_Modules_Admin_Login_FilterResult' => 'onLoginFilterResult',
        ];
    }

    public function onPreDispatch(): void
    {
        $this->templateManager->addTemplateDir($this->pluginDirectory . '/Views');
    }

    /**
     * Handle failed authentication
     */
    public function onLoginFailed(\Enlight_Event_EventArgs $args): void
    {
        $email = $args->get('email');

        if (!empty($email)) {
            $result = $this->loginSecurityService->handleFailedLogin($email);

            $session = Shopware()->Session();
            $session->offsetSet('EcSecureLoginResult', $result);
        }
    }

    public function onLoginFilterResult(\Enlight_Event_EventArgs $args): void
    {

        $session = Shopware()->Session();

        if (!$session->get('EcSecureLoginResult')) {
            return;
        }

        $return = $args->getReturn();
        $snippetsManager = Shopware()->Snippets();
        $snippets = $snippetsManager->getNamespace('frontend/ec_login_ext');


        $result = $session->get('EcSecureLoginResult');

        // Not locked yet, show remaining attempts
        if (!$result['locked'] && $result['attempts_remaining']) {
            $remainingAttempts = sprintf($snippets->get('account/login/remaining'), $result['attempts_remaining'], $result['max_attempts']);
            $return[0][] = $remainingAttempts;
            $args->setReturn($return);
            $session->set('EcSecureLoginResult', null);
            return;
        }

        if ($result['locked'] && $result['lock_until']) {

            $then = $result['lock_until'];
            $now = new \DateTime();

            $remaining = $now->diff(new \DateTime($then->format('Y-m-d H:i:s')));

            $lockMessage = sprintf($snippets->get('account/login/locked/until'), $then->format('j.n.Y'), $then->format('H:i'));
            $return[0][] = $lockMessage;
            $return[0][] = sprintf($snippets->get('account/login/locked/counter'),
                $remaining->h, $remaining->i, $remaining->s
            );
            $args->setReturn($return);
            $session->set('EcSecureLoginResult', null);
            return;
        }
    }

    /**
     * Handle successful authentication
     */
    public function onLoginSuccessful(\Enlight_Event_EventArgs $args): void
    {
        $email = $args->get('email');

        if (!empty($email)) {
            $customer = $this->getCustomerByEmail($email);
            if ($customer) {
                $this->loginSecurityService->handleSuccessfulLogin($customer);
            }
        }
    }

    /**
     * Get customer by email helper
     */
    private function getCustomerByEmail(string $email): ?Customer
    {
        $em = Shopware()->Models();
        return $em->getRepository('Shopware\Models\Customer\Customer')
            ->findOneBy(['email' => $email]);
    }

    /**
     * Handle account controller actions to inject security information
     */
    public function onAccountAction(\Enlight_Event_EventArgs $args): void
    {
        $controller = $args->getSubject();
        $view = $controller->View();
        $session = Shopware()->Session();

        // Handle error messages from unlock controller
        if ($session->has('sErrorFlag') && $session->has('sErrorMessages')) {
            $view->assign('sErrorFlag', $session->get('sErrorFlag'));
            $view->assign('sErrorMessages', $session->get('sErrorMessages'));

            // Clear from session after use
            $session->remove('sErrorFlag');
            $session->remove('sErrorMessages');
        }

        if ($session->has('sSuccessFlag') && $session->has('sSuccessMessages')) {
            $view->assign('sSuccessFlag', $session->get('sSuccessFlag'));
            $view->assign('sSuccessMessages', $session->get('sSuccessMessages'));

            // Clear from session after use
            $session->remove('sSuccessFlag');
            $session->remove('sSuccessMessages');
        }

        // Pass login result to template if available
        if ($session->has('EcSecureLoginResult')) {
            $result = $session->get('EcSecureLoginResult');
            $view->assign('EcSecureLoginResult', $result);

            // Clear from session after use
            $session->remove('EcSecureLoginResult');
        }

        // Handle unlock controller success/error messages
        if ($controller->Request()->getActionName() === 'login') {
            // Handle success messages from unlock controller
            if ($session->offsetExists('sSuccessFlag') && $session->offsetExists('sSuccessMessages')) {
                $view->assign('sSuccessFlag', $session->get('sSuccessFlag'));
                $view->assign('sSuccessMessages', $session->get('sSuccessMessages'));
                
                // Clear from session after use
                $session->remove('sSuccessFlag');
                $session->remove('sSuccessMessages');
            }

            // Check if current user is locked
            $userData = $session->get('sUserData');
            if ($userData && isset($userData['additional']['user'])) {
                $customer = Shopware()->Models()->find(
                    'Shopware\Models\Customer\Customer',
                    $userData['additional']['user']['id']
                );

                if ($customer && $this->loginSecurityService->isCustomerLocked($customer)) {
                    $view->assign('EcAccountLocked', true);
                    $view->assign('EcLockUntil', $customer->getLockedUntil());
                }
            }
        }
    }
}