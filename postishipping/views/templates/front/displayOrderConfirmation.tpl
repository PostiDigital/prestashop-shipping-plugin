<div class="card postishipping-order-confirmation">
	<div class="card-block">
		<h3 class="card-title h3">{l s='Selected pickup point' d='Modules.Postishipping.Front'}</h3>
		<p>
			<strong>{$posti_pickup_name|escape:'html':'UTF-8'}</strong><br>
			{$posti_pickup_street|escape:'html':'UTF-8'}<br>
			{$posti_pickup_postcode|escape:'html':'UTF-8'} {$posti_pickup_city|escape:'html':'UTF-8'}
		</p>
	</div>
</div>
