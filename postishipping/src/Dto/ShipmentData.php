<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class ShipmentData
{
    /** @var string Shipping service code */
    public $serviceId;
    /** @var SenderData */
    public $sender;
    /** @var ReceiverData */
    public $receiver;
    /** @var ParcelData[] */
    public $parcels = [];
    /** @var AdditionalServiceData[] */
    public $additionalServices = [];
    /** @var string Order reference */
    public $reference = '';
    /** @var string Currency ISO code */
    public $currency = '';
    /** @var string|null Additional info text for label */
    public $additionalInfoText = null;
    /** @var string Language ISO code (2 chars) */
    public $language = 'en';

    public function __construct()
    {
        $this->sender = new SenderData();
        $this->receiver = new ReceiverData();
    }
}
