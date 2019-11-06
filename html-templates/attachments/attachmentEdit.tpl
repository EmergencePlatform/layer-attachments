{extends designs/site.tpl}

{block title}{if $data->isPhantom}Create{else}Edit{/if} Attachment &middot; {$dwoo.parent}{/block}

{block content}
    {load_templates subtemplates/hhld-forms.tpl}
    {$Attachment = $data}

    <header class="page-header">
        <h2 class="header-title">{if $Attachment->isPhantom}New{else}Edit{/if} Attachment</h2>
        <div class="header-buttons">
            {if !$Attachment->isPhantom}
                <a class="button destructive" href="{$Attachment->getURL('/delete')}">Remove Attachment</a>
            {/if}
        </div>
    </header>

    <p>
        <a href="{$Attachment->getUrl('content')}" target="_blank">
            <img src="{$Attachment->getThumbnailUrl(500)}" alt="{$Attachment->Title|escape}">
        </a>
    </p>

    {$errors = $data->getValidationErrors()}
    {validationErrors $errors}

    <form method="POST" enctype="multipart/form-data" class="show-required">
        <fieldset>
            {field inputName=Title default=$Attachment->Title label="File Title" required=true error=$errors.Title}

            {if $Attachment->Status != 'normal'}
                {selectField
                    inputName='Status'
                    default=$Attachment->Status
                    label='Status'
                    options=$Attachment->getFieldOptions('Status', 'values')
                    useKeyAsValue=no
                    required=true
                    error=$errors.Status
                }
            {/if}
        </fieldset>

        <div class="submit-area">
            <button type="submit">{tif $Activity->isPhantom ? 'Create' : 'Save'}</button>
        </div>
    </form>
{/block}

{block js-bottom}
    {$dwoo.parent}

    {jsmin "pages/activity.js"}
{/block}
