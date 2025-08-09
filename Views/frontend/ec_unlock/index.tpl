{extends file="parent:frontend/account/login.tpl"}

{block name="frontend_index_header_title"}
    {s name="UnlockSuccessMetaTitle" namespace="frontend/plugins/ec_unlock"}Konto Entsperrung - {$Shop->getName()}{/s}
{/block}

{block name='frontend_account_login_error_messages'}
    {$smarty.block.parent}

    {if $success}
        <div class="alert is--success is--rounded">
            <div class="alert--icon">
                <i class="icon--element icon--check"></i>
            </div>
            <div class="alert--content">
                {s name="UnlockSuccessTitle" namespace="frontend/plugins/ec_unlock"}Konto erfolgreich entsperrt{/s}
            </div>
        </div>

    {else}
        <div class="alert is--error is--rounded">
            <div class="alert--icon">
                <i class="icon--element icon--cross"></i>
            </div>
            <div class="alert--content">
                {s name="UnlockErrorTitle" namespace="frontend/plugins/ec_unlock"}Konto konnte nicht entsperrt werden{/s}
            </div>
        </div>

        <div class="account-unlock-error">
            <p class="unlock-error-message">
                {$message}
            </p>

            {if $error === 'token_expired'}
                <div class="unlock-help">
                    <p>{s name="ExpiredTokenHelp" namespace="frontend/hau_unlock"}If your account is still locked, please try logging in again to receive a new unlock email, or contact our customer service.{/s}</p>
                    <a href="{url controller='account' action='login'}" class="btn is--secondary">
                        {s name="BackToLoginButton" namespace="frontend/hau_unlock"}Back to login{/s}
                    </a>
                </div>
            {else}
                <div class="unlock-help">
                    <p>{s name="GeneralErrorHelp" namespace="frontend/hau_unlock"}Please contact our customer service if you need assistance.{/s}</p>
                    <a href="{url controller='index'}" class="btn is--secondary">
                        {s name="BackToHomeButton" namespace="frontend/hau_unlock"}Back to homepage{/s}
                    </a>
                </div>
            {/if}
        </div>
    {/if}
{/block}
