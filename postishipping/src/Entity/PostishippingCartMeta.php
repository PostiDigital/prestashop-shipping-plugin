<?php

namespace Posti\PrestaShop\Postishipping\Entity;

use Carrier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use Posti\PrestaShop\Postishipping\Contract\AdditionalServiceContract;
use Posti\PrestaShop\Postishipping\Exception\PostiBadPickupPointId;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServicePickupPoint;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;
use Posti\PrestaShop\Postishipping\Model\ShippingMethod;
use Posti\PrestaShop\Postishipping\PostiApi;
use ReturnTypeWillChange;
use Validate;

/**
 * @ORM\Table()
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 */
class PostishippingCartMeta implements JsonSerializable
{
    const META_DATA_TABLE = 'postishipping_order_meta';

    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\Column(name="id_cart", type="integer")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="meta_data", type="text")
     */
    private $meta_data;

    private $metaDecoded;

    /** @var ?Posti\PrestaShop\Postishipping\Model\ShippingMethod */
    private $shippingMethod;

    public function __construct()
    {
        //
    }

    /** 
     * Returs cart id that meta belongs to
     */
    public function getId(): int
    {
        return $this->id;
    }

    public function getMeta()
    {
        return $this->metaDecoded ?? [];
    }

    public function isRegistered()
    {
        $registrations = $this->getRegistrations();
        return !empty($registrations);
    }

    /**
     * Returns all registrations as array of ['trackingCode' => ..., 'reference' => ...]
     */
    public function getRegistrations(): array
    {
        $registered = $this->metaDecoded['registered'] ?? [];

        // Backward compatibility: convert old single-registration format to array
        if (isset($registered['trackingCode'])) {
            return [$registered];
        }

        return array_values($registered);
    }

    public function getTrackingCode()
    {
        $registrations = $this->getRegistrations();
        if (empty($registrations)) {
            return '';
        }
        return (string) ($registrations[count($registrations) - 1]['trackingCode'] ?? '');
    }

    public function getRegistrationReference()
    {
        $registrations = $this->getRegistrations();
        if (empty($registrations)) {
            return '';
        }
        return (string) ($registrations[count($registrations) - 1]['reference'] ?? '');
    }

    public function addRegistration(string $trackingCode, string $reference): self
    {
        $registrations = $this->getRegistrations();
        $registrations[] = [
            'trackingCode' => $trackingCode,
            'reference' => $reference,
            'timestamp' => time(),
        ];
        $this->metaDecoded['registered'] = $registrations;
        $this->encodeMeta();

        return $this;
    }

    public function updateRegistrationStatus(string $trackingCode, string $status): self
    {
        $registrations = $this->getRegistrations();
        foreach ($registrations as &$reg) {
            if (($reg['trackingCode'] ?? '') === $trackingCode) {
                $reg['status'] = $status;
                break;
            }
        }
        unset($reg);
        $this->metaDecoded['registered'] = $registrations;
        $this->encodeMeta();

        return $this;
    }

    public function removeRegistration(string $trackingCode): self
    {
        $registrations = $this->getRegistrations();
        $registrations = array_values(array_filter($registrations, function ($reg) use ($trackingCode) {
            return ($reg['trackingCode'] ?? '') !== $trackingCode;
        }));
        $this->metaDecoded['registered'] = $registrations;
        $this->encodeMeta();

        return $this;
    }

    /**
     * Returns all return label registrations
     */
    public function getReturnRegistrations(): array
    {
        return array_values($this->metaDecoded['returnRegistered'] ?? []);
    }

    public function hasReturnRegistrations(): bool
    {
        return !empty($this->getReturnRegistrations());
    }

    public function addReturnRegistration(string $trackingCode, string $reference, string $serviceId): self
    {
        $registrations = $this->getReturnRegistrations();
        $registrations[] = [
            'trackingCode' => $trackingCode,
            'reference' => $reference,
            'serviceId' => $serviceId,
            'timestamp' => time(),
        ];
        $this->metaDecoded['returnRegistered'] = $registrations;
        $this->encodeMeta();

        return $this;
    }

    public function removeReturnRegistration(string $trackingCode): self
    {
        $registrations = $this->getReturnRegistrations();
        $registrations = array_values(array_filter($registrations, function ($reg) use ($trackingCode) {
            return ($reg['trackingCode'] ?? '') !== $trackingCode;
        }));
        $this->metaDecoded['returnRegistered'] = $registrations;
        $this->encodeMeta();

        return $this;
    }

    public function setIdCart($idCart)
    {
        $this->id = $idCart;
        $this->metaDecoded['id_cart'] = $idCart;

        $this->encodeMeta();

        return $this;
    }

    public function setRegistrationReference($reference)
    {
        // Kept for backward compatibility, operates on last registration
        $registrations = $this->getRegistrations();
        if (!empty($registrations)) {
            $registrations[count($registrations) - 1]['reference'] = (string) $reference;
            $this->metaDecoded['registered'] = $registrations;
        } else {
            $this->metaDecoded['registered'] = [['reference' => (string) $reference, 'trackingCode' => '']];
        }
        $this->encodeMeta();

        return $this;
    }

    public function setTrackingCode($trackingCode)
    {
        // Kept for backward compatibility, operates on last registration
        $registrations = $this->getRegistrations();
        if (!empty($registrations)) {
            $registrations[count($registrations) - 1]['trackingCode'] = (string) $trackingCode;
            $this->metaDecoded['registered'] = $registrations;
        } else {
            $this->metaDecoded['registered'] = [['reference' => '', 'trackingCode' => (string) $trackingCode]];
        }
        $this->encodeMeta();

        return $this;
    }

    /**
     * @throws \Posti\PrestaShop\Postishipping\Exception\PostiBadPickupPointId
     */
    public function setPickupPointId($pickup_point_id)
    {
        if (!$pickup_point_id || !ctype_digit($pickup_point_id)) {
            throw new PostiBadPickupPointId("Invalid pickup point ID");
        }

        if (!in_array(AdditionalServicePickupPoint::CODE, $this->getServices())) {
            // Re-add 2106 if it was removed by clearPickupPoint (noPickupPoint flag present)
            if (!empty($this->metaDecoded['noPickupPoint'])) {
                $this->metaDecoded['services'][] = AdditionalServicePickupPoint::CODE;
                // Also restore into defaults
                if (isset($this->metaDecoded['defaultServices']) && !in_array(AdditionalServicePickupPoint::CODE, $this->metaDecoded['defaultServices'])) {
                    $this->metaDecoded['defaultServices'][] = AdditionalServicePickupPoint::CODE;
                }
            } else {
                throw new PostiBadPickupPointId("Pickup points not enabled");
            }
        }

        // Clear no-pickup flag
        unset($this->metaDecoded['noPickupPoint']);

        // Store/update original pickup point (buyer's selection during checkout)
        $this->metaDecoded['originalPickupPointId'] = (int) $pickup_point_id;

        $this->metaDecoded['spec'][AdditionalServicePickupPoint::CODE]['pickup_point_id'] = (int) $pickup_point_id;

        $postiApi = new PostiApi();
        $shippingMethod = $this->getShippingMethod();
        $serviceProvider = ($shippingMethod ? $shippingMethod->getServiceProvider() : null) ?: $this->getPostiServiceId();
        $info = $postiApi->getPickupPointInfo($pickup_point_id, $serviceProvider);
        if (!$info) {
            \PrestaShopLogger::addLog(
                'PostiShipping setPickupPointId failed: pickup_point_id=' . var_export($pickup_point_id, true)
                . ', serviceProvider=' . var_export($serviceProvider, true),
                3, 0, 'PostiShipping', 0, true
            );
            throw new PostiBadPickupPointId("Could not retrieve pickup point information");
        }

        $this->encodeMeta();

        $this->setPickupPointInfo(json_decode($info, true));

        // Store/update original pickup point info (buyer's selection during checkout)
        $this->metaDecoded['originalPickupPointInfo'] = $this->getPickupPointInfo();
        $this->encodeMeta();

        return $this;
    }

    public function setCarrierDefaultData(?PostishippingCarrier $postiCarrier)
    {
        if (is_null($postiCarrier)) {
            return $this;
        }

        // set post service id
        $this->metaDecoded['postiServiceId'] = $postiCarrier->getPostiServiceId();
        $this->metaDecoded['carrierReference'] = $postiCarrier->getCarrierReference();
        // get default services and specifiers
        $this->metaDecoded['services'] = $postiCarrier->getSelectedAdditionalServices();
        $this->metaDecoded['spec'] = $postiCarrier->getSelectedAdditionalServicesSpec();
        $this->metaDecoded['shippingMethod'] = $postiCarrier->getPostiServiceData(false);
        $this->metaDecoded['pickupPointInfo'] = [];
        $this->metaDecoded['registered'] = [];
        unset($this->metaDecoded['originalPickupPointId']);
        unset($this->metaDecoded['originalPickupPointInfo']);

        // Store carrier defaults for "Register" (default) mode
        $this->metaDecoded['defaultPostiServiceId'] = $postiCarrier->getPostiServiceId();
        $this->metaDecoded['defaultServices'] = $postiCarrier->getSelectedAdditionalServices();
        $this->metaDecoded['defaultSpec'] = $postiCarrier->getSelectedAdditionalServicesSpec();
        $this->metaDecoded['defaultShippingMethod'] = $postiCarrier->getPostiServiceData(false);

        $this->encodeMeta();

        return $this;
    }

    public function setPickupPointInfo($data)
    {
        $this->metaDecoded['pickupPointInfo'] = $data;

        $this->encodeMeta();

        return $this;
    }

    /**
     * Clears pickup point selection and removes 2106 additional service.
     * Used when buyer selects "No pickup point: Send to the street address".
     */
    public function clearPickupPoint()
    {
        $filterOut2106 = function ($code) {
            return $code !== AdditionalServicePickupPoint::CODE;
        };

        // Remove 2106 from current services
        $this->metaDecoded['services'] = array_values(array_filter($this->getServices(), $filterOut2106));
        unset($this->metaDecoded['spec'][AdditionalServicePickupPoint::CODE]);

        // Remove 2106 from default services (used by "Register" default mode)
        if (isset($this->metaDecoded['defaultServices'])) {
            $this->metaDecoded['defaultServices'] = array_values(array_filter($this->metaDecoded['defaultServices'], $filterOut2106));
        }
        if (isset($this->metaDecoded['defaultSpec'])) {
            unset($this->metaDecoded['defaultSpec'][AdditionalServicePickupPoint::CODE]);
        }

        $this->metaDecoded['pickupPointInfo'] = [];
        unset($this->metaDecoded['originalPickupPointId']);
        unset($this->metaDecoded['originalPickupPointInfo']);
        $this->metaDecoded['noPickupPoint'] = true;

        $this->encodeMeta();

        return $this;
    }

    public function hasNoPickupPoint(): bool
    {
        return !empty($this->metaDecoded['noPickupPoint']);
    }

    public function getPickupPointInfo()
    {
        return $this->metaDecoded['pickupPointInfo'] ?? [];
    }

    public function getPostiServiceId()
    {
        return $this->metaDecoded['postiServiceId'] ?? null;
    }

    /**
     * Updates the Posti service ID and shipping method data for "Register custom" mode.
     * Fetches new service data from the API so the shipping method / additional services are updated.
     *
     * @return bool true if service was changed
     */
    public function updatePostiService(string $newServiceCode): bool
    {
        $currentCode = $this->getPostiServiceId();
        if ($currentCode === $newServiceCode) {
            return false;
        }

        $this->metaDecoded['postiServiceId'] = $newServiceCode;

        // Try to update the shippingMethod base64 data from the API
        try {
            $postiApi = new PostiApi();
            $methods = $postiApi->getShippingMethods();
            if (isset($methods[$newServiceCode])) {
                $this->metaDecoded['shippingMethod'] = $methods[$newServiceCode]->getRaw()
                    ? base64_encode($methods[$newServiceCode]->getRaw())
                    : $this->metaDecoded['shippingMethod'];
                $this->shippingMethod = null; // reset cached decoded instance
            }
        } catch (\Throwable $th) {
            // If API fails, keep old shipping method data but still update the service ID
        }

        $this->encodeMeta();
        return true;
    }

    /**
     * Returns default (carrier-assigned) service ID for "Register" (default) mode.
     * Falls back to current service ID for orders created before this feature.
     */
    public function getDefaultPostiServiceId()
    {
        return $this->metaDecoded['defaultPostiServiceId'] ?? $this->getPostiServiceId();
    }

    public function getDefaultServices()
    {
        return $this->metaDecoded['defaultServices'] ?? $this->getServices();
    }

    public function getDefaultServicesSpec()
    {
        return $this->metaDecoded['defaultSpec'] ?? $this->getServicesSpec();
    }

    /**
     * Adds a COD (or any) additional service code with specifiers to both
     * regular services and default services.
     */
    public function addAdditionalServiceToAll(string $code, array $specData): self
    {
        // Add to services (custom)
        $services = $this->metaDecoded['services'] ?? [];
        if (!in_array($code, $services)) {
            $services[] = $code;
        }
        $this->metaDecoded['services'] = $services;
        $this->metaDecoded['spec'][$code] = $specData;

        // Add to defaultServices (for "Register" default mode)
        $defaultServices = $this->metaDecoded['defaultServices'] ?? [];
        if (!in_array($code, $defaultServices)) {
            $defaultServices[] = $code;
        }
        $this->metaDecoded['defaultServices'] = $defaultServices;
        $this->metaDecoded['defaultSpec'][$code] = $specData;

        $this->encodeMeta();

        return $this;
    }

    public function getDefaultShippingMethodBase64()
    {
        return $this->metaDecoded['defaultShippingMethod'] ?? $this->metaDecoded['shippingMethod'] ?? null;
    }

    /**
     * Returns buyer's original pickup point ID (from checkout).
     * Falls back to current pickup point ID for backward compatibility.
     */
    public function getOriginalPickupPointId()
    {
        if (isset($this->metaDecoded['originalPickupPointId'])) {
            return $this->metaDecoded['originalPickupPointId'];
        }
        $spec = $this->getServicesSpec();
        return $spec[AdditionalServicePickupPoint::CODE]['pickup_point_id'] ?? null;
    }

    /**
     * Returns buyer's original pickup point info (from checkout).
     * Returns empty array if no original was stored (prevents Custom section data leaking here).
     */
    public function getOriginalPickupPointInfo()
    {
        return $this->metaDecoded['originalPickupPointInfo'] ?? [];
    }

    /**
     * Returns additional services built from carrier defaults, with buyer's original pickup point.
     * Used for "Register" (default) mode.
     *
     * @return AdditionalServiceContract[]
     */
    public function getDefaultAdditionalServices()
    {
        $result = [];
        $services = $this->getDefaultServices();
        $spec = $this->getDefaultServicesSpec();

        // Override pickup point with buyer's original selection
        $originalPpId = $this->getOriginalPickupPointId();
        if ($originalPpId && in_array(AdditionalServicePickupPoint::CODE, $services)) {
            $spec[AdditionalServicePickupPoint::CODE]['pickup_point_id'] = $originalPpId;
        }

        foreach ($services as $code) {
            $specifiersData = $spec[$code] ?? [];
            $specifiers = array_keys($specifiersData);
            $result[] = GenericAdditionalService::getAdditionalServiceByCode((string) $code)
                ->setSpecifiers($specifiers ?: null)
                ->setSpecifiersData($specifiersData);
        }

        return $result;
    }

    public function getServices()
    {
        return $this->metaDecoded['services'] ?? [];
    }

    public function getServicesSpec()
    {
        return $this->metaDecoded['spec'] ?? [];
    }

    /**
     * @return AdditionalServiceContract[]
     */
    public function getAdditionalServices()
    {
        $result = [];
        $spec = $this->getServicesSpec();
        foreach ($this->getServices() as $code) {
            $specifiersData = $spec[$code] ?? [];
            $specifiers = array_keys($specifiersData);
            $result[] = GenericAdditionalService::getAdditionalServiceByCode((string) $code)
                ->setSpecifiers($specifiers ?: null)
                ->setSpecifiersData($specifiersData);
        }

        return $result;
    }

    public function getShippingMethod()
    {
        if (is_null($this->shippingMethod)) {
            $this->decodeShippingMethod();
        }

        return $this->shippingMethod;
    }

    /**
     * Returns associated carrier reference id
     */
    public function getCarrierReferenceId()
    {
        return $this->metaDecoded['carrierReference'] ?? 0;
    }

    /**
     * Return true if something changed, false otherwise
     */
    public function updateMetaServices($selectedServices, $selectedSpec)
    {
        $needsSave = empty($this->metaDecoded['metaSaved']);

        // Always mark meta as user-saved so auto-selections don't override user choices
        $this->metaDecoded['metaSaved'] = true;

        // if no services selected remove any existing
        if (!$selectedServices) {
            $this->metaDecoded['services'] = [];
            $this->metaDecoded['spec'] = [];
            $this->metaDecoded['pickupPointInfo'] = [];
            $this->encodeMeta();

            return true;
        }

        $currentServices = $this->getServices();
        $currentSpec = $this->getServicesSpec();

        $combinedServices = array_unique(array_merge($currentServices, $selectedServices));
        // check if anything changed - combined will have more services if new one were added/removed than $selectedServices
        $servicesChanged = count($combinedServices) !== count($currentServices) || count($combinedServices) !== count($selectedServices);

        $specChanged = false;
        $updatedSpec = [];
        foreach ($selectedSpec as $serviceCode => $spec) {
            // new spec
            if (!isset($currentSpec[$serviceCode])) {
                $updatedSpec[$serviceCode] = $spec;
                $specChanged = true;
                continue;
            }

            foreach ($spec as $specName => $specValue) {
                $updatedSpec[$serviceCode][$specName] = $specValue;

                // if didnt exist or changed value
                if (!isset($currentSpec[$serviceCode][$specName]) || $currentSpec[$serviceCode][$specName] != $specValue) {
                    $specChanged = true;
                }
            }
        }

        // Fetch pickup point info for display without modifying originals
        if (in_array(AdditionalServicePickupPoint::CODE, $selectedServices)) {
            $ppId = $updatedSpec[AdditionalServicePickupPoint::CODE]['pickup_point_id'] ?? null;
            if ($ppId && ctype_digit((string) $ppId)) {
                $postiApi = new PostiApi();
                $shippingMethod = $this->getShippingMethod();
                $serviceProvider = ($shippingMethod ? $shippingMethod->getServiceProvider() : null) ?: $this->getPostiServiceId();
                $info = $postiApi->getPickupPointInfo((string) $ppId, $serviceProvider);
                if ($info) {
                    $this->setPickupPointInfo(json_decode($info, true));
                }
            }
        }

        if ($servicesChanged || $specChanged || $needsSave) {
            $this->metaDecoded['services'] = $selectedServices;
            $this->metaDecoded['spec'] = $updatedSpec;

            $this->encodeMeta();

            return true;
        }

        return false;
    }

    /**
     * @ORM\PostLoad
     */
    public function decodeMeta()
    {
        if (!$this->meta_data) {
            return;
        }

        $this->metaDecoded = json_decode($this->meta_data, true);

        $this->decodeShippingMethod();
    }

    private function decodeShippingMethod()
    {
        if (!isset($this->metaDecoded['shippingMethod'])) {
            return;
        }

        $this->shippingMethod = ShippingMethod::getShippingMethodFromBase64($this->metaDecoded['shippingMethod']);
    }

    private function encodeMeta()
    {
        $this->meta_data = json_encode($this->metaDecoded);
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'id_cart' => $this->getId(),
            'meta' => $this->getMeta(),
            'shippingMethod' => $this->getShippingMethod(),
        ];
    }
}
