<?php

namespace Posti\PrestaShop\Postishipping\Model\AdditionalServices;

use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;

class AdditionalServiceDangerousGoods extends GenericAdditionalService
{
    const CODE = '3143';

    protected $code = self::CODE;

    protected $specifiers = [
        'lqweight',
        'lqcount',
    ];

    protected $specifiersDefinition = [
        ['name' => 'lqweight', 'type' => 'number'],
        ['name' => 'lqcount', 'type' => 'number'],
    ];

    public function isAdditionalServiceCustom(): bool
    {
        return true;
    }
}
