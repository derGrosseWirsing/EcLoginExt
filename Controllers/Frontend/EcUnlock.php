<?php

declare(strict_types=1);

use EcLoginExt\Services\LoginSecurityService;

/**
 * Shopware Controller for handling account unlock via email token
 * This controller is responsible for processing the unlock request,
 * validating the token, and unlocking the customer account.
 * It uses the LoginSecurityService to perform the unlock operation
 * and interacts with the session to store success or error messages.
 */
class Shopware_Controllers_Frontend_EcUnlock extends Enlight_Controller_Action
{
    private ?Shopware_Components_Snippet_Manager $snippetManager;
    private ?LoginSecurityService $loginSecurityService = null;

    public function preDispatch(): void
    {
        $this->loginSecurityService = $this->get('ec_login_ext.service.login_security');
    }

    /**
     * Handle account unlock via email token
     */
    public function indexAction(): void
    {
        $this->Front()->Plugins()->ViewRenderer()->setNoRender();
        $token = $this->Request()->getParam('token');
        $loginSecurityService = $this->loginSecurityService;
        $session = $this->container->get('session');
        $this->snippetManager = $this->container->get('snippets');
        $snippets = $this->snippetManager->getNamespace('frontend/ec_login_ext');

        /**
         * Check if token is provided
         * If not, redirect to login with error message
         */
        if (empty($token)) {
            /** Store error message in session for cross-controller persistence */
            $session->set('sErrorFlag', ['token' => true]);
            $session->set('sErrorMessages', [$this->getErrorMessage('invalid_token')]);

            $this->redirect([
                'controller' => 'account',
                'action' => 'login'
            ]);

            return;
        }

        $result = $loginSecurityService->unlockWithToken($token);

        /**
         * Check if unlock was successful
         * If not, redirect to login with appropriate error message
         */
        if (!$result['success']) {
            $errorMessage = $this->getErrorMessage($result['error']);

            /** Store error message in session for cross-controller persistence */
            $session->set('sErrorFlag', ['token' => true]);
            $session->set('sErrorMessages', [$errorMessage]);

            $this->redirect([
                'controller' => 'account',
                'action' => 'login'
            ]);

            return;
        }

        /** Success - store success message in session */
        $session->set('sSuccessFlag', true);
        $session->set('sSuccessMessages', [$snippets->get('account/unlocked/success')]);

        $this->redirect([
            'controller' => 'account',
            'action' => 'login'
        ]);
    }

    /**
     * Get user-friendly error message for unlock errors
     */
    private function getErrorMessage(string $errorCode): string
    {
        $snippets = $this->snippetManager->getNamespace('frontend/ec_login_ext');

        return match ($errorCode) {
            'invalid_token' => $snippets->get('account/unlocked/invalid_token'),
            'token_not_found' => $snippets->get('account/unlocked/not_found'),
            'token_expired' => $snippets->get('account/unlocked/expired'),
            default => $snippets->get('account/unlocked/error'),
        };
    }
}