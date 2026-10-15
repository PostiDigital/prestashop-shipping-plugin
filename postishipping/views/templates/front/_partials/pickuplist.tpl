{if $showType === 'menu'}
    <select class="form-control postishipping-pickup-select" name="selected_pickup_point_{$idReference}" data-postishipping-select>
        {if count($pickupList) > 0}
            <option value="no_pickup" {if $noPickupPointSelected || !$selectedPP}selected="selected"{/if}>{l s='-- No pickup point: Send to the street address --' d='Modules.Postishipping.Front'}</option>
        {/if}
        {if not $foundSelectedInList && $selectedPP}
            <option value="{$selectedPP.pickup_point_id|escape:'html':'UTF-8'}" selected="selected">{$selectedPP.name|escape:'html':'UTF-8'}: {$selectedPP.street_address|escape:'html':'UTF-8'}, {$selectedPP.postcode|escape:'html':'UTF-8'}, {$selectedPP.city|escape:'html':'UTF-8'}</option>
        {/if}
        {foreach $pickupList as $pickup}
            <option value="{$pickup->pickup_point_id|escape:'html':'UTF-8'}" data-point-type="{$pickup->point_type|default:''|escape:'html':'UTF-8'}" {if $selectedPP && $pickup->pickup_point_id == $selectedPP.pickup_point_id}selected="selected"{/if}>{$pickup->name|escape:'html':'UTF-8'}: {$pickup->street_address|escape:'html':'UTF-8'}, {$pickup->postcode|escape:'html':'UTF-8'}, {$pickup->city|escape:'html':'UTF-8'}</option>
        {foreachelse}
            <option value="" disabled>{l s='Unfortunately there are no pickup points available, try searching different address' d='Modules.Postishipping.Front'}</option>
        {/foreach}
    </select>
{else}
    {if count($pickupList) > 0}
        <div class="card postishipping-pickup mb-1 pb-1 pr-1 pl-1 {if $noPickupPointSelected}selected{/if}">
            <div class="posti-radio-wrapper clearfix">
                {$elementId = "posti_no_pickup_`$uniqueIdSuffix`"}
                <input type="radio" name="selected_pickup_point_{$idReference}" id="{$elementId}" class="posti-radio" value="no_pickup" {if $noPickupPointSelected}checked="checked"{/if}/>
                <label for="{$elementId}" class="posti-label">
                    <div>
                        <span class="pickup-address-title">{l s='-- No pickup point: Send to the street address --' d='Modules.Postishipping.Front'}</span>
                    </div>
                </label>
            </div>
        </div>
    {/if}
    {if not $foundSelectedInList && $selectedPP}
        <div class="card postishipping-pickup mb-1 pb-1 pr-1 pl-1 selected">
            <div class="posti-radio-wrapper clearfix">
                {$elementId = "posti_`$selectedPP.pickup_point_id`_`$uniqueIdSuffix`"}
                <input type="radio" name="selected_pickup_point_{$idReference}" id="{$elementId}" class="posti-radio" value="{$selectedPP.pickup_point_id|escape:'html':'UTF-8'}" checked="checked"/>
                <label for="{$elementId}" class="posti-label">
                    <div>
                        <span class="pickup-address-title">{$selectedPP.name|escape:'html':'UTF-8'}:</span>
                        <span class="pickup-address">{$selectedPP.street_address|escape:'html':'UTF-8'}, {$selectedPP.postcode|escape:'html':'UTF-8'}, {$selectedPP.city|escape:'html':'UTF-8'}</span>
                    </div>
                </label>
            </div>
        </div>
    {/if}
    {foreach $pickupList as $pickup}
        <div class="card postishipping-pickup mb-1 pb-1 pr-1 pl-1 {if $selectedPP && $pickup->pickup_point_id == $selectedPP.pickup_point_id}selected{/if}">
            <div class="posti-radio-wrapper clearfix">
                {$elementId = "posti_`$pickup->pickup_point_id`_`$uniqueIdSuffix`"}
                <input type="radio" name="selected_pickup_point_{$idReference}" id="{$elementId}" class="posti-radio" value="{$pickup->pickup_point_id|escape:'html':'UTF-8'}" data-point-type="{$pickup->point_type|default:''|escape:'html':'UTF-8'}" {if $selectedPP && $pickup->pickup_point_id == $selectedPP.pickup_point_id}checked="checked"{/if}/>
                <label for="{$elementId}" class="posti-label">
                    <div>
                        <span class="pickup-address-title">{$pickup->name|escape:'html':'UTF-8'}:</span>
                        <span class="pickup-address">{$pickup->street_address|escape:'html':'UTF-8'}, {$pickup->postcode|escape:'html':'UTF-8'}, {$pickup->city|escape:'html':'UTF-8'}</span>
                    </div>
                </label>
            </div>
        </div>
    {foreachelse}
        <div class="bg-danger p-1">
            <span class="text-white">{l s='Unfortunately there are no pickup points available, try searching different address' d='Modules.Postishipping.Front'}</span>
        </div>
    {/foreach}
{/if}
