<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class ContentLineData
{
    /** @var string */
    public $currency = '';
    /** @var string */
    public $description = '';
    /** @var int */
    public $quantity = 0;
    /** @var string */
    public $countryOfOrigin = '';
    /** @var string */
    public $tariffCode = '';
    /** @var float */
    public $netweight = 0.0;
    /** @var float */
    public $value = 0.0;
}
