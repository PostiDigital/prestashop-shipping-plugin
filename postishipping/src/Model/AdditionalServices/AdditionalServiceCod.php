<?php

namespace Posti\PrestaShop\Postishipping\Model\AdditionalServices;

use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;

class AdditionalServiceCod extends GenericAdditionalService
{
    const CODE = '3101';

    protected $code = self::CODE;

    protected $specifiers = [
        'amount',
        'account',
        'reference',
        'codbic',
    ];

    public function isAdditionalServiceCustom(): bool
    {
        return true;
    }
}