<?php

namespace Posti\PrestaShop\Postishipping\Contract;

interface AdditionalServiceContract
{
    /**
     * Set additional service code, this actions will reset specifiers and specifierData if code changes.
     */
    public function setCode(string $code): AdditionalServiceContract;

    /**
     * Returns additional service code
     */
    public function getCode(): string;

    /**
     * Sets additional service specifiers. Should be in format [specifierName1, specifierName2]
     */
    public function setSpecifiers(?array $specifiers): AdditionalServiceContract;

    /**
     * Sets additional service specifiers data. Should be in format [specifierName => value]
     */
    public function setSpecifiersData(array $specifiersData = []): AdditionalServiceContract;

    /**
     * Checks if set specifiers data has required fields, if missing will throw exception
     * 
     * @throws \Posti\PrestaShop\Postishipping\Exception\PostiInvalidService
     */
    public function isSpecifiersValid(): bool;

    /**
     * Identifies if additional service is custom, generic will always return false
     */
    public function isAdditionalServiceCustom(): bool;

    /**
     * Returns set specifiers data or empty array if nothing found
     */
    public function getSpecifiersData(): array;
}