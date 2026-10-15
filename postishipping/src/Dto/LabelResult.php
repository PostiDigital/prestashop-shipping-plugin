<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Dto;

class LabelResult
{
    /** @var string Base64-encoded PDF content */
    private $base64Pdf;

    public function __construct(string $base64Pdf)
    {
        $this->base64Pdf = $base64Pdf;
    }

    public function getBase64Pdf(): string
    {
        return $this->base64Pdf;
    }
}
