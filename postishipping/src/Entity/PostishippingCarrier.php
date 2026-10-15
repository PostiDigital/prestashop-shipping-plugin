<?php

namespace Posti\PrestaShop\Postishipping\Entity;

use Carrier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServicePickupPoint;
use Posti\PrestaShop\Postishipping\Model\ShippingMethod;
use ReturnTypeWillChange;
use Validate;

/**
 * @ORM\Table()
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 */
class PostishippingCarrier implements JsonSerializable
{
    const NAME_MAX_LENGTH = 64;
    const ENTIRE_CATALOG_TYPE = 1;
    const CATEGORIES_TYPE = 2;
    const PRODUCTS_TYPE = 3;

    const DEFAULT_NAME = 'Posti carrier';

    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\Column(name="id_postishipping_carrier", type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var int
     *
     * @ORM\Column(name="id_carrier_reference", type="integer")
     */
    private $id_reference;

    /**
     * @var string
     *
     * @ORM\Column(name="id_posti_service", type="text")
     */
    private $id_posti_service;

    /**
     * @var string
     *
     * @ORM\Column(name="service_data", type="text")
     */
    private $service_data;

    /**
     * @var string
     *
     * @ORM\Column(name="selected_add_services", type="text")
     */
    private $selected_add_services;

    /**
     * @var bool
     *
     * @ORM\Column(name="active", type="boolean")
     */
    private $active = false;

    private $psCarrier;

    private $servicesMeta = [];

    /** @var ?\Posti\PrestaShop\Postishipping\Model\ShippingMethod */
    private $serviceDataDecoded;

    public function __construct()
    {
        //
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setCarrierReference($idReference)
    {
        $this->id_reference = $idReference;

        return $this;
    }

    public function getCarrierReference()
    {
        return $this->id_reference;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function setPostiServiceId($postiServiceId)
    {
        $this->id_posti_service = $postiServiceId;

        return $this;
    }

    public function getPostiServiceId()
    {
        return $this->id_posti_service;
    }

    public function setPostiServiceData($serviceData)
    {
        $this->service_data = $serviceData;

        return $this;
    }

    public function hasPickupPointsEnabled()
    {
        if (!($this->serviceDataDecoded instanceof ShippingMethod)) {
            return false;
        }

        return $this->serviceDataDecoded->hasPickupPoints() && in_array(AdditionalServicePickupPoint::CODE, $this->getSelectedAdditionalServices());
    }

    /**
     * If $decoded is set to TRUE will return ShippingMethod object, otherwise returns base64 encoded string
     * 
     * @return string|\Posti\PrestaShop\Postishipping\Model\ShippingMethod
     */
    public function getPostiServiceData($decoded = false)
    {
        return $decoded ? $this->serviceDataDecoded : $this->service_data;
    }

    public function setSelectedAdditionalServices($services)
    {
        $this->servicesMeta['services'] = $services;

        $this->encodeAdditionalServicesMeta();

        return $this;
    }

    public function setSelectedAdditionalServicesSpec($spec)
    {
        $this->servicesMeta['spec'] = $spec;

        $this->encodeAdditionalServicesMeta();

        return $this;
    }

    public function getSelectedAdditionalServices()
    {
        return $this->servicesMeta['services'] ?? [];
    }

    public function getSelectedAdditionalServicesSpec()
    {
        return $this->servicesMeta['spec'] ?? [];
    }

    public function getPsCarrier($refresh = false)
    {
        if (!$refresh && !is_null($this->psCarrier)) {
            return $this->psCarrier;
        }

        $this->psCarrier = Carrier::getCarrierByReference($this->id_reference);

        return $this->psCarrier;
    }

    /**
     * Encoder, used to update selected_add_services attribute so doctrine knows it needs to be updated
     */
    private function encodeAdditionalServicesMeta()
    {
        $this->selected_add_services = json_encode($this->servicesMeta);
    }

    /**
     * @ORM\PostLoad
     */
    public function decodeAdditionalServicesMeta()
    {
        if (!$this->selected_add_services) {
            return;
        }

        $this->servicesMeta = json_decode($this->selected_add_services, true);
    }

    /**
     * @ORM\PostLoad
     */
    public function decodeServiceData(): void
    {
        if (!$this->service_data) {
            return;
        }

        $this->serviceDataDecoded = ShippingMethod::getShippingMethodFromBase64($this->service_data);
    }


    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'id_refernce' => $this->getCarrierReference(),
            'id_posti_service' => $this->getPostiServiceId(),
            'selected_add_services' => $this->getSelectedAdditionalServices(),
            'selected_add_services_spec' => $this->getSelectedAdditionalServicesSpec(),
            'ShippingMethod' => $this->getPostiServiceData(true),
        ];
    }
}
