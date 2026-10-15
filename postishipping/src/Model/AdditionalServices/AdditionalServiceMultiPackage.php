<?php

namespace Posti\PrestaShop\Postishipping\Model\AdditionalServices;

use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;

class AdditionalServiceMultiPackage extends GenericAdditionalService
{
    const CODE = '3102';

    protected $code = self::CODE;

    protected $specifiers = [
        'count'
    ];

    public function isAdditionalServiceCustom(): bool
    {
        return true;
    }
}