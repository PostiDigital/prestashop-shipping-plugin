<?php

namespace Posti\PrestaShop\Postishipping\Model\AdditionalServices;

use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;

class AdditionalServiceOtherPayer extends GenericAdditionalService
{
    const CODE = '3103';

    protected $code = self::CODE;

    protected $specifiers = [
        'contractid'
    ];

    public function isAdditionalServiceCustom(): bool
    {
        return true;
    }
}