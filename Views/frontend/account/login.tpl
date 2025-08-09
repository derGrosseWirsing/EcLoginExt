{extends file='parent:frontend/account/login.tpl'}

{block name='frontend_account_login_error_messages'}
    {$smarty.block.parent}sdfsdfsdfsdfs
{$sSuccessMessages}
    {if $sSuccessMessages}
        {include file="frontend/_includes/messages.tpl" type="success" content=$sSuccessMessages}
{/if}
{/block}
{block name="frontend_index_javascript_async_ready"}

    {$smarty.block.parent}
    <script>
        document.asyncReady(function () {
            StateManager.addPlugin('.counterLocked', 'swCountDown');
        });
    </script>
{/block}