<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class PickupOrderData
{
    /** @var string Pickup URL override (optional) */
    public $pickupUrl = '';
    /** @var string YYYY-MM-DD */
    public $date = '';
    /** @var string HH:MM */
    public $timeFrom = '';
    /** @var string HH:MM */
    public $timeTo = '';
    /** @var string */
    public $additionalInfo = '';
    /** @var string Sender ID for POSTRA XML */
    public $senderId = '';
    /** @var string SAP Customer ID */
    public $customerId = '';
    /** @var string SAP Invoice ID */
    public $invoiceId = '';
    /** @var string */
    public $senderName = '';
    /** @var string */
    public $senderAddress = '';
    /** @var string */
    public $senderPostcode = '';
    /** @var string */
    public $senderCity = '';
    /** @var string */
    public $senderCountry = '';
    /** @var array Array of ['trackingCode' => string] */
    public $labels = [];
}
