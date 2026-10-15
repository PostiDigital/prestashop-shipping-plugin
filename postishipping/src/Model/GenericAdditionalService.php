<?php

namespace Posti\PrestaShop\Postishipping\Model;

use JsonSerializable;
use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Exception\PostiInvalidService;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceCod;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceDangerousGoods;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceMultiPackage;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceOtherPayer;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServicePickupPoint;
use Posti\PrestaShop\Postishipping\PostiHelper;
use ReturnTypeWillChange;

class GenericAdditionalService implements AdditionalServiceContract, JsonSerializable
{
    protected $code;

    protected $specifiers;

    protected $specifiersData = [];

    /**
     * Specifier definitions with name and type for UI rendering.
     * Override in custom additional service classes.
     */
    protected $specifiersDefinition = [];

    /**
     * @inheritDoc
     */
    public function setCode(string $code): AdditionalServiceContract
    {
        // do not allow code change for custom additional services
        if ($this->isAdditionalServiceCustom()) {
            return $this;
        }

        // reset specifiers and specifiersData if change detected
        if (!is_null($this->code) && $this->code !== $code) {
            $this->specifiers = null;
            $this->specifiersData = [];
        }

        $this->code = $code;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function isSpecifiersValid(): bool
    {
        // Generic additional service usualy has no specifiers
        if (is_null($this->specifiers)) {
            return true;
        }

        // in case we have generic additional service with specifiers (not yet a separate custom class)
        foreach ($this->specifiers as $specifier) {
            if (!PostiHelper::keyExists($specifier, $this->specifiersData) || empty($this->specifiersData[$specifier])) {
                throw new PostiInvalidService($this->getCode() . ' code must have specifiers: ' . implode(', ', $this->specifiers));
            }
        }

        // If no exceptions trigerred assume additional service is good
        return true;
    }

    /**
     * @inheritDoc
     */
    public function setSpecifiers(?array $specifiers): AdditionalServiceContract
    {
        // do not allow specifiers change for custom additional services
        if ($this->isAdditionalServiceCustom()) {
            return $this;
        }

        $this->specifiers = $specifiers;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setSpecifiersData(array $specifiersData = []): AdditionalServiceContract
    {
        $this->specifiersData = $specifiersData;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isAdditionalServiceCustom(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSpecifiersData(): array
    {
        return $this->specifiersData ?? [];
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'code' => $this->getCode(),
            'specifiers' => $this->specifiers,
            'specifiersData' => $this->specifiersData,
        ];
    }

    /**
     * Return additional service object, either Generic or specific based on given code
     */
    public static function getAdditionalServiceByCode(string $code): AdditionalServiceContract
    {
        switch ($code) {
            case AdditionalServicePickupPoint::CODE:
                return (new AdditionalServicePickupPoint());
            case AdditionalServiceCod::CODE:
                return (new AdditionalServiceCod());
            case AdditionalServiceMultiPackage::CODE:
                return (new AdditionalServiceMultiPackage());
            case AdditionalServiceOtherPayer::CODE:
                return (new AdditionalServiceOtherPayer());
            case AdditionalServiceDangerousGoods::CODE:
                return (new AdditionalServiceDangerousGoods());

            /** Treat as generic additional service */
            default:
                return (new self())->setCode($code);
        }
    }

    /**
     * Returns specifier definitions with name and type for UI rendering
     */
    public function getSpecifiersDefinition(): array
    {
        return $this->specifiersDefinition;
    }

    public function selfString()
    {
        return static::class;
    }
}
