<?php

namespace Posti\PrestaShop\Postishipping\Model\AdditionalServices;

use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;

class AdditionalServicePickupPoint extends GenericAdditionalService
{
    const CODE = '2106';

    protected $code = self::CODE;

    protected $specifiers = [
        'pickup_point_id'
    ];

    public function isAdditionalServiceCustom(): bool
    {
        return true;
    }
}