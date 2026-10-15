<?php

namespace Posti\PrestaShop\Postishipping\Model;

use \JsonSerializable;

class ShippingMethod implements JsonSerializable
{
    private $values = [];

    /** json_encoded string of raw shipping method data */
    private $raw;

    public function __set($name, $value)
    {
        $this->values[$name] = $value;
    }

    public function __get($name)
    {
        return $this->values[$name] ?? null;
    }

    #[\ReturnTypeWillChange] 
    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'values' => $this->values
        ];
    }

    /**
     * Given $raw value will be json_encoded and stored as string
     */
    public function setRaw($raw)
    {
        $this->raw = json_encode($raw);

        foreach ($raw as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function getRaw()
    {
        return $this->raw;
    } 

    public function getName()
    {
        $suffix = null;
        if ($this->has_pickup_points ?? false) {
            $suffix = 'Including Pickup Points';
        }

        return $this->service_provider . ': ' . $this->name . ($suffix ? ' (' . $suffix . ')' : '');
    }

    public function getServiceProvider()
    {
        return $this->service_provider;
    }

    public function getAdditionalServices()
    {
        $list = $this->additional_services ?? [];

        if (!$list) {
            return [];
        }

        $result = [];
        foreach ($list as $item) {
            if (!isset($item->service_code)) {
                continue;
            }

            $specifiers = (array) ($item->specifiers ?? []);
            
            // there is a case where specifiers are not passed as array, but single object
            if (isset($specifiers['name'])) {
                $specifiers = [$specifiers['name']];
            } else {
                // here we would still have stdClass object since specifiers was an array of stdClass
                $specifiers = array_map(function($specifier) {
                    return $specifier->name ?? null;
                }, $specifiers);

                $specifiers = array_values(array_filter($specifiers));
            }

            $result[] = GenericAdditionalService::getAdditionalServiceByCode((string) $item->service_code)
                ->setSpecifiers($specifiers);
        }

        return $result;
    }

    /**
     * Returns a map of service_code => specifiers definitions for custom additional services.
     * Used by JS to render specifier fields when API does not provide them.
     */
    public function getCustomSpecifiersMap(): array
    {
        $map = [];
        foreach ($this->getAdditionalServices() as $service) {
            $def = $service->getSpecifiersDefinition();
            if (!empty($def)) {
                $map[$service->getCode()] = $def;
            }
        }
        return $map;
    }

    public function hasPickupPoints()
    {
        return (bool) ($this->has_pickup_points ?? false);
    }

    public function hasHomeDelivery()
    {
        return (bool) ($this->home_delivery ?? false);
    }

    public function getCode()
    {
        return $this->shipping_method_code ?? null;
    }

    /**
     * Returns list of supported countries ISO codes
     */
    public function getSupportedCountries()
    {
        return $this->supported_countries ?? [];
    }

    static function getShippingMethod($raw)
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setRaw($raw);

        return $shippingMethod;
    }

    static function getShippingMethodFromBase64($base64String)
    {
        return static::getShippingMethod(json_decode(base64_decode($base64String)));
    }
}