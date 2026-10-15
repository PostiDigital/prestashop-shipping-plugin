<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class ParcelData
{
    /** @var float */
    public $weight = 0.0;
    /** @var string */
    public $packageType = 'PC';
    /** @var string|null */
    public $infoCode = null;
    /** @var ContentLineData[] */
    public $contentLines = [];
}
