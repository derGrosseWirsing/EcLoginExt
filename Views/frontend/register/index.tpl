{extends file='parent:frontend/register/index.tpl'}


{block name='frontend_register_login_error_messages'}

    {$smarty.block.parent}

    {if $sSuccessMessages}
        {include file="frontend/_includes/messages.tpl" type="success" list=$sSuccessMessages}
    {/if}
{/block}

{block name="frontend_index_javascript_async_ready"}

   {$smarty.block.parent}

    <script>
        document.asyncReady(function() {
            StateManager.addPlugin('.counterLocked','swCountDown');
        });
    </script>

{/block}