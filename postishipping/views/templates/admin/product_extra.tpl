<div class="panel product-tab posti-product-extra">

    <div class="form-group posti-field">
        <label for="posti_hs_tariff">{l s='HS tariff number' mod='postishipping'}</label>
        <input type="text"
            id="posti_hs_tariff"
            name="posti_hs_tariff"
            value="{$posti_hs_tariff|escape:'html':'UTF-8'}"
            class="form-control" />
        <div class="small">{l s='The HS tariff number must be based on the Harmonized Commodity Description and Coding System developed by the World Customs Organization.' mod='postishipping'}</div>
    </div>

    <div class="form-group posti-field">
        <label for="posti_country_of_origin">{l s='Country of origin' mod='postishipping'}</label>
        <select id="posti_country_of_origin" name="posti_country_of_origin" class="form-control">
            <option value="">--</option>
            {foreach $posti_countries as $country}
                <option value="{$country.iso_code|escape:'html':'UTF-8'}"{if $country.iso_code == $posti_country_of_origin} selected="selected"{/if}>
                    {$country.name|escape:'html':'UTF-8'}
                </option>
            {/foreach}
        </select>
        <div class="small">{l s='The country where the goods originated, e.g. were produced/manufactured or assembled.' mod='postishipping'}</div>
    </div>

    <div class="form-group posti-field">
        <label for="posti_dg_weight">{l s='DG weight (g)' mod='postishipping'}</label>
        <input type="number"
            id="posti_dg_weight"
            name="posti_dg_weight"
            value="{$posti_dg_weight|escape:'html':'UTF-8'}"
            class="form-control"
            min="0"
            step="1" />
        <div class="small">{l s='Amount of hazardous substances in the product as grams.' mod='postishipping'}</div>
    </div>

</div>
