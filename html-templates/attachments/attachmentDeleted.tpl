{extends "designs/site.tpl"}

{block "title"}Attachment deleted &mdash; {$dwoo.parent}{/block}

{block "content"}
    {$Attachment = $data}

    <p class="lead">Attachment #{$Attachment->ID} deleted.</p>

    {if $Attachment->Context}
        <p><a href="{$Attachment->Context->getUrl()}">Return to {$Attachment->Context->getTitle()|escape}</a></p>
    {/if}
{/block}