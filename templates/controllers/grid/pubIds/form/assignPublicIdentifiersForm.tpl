{**
 * templates/controllers/grid/pubIds/form/assignPublicIdentifiersForm.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 *}
 <script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#assignPublicIdentifierForm').pkpHandler(
			'$.pkp.controllers.form.AjaxFormHandler',
			{ldelim}
				trackFormChanges: true
			{rdelim}
		);
	{rdelim});
</script>		
{if $pubObject instanceof Preprint}
	<form class="pkp_form" id="assignPublicIdentifierForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="tab.issueEntry.IssueEntryTabHandler" op="assignPubIds" escape=false}">
		<input type="hidden" name="submissionId" value="{$pubObject->getId()|escape}" />
		<input type="hidden" name="stageId" value="{$formParams.stageId|escape}" />
		{assign var=hideCancel value=true}
{/if}
{csrf}
{if $confirmationText}
	<p>{$confirmationText}</p>
{/if}
{if $approval}
	{foreach from=$pubIdPlugins item=pubIdPlugin}
		{assign var=pubIdAssignFile value=$pubIdPlugin->getPubIdAssignFile()}
		{assign var=canBeAssigned value=$pubIdPlugin->canBeAssigned($pubObject)}
		{include file="$pubIdAssignFile" pubIdPlugin=$pubIdPlugin pubObject=$pubObject canBeAssigned=$canBeAssigned}
	{/foreach}
{/if}
{fbvFormButtons id="assignPublicIdentifierForm" submitText="common.ok" hideCancel=$hideCancel}
</form>
