<?php

declare(strict_types=1);

namespace EcLoginExt\Subscriber;

use DateTime;
use Enlight\Event\SubscriberInterface;
use EcLoginExt\Services\LoginSecurityService;
use Enlight_Components_Session_Namespace;
use Enlight_Event_EventArgs;
use Enlight_Template_Manager;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Customer\Customer;
use Shopware_Components_Snippet_Manager;

/**
 * Authentication Subscriber
 *
 * Event flow:
 *
 * 1. onPreDispatch - Fires first when any frontend controller loads
 * 2. onLoginFailed / onLoginSuccessful - Fires during authentication process
 * 3. onLoginFilterResult - Fires immediately after login events to filter/modify results
 * 4. onLoginAction - Fires during PostDispatch of Account/Register controllers
 *
 */
class AuthSubscriber implements SubscriberInterface
{
    public function __construct(
        private Shopware_Components_Snippet_Manager  $snippetsManager,
        private ModelManager                         $modelManager,
        private LoginSecurityService                 $loginSecurityService,
        private Enlight_Template_Manager             $templateManager,
        private Enlight_Components_Session_Namespace $session,
        private string                               $pluginDirectory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'Enlight_Controller_Action_PreDispatch_Frontend' => 'onPreDispatch',
            'Shopware_Modules_Admin_Login_Failure' => 'onLoginFailed',
            'Shopware_Modules_Admin_Login_Successful' => 'onLoginSuccessful',
            'Enlight_Controller_Action_PostDispatch_Frontend_Register' => 'onLoginAction',
            'Enlight_Controller_Action_PostDispatch_Frontend_Account' => 'onLoginAction',
            'Shopware_Modules_Admin_Login_FilterResult' => 'onLoginFilterResult',
        ];
    }

    public function onPreDispatch(): void
    {
        $this->templateManager->addTemplateDir($this->pluginDirectory . '/Views');
    }

    /**
     * Handle failed authentication
     * Result stored in session
     */
    public function onLoginFailed(Enlight_Event_EventArgs $args): void
    {
        $email = $args->get('email');

        if (!empty($email)) {
            $result = $this->loginSecurityService->handleFailedLogin($email);

            $session = $this->session;
            $session->set('EcSecureLoginResult', $result);
        }
    }

    /**
     * Takes session Data from onLoginFailed and the loginSecurityService
     * and Calculates the remaining Time to return
     * Messages (for the frontend counter)
     */
    public function onLoginFilterResult(Enlight_Event_EventArgs $args): void
    {

        $session = $this->session;

        if (!$session->get('EcSecureLoginResult')) {
            return;
        }

        $return = $args->getReturn();

        $snippets = $this->snippetsManager->getNamespace('frontend/ec_login_ext');

        $result = $session->get('EcSecureLoginResult');

        /** Not locked yet, show remaining attempts */
        if (!$result['locked'] && $result['attempts_remaining']) {
            $remainingText = $snippets->get('account/login/remaining') ?? '';
            $remainingAttempts = sprintf($remainingText, $result['attempts_remaining'], $result['max_attempts']);
            $return[0][] = $remainingAttempts;

            $args->setReturn($return);
            $session->set('EcSecureLoginResult', null);
            return;
        }

        /** Locked: calculate time difference for the frontend counter */
        if ($result['locked'] && $result['lock_until']) {

            $then = $result['lock_until'];
            $now = new DateTime();

            $remaining = $now->diff(new DateTime($then->format('Y-m-d H:i:s')));

            $lockText = $snippets->get('account/login/locked/until') ?? '';
            $lockMessage = sprintf($lockText, $then->format('j.n.Y'), $then->format('H:i'));
            $return[0][] = $lockMessage;

            $counter = $snippets->get('account/login/locked/counter') ?? '';
            $return[0][] = sprintf($counter,
                $remaining->h, $remaining->i, $remaining->s
            );

            $args->setReturn($return);
            $session->set('EcSecureLoginResult', null);
        }
    }

    /**
     * Handle successful authentication
     * Resets failed Attempts if necessary
     */
    public function onLoginSuccessful(Enlight_Event_EventArgs $args): void
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
        $em = $this->modelManager;
        return $em->getRepository('Shopware\Models\Customer\Customer')
            ->findOneBy(['email' => $email]);
    }

    /**
     * Passes Messages from the Unlock Controller to the Frontend View
     * since Counter-Data and lockmessage is being transferred according the
     * shopware way at onLoginFilterResult
     */
    public function onLoginAction(Enlight_Event_EventArgs $args): void
    {
        $controller = $args->getSubject();
        $view = $controller->View();
        $session = $this->session;

        /** Handle error messages from unlock controller */
        if ($session->has('sErrorFlag') && $session->has('sErrorMessages')) {
            $view->assign('sErrorFlag', $session->get('sErrorFlag'));
            $view->assign('sErrorMessages', $session->get('sErrorMessages'));

            /** Clear from session after use */
            $session->remove('sErrorFlag');
            $session->remove('sErrorMessages');
        }

        if ($session->has('sSuccessFlag') && $session->has('sSuccessMessages')) {
            $view->assign('sSuccessFlag', $session->get('sSuccessFlag'));
            $view->assign('sSuccessMessages', $session->get('sSuccessMessages'));

            /** Clear from session after use */
            $session->remove('sSuccessFlag');
            $session->remove('sSuccessMessages');
        }

        /** Pass login result to template if available */
        if ($session->has('EcSecureLoginResult')) {
            $result = $session->get('EcSecureLoginResult');
            $view->assign('EcSecureLoginResult', $result);

            /** Clear from session after use */
            $session->remove('EcSecureLoginResult');
        }
    }
}