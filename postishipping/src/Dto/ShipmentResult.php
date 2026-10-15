<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class ShipmentResult
{
    /** @var string */
    private $trackingCode;
    /** @var string */
    private $reference;

    public function __construct(string $trackingCode, string $reference)
    {
        $this->trackingCode = $trackingCode;
        $this->reference = $reference;
    }

    public function getTrackingCode(): string
    {
        return $this->trackingCode;
    }

    public function getReference(): string
    {
        return $this->reference;
    }
}
