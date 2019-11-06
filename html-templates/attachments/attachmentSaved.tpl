{extends "designs/site.tpl"}

{block "title"}Attachment saved &mdash; {$dwoo.parent}{/block}

{block "content"}
    {$Attachment = $data}

    <p class="lead">Your changed to attachment #{$Attachment->ID} were saved.</p>

    {if $Attachment->Context}
        <p><a href="{$Attachment->Context->getUrl()}">Return to {$Attachment->Context->getTitle()|escape}</a></p>
    {/if}
{/block}