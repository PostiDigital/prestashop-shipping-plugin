<div id="postishipping-wrapper-{$postiIdCarrier}" data-postishipping-wrapper>
	<div class="overlay" style="display:none;"><span class="overlay_inner"><i class="material-icons postishipping-spin">sync</i></span></div>
	<div class="col-sm posti-filters mb-1">
		<span class="posti-filter-title text-decoration-underline">{l s='Filter by address' d='Modules.Postishipping.Front'}</span>
		<input type="text" name="postishipping_address" value="" class="posti-filter-input">
		<button type="button" class="posti btn py-0" data-postishipping-target="{$postiIdCarrier}"><span>{l s='Find pickup points' d='Modules.Postishipping.Front'}</span></button>
	</div>
	
	<div class="col-sm postishipping posti-errors mb-1" style="{if !$error}display:none;{/if}"><div class="alert alert-danger"><p class="m-0 alert-danger">{$error|escape:'html':'UTF-8'}</p></div></div>

	<div class="col-sm postishipping-list mb-1" data-postishipping-cid="{$postiIdCarrier}"></div>
</div>