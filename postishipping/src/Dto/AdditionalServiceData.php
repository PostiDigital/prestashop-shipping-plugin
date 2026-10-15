<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class AdditionalServiceData
{
    /** @var string */
    public $code;
    /** @var array<string, string|int|float> */
    public $specifiers = [];

    public function __construct(string $code, array $specifiers = [])
    {
        $this->code = $code;
        $this->specifiers = $specifiers;
    }
}
