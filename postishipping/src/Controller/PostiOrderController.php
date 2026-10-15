<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Controller;

use Configuration;
use Context;
use Posti\PrestaShop\Postishipping\Dto\AdditionalServiceData;
use Posti\PrestaShop\Postishipping\Dto\ContentLineData;
use Posti\PrestaShop\Postishipping\Dto\ParcelData;
use Posti\PrestaShop\Postishipping\Dto\ShipmentData;
use Posti\PrestaShop\Postishipping\Exception\PostiGenericException;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceCod;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceDangerousGoods;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceMultiPackage;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServicePickupPoint;
use Posti\PrestaShop\Postishipping\Model\GenericAdditionalService;
use Posti\PrestaShop\Postishipping\Model\ShippingServiceConfig;
use Posti\PrestaShop\Postishipping\PostiApi;
use Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository;
use Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository;
use Posti\PrestaShop\Postishipping\Repository\PostishippingManifestRepository;
use PostiShipping;
use Posti\PrestaShop\Postishipping\Compat\AdminControllerCompat;
use Profile;
use Tab;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PostiOrderController extends AdminControllerCompat
{
    public const CONTROLLER_NAME = 'PostishippingPostiOrderController';

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            PostishippingCartMetaRepository::class => PostishippingCartMetaRepository::class,
            PostishippingManifestRepository::class => PostishippingManifestRepository::class,
            'doctrine.orm.entity_manager' => '?Doctrine\ORM\EntityManagerInterface',
        ]);
    }

    private function getModule(): PostiShipping
    {
        $module = \Module::getInstanceByName('postishipping');
        if (!$module instanceof PostiShipping) {
            throw new PostiGenericException('Failed to load PostiShipping module');
        }
        return $module;
    }

    private function getMetaRepository(): PostishippingCartMetaRepository
    {
        return $this->container->get(PostishippingCartMetaRepository::class);
    }

    public static function getEmployeeAccess(): array
    {
        $allTrue = ['view' => true, 'add' => true, 'edit' => true, 'delete' => true];
        $allFalse = ['view' => false, 'add' => false, 'edit' => false, 'delete' => false];

        try {
            $employee = Context::getContext()->employee;
            if (!$employee || !$employee->id) {
                return $allFalse;
            }

            if ($employee->isSuperAdmin()) {
                return $allTrue;
            }

            $tabId = Tab::getIdFromClassName(self::CONTROLLER_NAME);
            if (!$tabId) {
                // Tab not yet created — allow view by default, deny write actions
                return ['view' => true, 'add' => false, 'edit' => false, 'delete' => false];
            }

            $access = Profile::getProfileAccess($employee->id_profile, $tabId);

            return [
                'view' => (bool) ($access['view'] ?? false),
                'add' => (bool) ($access['add'] ?? false),
                'edit' => (bool) ($access['edit'] ?? false),
                'delete' => (bool) ($access['delete'] ?? false),
            ];
        } catch (\Throwable $e) {
            return $allTrue;
        }
    }

    private function denyAccess(string $action): JsonResponse
    {
        return $this->json([
            'error' => $this->trans('You do not have permission to perform this action.', 'Admin.Notifications.Error'),
            'content' => [],
            'action' => $action,
        ], 403);
    }

    /**
     * Returns a JsonResponse that includes fresh block HTML + JS state for AJAX refresh.
     * When $orderId is provided and the action succeeded (no error), the response
     * will contain blockHtml and blockState so the JS can update the DOM without a page reload.
     */
    private function jsonWithRefresh(array $data, $orderId = null): JsonResponse
    {
        if ($orderId && empty($data['error'])) {
            try {
                $module = $this->getModule();
                $blockData = $module->getPostiOrderBlockData((int) $orderId);
                if (!empty($blockData)) {
                    $data['blockHtml'] = $blockData['html'];
                    $data['blockState'] = $blockData['state'];
                }
            } catch (\Throwable $e) {
                // Block refresh failure should not break the main action
            }
        }
        return $this->json($data);
    }

    /**
     * Posti Order meta AJAX handler
     *
     * @return Response
     */
    public function indexAction(
        Request $request
    ): JsonResponse {
        $module = $this->getModule();
        $metaRepository = $this->getMetaRepository();
        $response = [];
        $error = '';

        $action = $request->isMethod('POST')
            ? $request->request->get('action', '')
            : $request->query->get('action', '');
        $access = self::getEmployeeAccess();

        // Write actions require specific permissions
        $addActions = ['registerLabel', 'registerCustomLabel', 'registerReturnLabel', 'toPickup'];
        $editActions = ['saveMeta'];
        $deleteActions = ['deleteLabel', 'deleteReturnLabel'];

        if (in_array($action, $addActions, true) && !$access['add']) {
            return $this->denyAccess($action);
        }
        if (in_array($action, $editActions, true) && !$access['edit']) {
            return $this->denyAccess($action);
        }
        if (in_array($action, $deleteActions, true) && !$access['delete']) {
            return $this->denyAccess($action);
        }

        if ('searchPP' === $action) {
            $cartId = $request->query->get('id_cart', '');
            $provider = $request->query->get('provider', '');
            $address = $request->query->get('address', '');

            try {
                $response = $module->getPickupPoints((int) $cartId, $provider, $address);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to get Pickup Points', 'Modules.Postishipping.Admin');
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        if ('saveMeta' === $action) {
            $orderId = $request->request->get('id_order', 0);
            try {
                $response = $this->saveMeta($request, $metaRepository);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to save order data', 'Modules.Postishipping.Admin');
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('registerLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            try {
                $customProducts = $request->request->all()['posti_custom_product'] ?? [];
                $productQuantities = [];
                if (is_array($customProducts)) {
                    foreach ($customProducts as $key => $qty) {
                        $qty = (int) $qty;
                        if ($qty > 0) {
                            $productQuantities[$key] = $qty;
                        }
                    }
                }

                if (!empty($customProducts) && empty($productQuantities)) {
                    throw new PostiGenericException($this->trans('Shipment must contain at least one product unit', 'Modules.Postishipping.Admin'));
                }

                $defaultParams = !empty($productQuantities) ? ['customProducts' => $productQuantities] : [];
                $response = $this->registerLabel($orderId, $metaRepository, $module, $defaultParams);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying register: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('registerCustomLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            try {
                $customServiceCode = $request->request->get('posti_service_code', null);
                $selectedAddServices = $request->request->all()['posti_additional_service'] ?? [];
                $selectedSpec = [];
                foreach ($request->request->all()['posti_additional_service_spec'] ?? [] as $addServiceId => $specData) {
                    if (in_array($addServiceId, $selectedAddServices)) {
                        $selectedSpec[$addServiceId] = $specData;
                    }
                }

                $customAdditionalServices = [];
                foreach ($selectedAddServices as $code) {
                    $specData = $selectedSpec[$code] ?? [];
                    $specifiers = array_keys($specData);
                    $customAdditionalServices[] = GenericAdditionalService::getAdditionalServiceByCode((string) $code)
                        ->setSpecifiers($specifiers ?: null)
                        ->setSpecifiersData($specData);
                }

                $customProducts = $request->request->all()['posti_custom_product'] ?? [];
                $customProductQuantities = [];
                if (is_array($customProducts)) {
                    foreach ($customProducts as $key => $qty) {
                        $qty = (int) $qty;
                        if ($qty > 0) {
                            $customProductQuantities[$key] = $qty;
                        }
                    }
                }

                if (!empty($customProducts) && empty($customProductQuantities)) {
                    throw new PostiGenericException($this->trans('Shipment must contain at least one product unit', 'Modules.Postishipping.Admin'));
                }

                $response = $this->registerLabel($orderId, $metaRepository, $module, [
                    'serviceId' => $customServiceCode,
                    'additionalServices' => $customAdditionalServices,
                    'customProducts' => $customProductQuantities,
                ]);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying register: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('getAvailableServices' === $action) {
            try {
                $postiApi = new PostiApi();
                $methods = $postiApi->getShippingMethods();
                $response = [];
                foreach ($methods as $method) {
                    $raw = json_decode($method->getRaw());
                    $response[] = [
                        'shipping_method_code' => $method->getCode(),
                        'service_provider' => $method->getServiceProvider(),
                        'name' => $method->name,
                        'has_pickup_points' => $method->hasPickupPoints(),
                        'additional_services' => json_decode(json_encode($raw->additional_services ?? ($raw->AdditionalService ?? [])), true),
                        'home_delivery' => $method->hasHomeDelivery(),
                    ];
                }
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Failed to load available services', 'Modules.Postishipping.Admin');
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        if ('printLabel' === $action) {
            $trackingCode = $request->request->get('tracking_code', null);
            try {
                $response = $this->printLabels($trackingCode);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to print label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        if ('deleteLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            $trackingCode = $request->request->get('tracking_code', '');
            try {
                $response = $this->deleteLabel($orderId, $trackingCode, $metaRepository);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to delete label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('registerReturnLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            try {
                $response = $this->registerReturnLabel($orderId, $metaRepository, $module);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to register return label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('deleteReturnLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            $trackingCode = $request->request->get('tracking_code', '');
            try {
                $response = $this->deleteReturnLabel($orderId, $trackingCode, $metaRepository);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to delete return label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->jsonWithRefresh([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ], $orderId);
        }

        if ('refreshLabel' === $action) {
            $orderId = $request->request->get('id_order', 0);
            $trackingCode = $request->request->get('tracking_code', '');
            try {
                $response = $this->refreshLabel($orderId, $trackingCode, $metaRepository);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to refresh label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        if ('toPickup' === $action) {
            $orderId = (int) $request->request->get('id_order', 0);
            $trackingCode = $request->request->get('tracking_code', '');
            try {
                $response = $this->addToPickup($orderId, $trackingCode);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while trying to add to pickup: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        return $this->json([
            'error' => $this->trans('Unknown module action', 'Modules.Postishipping.Admin'),
            'content' => $response,
            'action' => $action,
        ]);
    }

    private function saveMeta(Request $request, PostishippingCartMetaRepository $metaRepository)
    {
        // manage additional services selection and default values
        $selectedAddServices = $request->request->all()['posti_additional_service'] ?? [];
        $selectedSpec = [];
        foreach ($request->request->all()['posti_additional_service_spec'] ?? [] as $addServiceId => $specData) {
            if (in_array($addServiceId, $selectedAddServices)) {
                $selectedSpec[$addServiceId] = $specData;
            }
        }

        $cartMeta = $metaRepository->find((int) $request->request->get('meta_id', 0));

        // Check if service code was changed in the custom section
        $newServiceCode = $request->request->get('posti_service_code', null);
        $serviceChanged = false;
        if ($newServiceCode && $newServiceCode !== $cartMeta->getPostiServiceId()) {
            $serviceChanged = $cartMeta->updatePostiService($newServiceCode);
        }

        $metaChanged = $cartMeta->updateMetaServices($selectedAddServices, $selectedSpec);

        if ($metaChanged || $serviceChanged) {
            $metaRepository->updateGeneral($cartMeta);

            return [
                'message' => $this->trans('Changes saved', 'Modules.Postishipping.Admin'),
                'changed' => true,
            ];
        }

        return [
            'message' => $this->trans('No changes found', 'Modules.Postishipping.Admin'),
            'changed' => false,
        ];
        // return [
        //     'old_posti_additional_service' => $cartMeta->getServices(),
        //     'old_posti_additional_service_spec' => $cartMeta->getServicesSpec(),
        //     'posti_additional_service' => $selectedAddServices,
        //     'posti_additional_service_spec' => $selectedSpec,
        //     'old_meta' => $cartMeta,
        //     'new_meta' => $metaChanged,
        // ];
    }

    /**
     * Registers shipment
     *
     * @param array $customParams Optional overrides: ['serviceId' => ..., 'additionalServices' => [...]]
     *                            When empty, uses carrier defaults ("Register" mode).
     *                            When provided, uses given values ("Register custom" mode).
     * 
     * @throws \Exception
     */
    public function registerLabel($idOrder, PostishippingCartMetaRepository $metaRepository = null, PostiShipping $module = null, array $customParams = [])
    {
        $module = $module ?? $this->getModule();
        $metaRepository = $metaRepository ?? $this->getMetaRepository();
        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta = $metaRepository->find((int) $order->id_cart);

        $context = Context::getContext();

        // Language: use employee/admin language or default
        $languageIso = $context->language ? $context->language->iso_code : 'en';
        $languageIso = substr($languageIso, 0, 2);

        $useCustom = !empty($customParams['serviceId']) || !empty($customParams['additionalServices']);

        if ($useCustom) {
            // "Register custom" mode: use provided overrides
            $serviceId = $customParams['serviceId'] ?? $cartMeta->getPostiServiceId();
            $additionalServicesForShipment = $customParams['additionalServices'] ?? $cartMeta->getAdditionalServices();
        } else {
            // "Register" default mode: use carrier defaults + buyer's original pickup point
            $serviceId = $cartMeta->getDefaultPostiServiceId();
            $additionalServicesForShipment = $cartMeta->getDefaultAdditionalServices();
        }

        // Fetch supported additional service codes for the selected shipping service
        $supportedAdditionalServiceCodes = [];
        try {
            $postiApiForMethods = new PostiApi();
            $allMethods = $postiApiForMethods->getShippingMethods();
            if (isset($allMethods[$serviceId])) {
                foreach ($allMethods[$serviceId]->getAdditionalServices() as $supportedSvc) {
                    $supportedAdditionalServiceCodes[] = $supportedSvc->getCode();
                }
            }
        } catch (\Throwable $e) {
            // If API call fails, skip filtering to avoid blocking registration
        }

        // Filter additional services: only keep those supported by the selected shipping service
        if (!empty($supportedAdditionalServiceCodes)) {
            $additionalServicesForShipment = array_filter(
                $additionalServicesForShipment,
                function ($svc) use ($supportedAdditionalServiceCodes) {
                    return in_array($svc->getCode(), $supportedAdditionalServiceCodes, true);
                }
            );
            $additionalServicesForShipment = array_values($additionalServicesForShipment);
        }

        $shipmentData = new ShipmentData();
        $shipmentData->serviceId = $serviceId;
        $shipmentData->language = $languageIso;

        // Addresses
        $deliveryAddress = new \Address((int) $order->id_address_delivery);
        $invoiceAddress  = new \Address((int) $order->id_address_invoice);
        $customer = new \Customer((int) $order->id_customer);

        // Sender
        $shipmentData->sender->name1 = Configuration::get('POSTISHIPPING_SENDER_NAME');
        $shipmentData->sender->addr1 = Configuration::get('POSTISHIPPING_SENDER_ADDRESS');
        $shipmentData->sender->postcode = Configuration::get('POSTISHIPPING_SENDER_POSTAL_CODE');
        $shipmentData->sender->city = Configuration::get('POSTISHIPPING_SENDER_CITY');
        $shipmentData->sender->phone = Configuration::get('POSTISHIPPING_SENDER_PHONE');
        $shipmentData->sender->country = Configuration::get('POSTISHIPPING_SENDER_COUNTRY');
        $shipmentData->sender->email = Configuration::get('POSTISHIPPING_SENDER_EMAIL');

        // Receiver
        $company = $deliveryAddress->company;
        $name = trim($deliveryAddress->firstname . ' ' . $deliveryAddress->lastname);

        if (!empty($company)) {
            $shipmentData->receiver->name1 = $company;
            $shipmentData->receiver->name2 = $name;
        } else {
            $shipmentData->receiver->name1 = $name;
        }

        $shipmentData->receiver->addr1 = $deliveryAddress->address1;
        $shipmentData->receiver->addr2 = $deliveryAddress->address2;
        $shipmentData->receiver->postcode = $deliveryAddress->postcode;
        $shipmentData->receiver->city = $deliveryAddress->city;

        $receiverCountry = $deliveryAddress->id_country ? \Country::getIsoById($deliveryAddress->id_country) : null;
        if (!$receiverCountry && $invoiceAddress->id_country) {
            $receiverCountry = \Country::getIsoById($invoiceAddress->id_country);
        }
        if (!$receiverCountry) {
            $receiverCountry = $shipmentData->sender->country;
        }
        $shipmentData->receiver->country = $receiverCountry ?: 'FI';

        // Email/phone: use delivery if available, fallback to customer
        $shippingEmail = $customer->email;
        $shippingPhone = $deliveryAddress->phone_mobile ?: ($deliveryAddress->phone ?: ($invoiceAddress->phone_mobile ?: $invoiceAddress->phone));

        $shipmentData->receiver->email = $shippingEmail;
        $shipmentData->receiver->phone = $shippingPhone;

        // Additional services
        $parcelTotalCount = 1;
        foreach ($additionalServicesForShipment as $metaAdditionalService) {
            // if COD, update specifiers account and codbic if needed
            if ($metaAdditionalService->getCode() === AdditionalServiceCod::CODE) {
                $currentSpecifiersData = $metaAdditionalService->getSpecifiersData();

                if (!($currentSpecifiersData['account'] ?? null)) {
                    $currentSpecifiersData['account'] = Configuration::get('POSTISHIPPING_COD_ACCOUNT', null, null, null, '');
                }

                if (!($currentSpecifiersData['codbic'] ?? null)) {
                    $currentSpecifiersData['codbic'] = Configuration::get('POSTISHIPPING_COD_CODBIC', null, null, null, '');
                }

                $metaAdditionalService->setSpecifiersData($currentSpecifiersData);
            }

            // check if additional service is valid, will throw exception if invalid
            $metaAdditionalService->isSpecifiersValid();

            $shipmentData->additionalServices[] = new AdditionalServiceData(
                $metaAdditionalService->getCode(),
                $metaAdditionalService->getSpecifiersData()
            );

            if ($metaAdditionalService->getCode() === AdditionalServiceMultiPackage::CODE) {
                $parcelTotalCount = (int) ($metaAdditionalService->getSpecifiersData()['count'] ?? 1);
            }
        }

        // Products data (shared for weight, content lines, DG, and additional info)
        $orderProducts = $order->getProducts();
        $customProductQuantities = $customParams['customProducts'] ?? [];

        // When custom product selection is provided, filter and adjust quantities
        if (!empty($customProductQuantities)) {
            $filteredProducts = [];
            foreach ($orderProducts as $row) {
                $key = (int) $row['product_id'] . '_' . (int) ($row['product_attribute_id'] ?? 0);
                if (isset($customProductQuantities[$key]) && $customProductQuantities[$key] > 0) {
                    $row['product_quantity'] = min((int) $customProductQuantities[$key], (int) $row['product_quantity']);
                    $filteredProducts[] = $row;
                }
            }
            $orderProducts = $filteredProducts;
        }

        $ignoreWeight = (bool) \Configuration::get('POSTISHIPPING_IGNORE_PRODUCT_WEIGHT');
        $excludeNoHsCode = (bool) \Configuration::get('POSTISHIPPING_EXCLUDE_NO_HS_CODE');
        $currencyIso = \Currency::getIsoCodeById((int) $order->id_currency);
        $shipmentData->currency = $currencyIso;

        // Weight
        if ($ignoreWeight) {
            $orderTotalWeight = 0;
            foreach ($orderProducts as $p) {
                $orderTotalWeight += (int) $p['product_quantity'];
            }
        } elseif (!empty($customProductQuantities)) {
            // Recalculate weight from filtered products
            $orderTotalWeight = 0;
            foreach ($orderProducts as $p) {
                $orderTotalWeight += (float) ($p['weight'] ?? 0) * (int) $p['product_quantity'];
            }
        } else {
            $orderTotalWeight = $order->getTotalWeight();
        }

        $packageType = 'PC';
        if (ShippingServiceConfig::isExpressFreight($serviceId)) {
            $packageType = Configuration::get('POSTISHIPPING_PALLET_TYPE') ?: ShippingServiceConfig::DEFAULT_PALLET_TYPE;
        }

        $infoCode = mb_substr((string) Configuration::get('POSTISHIPPING_SENDER_INFO_CODE'), 0, 15);

        // Parcels
        $weightPerParcel = round($orderTotalWeight / max(1, $parcelTotalCount), 2);
        $contentLinesForFirstParcel = [];

        // Items
        $productsInfo = [];
        $dgTotalWeight = 0;
        $dgProductCount = 0;
        $db = \Db::getInstance();

        if (!empty($orderProducts)) {
            foreach ($orderProducts as $row) {
                $idProduct = (int) $row['product_id'];

                $product = new \Product($idProduct, false, $context->language->id);
                if (!\Validate::isLoadedObject($product) || $product->is_virtual) {
                    continue;
                }

                $quantity = (int) $row['product_quantity'];

                $productExtra = $db->getRow(
                    'SELECT hs_tariff, country_of_origin, dg_weight FROM `' . _DB_PREFIX_ . 'postishipping_product_extra` WHERE `id_product` = ' . (int) $idProduct
                );
                $tariffCode = $productExtra['hs_tariff'] ?? '';
                $countryOfOrigin = $productExtra['country_of_origin'] ?? '';
                $dgWeight = (int) ($productExtra['dg_weight'] ?? 0);

                if ($dgWeight > 0) {
                    $dgTotalWeight += $dgWeight * $quantity;
                    $dgProductCount += $quantity;
                }

                $productsInfo[] = [
                    'name' => $product->name,
                    'sku'  => $product->reference,
                    'qty'  => $quantity,
                ];

                if ($excludeNoHsCode && empty($tariffCode)) {
                    continue;
                }

                $contentLine = new ContentLineData();
                $contentLine->currency = $currencyIso;
                $contentLine->description = $product->name;
                $contentLine->quantity = $quantity;
                $contentLine->countryOfOrigin = $countryOfOrigin;
                $contentLine->tariffCode = $tariffCode;

                if (!empty($product->weight)) {
                    $contentLine->netweight = (float) $product->weight * 1000 * $quantity;
                }

                $contentLine->value = round((float) $row['total_price_tax_incl'], 2);

                $contentLinesForFirstParcel[] = $contentLine;
            }
        }

        for ($i = 0; $i < $parcelTotalCount; $i++) {
            $parcelData = new ParcelData();
            $parcelData->weight = $weightPerParcel;
            $parcelData->packageType = $packageType;
            $parcelData->infoCode = $infoCode ?: null;
            if ($i === 0) {
                $parcelData->contentLines = $contentLinesForFirstParcel;
            }
            $shipmentData->parcels[] = $parcelData;
        }

        // Auto-add dangerous goods (3143) for default mode when DG products found
        $hasDgInServices = false;
        foreach ($additionalServicesForShipment as $metaSvc) {
            if ($metaSvc->getCode() === AdditionalServiceDangerousGoods::CODE) {
                $hasDgInServices = true;
                break;
            }
        }

        $serviceSupportsDg = empty($supportedAdditionalServiceCodes) || in_array(AdditionalServiceDangerousGoods::CODE, $supportedAdditionalServiceCodes, true);

        if (!$useCustom && $dgTotalWeight > 0 && !$hasDgInServices && $serviceSupportsDg) {
            $shipmentData->additionalServices[] = new AdditionalServiceData(
                AdditionalServiceDangerousGoods::CODE,
                [
                    'lqweight' => round($dgTotalWeight / 1000, 3), // convert grams to kg
                    'lqcount' => $dgProductCount,
                ]
            );
        }

        // Info
        $shipmentData->reference = $order->reference;

        $additionalText = Configuration::get('POSTISHIPPING_LABEL_ADDITIONAL_INFO') ?: '';

        if (!empty($additionalText)) {
            $orderNote = '';
            $customerMessages = \CustomerMessage::getMessagesByOrderId((int) $order->id, true);
            if (!empty($customerMessages)) {
                $lastMessage = end($customerMessages);
                $orderNote = $lastMessage['message'] ?? '';
            }

            $shipmentData->additionalInfoText = $this->prepareAdditionalInfoText([
                'order_id'     => (string) $order->id,
                'order_number' => $order->reference,
                'order_note'   => $orderNote,
                'products'     => $productsInfo,
            ], $additionalText);
        }

        $postiApi = new PostiApi();

        try {
            $result = $postiApi->registerShipment($shipmentData);
        } catch (\Exception $e) {
            throw new PostiGenericException($this->trans('Failed to register: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $e->getMessage()]));
        }

        $reference = $result->getReference();
        $tracking = $result->getTrackingCode();

        if ($tracking) {
            $cartMeta->addRegistration($tracking, $reference);
            $order->setWsShippingNumber($tracking);

            $registeredState = (int) Configuration::get('POSTISHIPPING_STATE_REGISTERED');
            if ($registeredState > 0) {
                $module->changeOrderStatus($order, $registeredState);
            }

            $metaRepository->updateGeneral($cartMeta);
        }

        return [
            'message' => $this->trans(
                'Registered! Reference: %reference% | Tracking code: %tracking%',
                'Modules.Postishipping.Admin',
                [
                    '%reference%' => $reference,
                    '%tracking%' => '<b>' . $tracking . '</b>',
                ]
            ),
            'reference' => $reference,
            'trackingCode' => $tracking,
        ];
    }

    public function printLabels($trackingCodes)
    {
        if (!$trackingCodes) {
            throw new PostiGenericException($this->trans('Missing tracking code', 'Modules.Postishipping.Admin'));
        }

        if (!is_array($trackingCodes)) {
            $trackingCodes = [$trackingCodes];
        }

        $postiApi = new PostiApi();
        $labelsSize = \Configuration::get('POSTISHIPPING_LABEL_SIZE') ?: null;

        try {
            $labelResult = $postiApi->fetchLabels($trackingCodes, $labelsSize);
        } catch (\Exception $e) {
            throw new PostiGenericException($this->trans('Failed to get labels: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $e->getMessage()]));
        }

        return [
            'message' => 'Retrieved labels',
            'base64' => $labelResult->getBase64Pdf(),
        ];
    }

    /**
     * Deletes a single registration by tracking code
     */
    private function deleteLabel($idOrder, $trackingCode, PostishippingCartMetaRepository $metaRepository)
    {
        if (!$trackingCode) {
            throw new PostiGenericException($this->trans('Missing tracking code', 'Modules.Postishipping.Admin'));
        }

        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta = $metaRepository->find((int) $order->id_cart);
        if (!$cartMeta) {
            throw new PostiGenericException($this->trans('Cart meta not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta->removeRegistration((string) $trackingCode);

        // Update order tracking to latest remaining registration or clear
        $remaining = $cartMeta->getRegistrations();
        if (!empty($remaining)) {
            $last = end($remaining);
            $order->setWsShippingNumber($last['trackingCode'] ?? '');
        } else {
            $order->setWsShippingNumber('');
        }

        $metaRepository->updateGeneral($cartMeta);

        return [
            'message' => $this->trans('Label deleted', 'Modules.Postishipping.Admin'),
            'trackingCode' => (string) $trackingCode,
        ];
    }



    /**
     * Registers return shipment label
     *
     * @throws \Exception
     */
    private function registerReturnLabel($idOrder, PostishippingCartMetaRepository $metaRepository, PostiShipping $module)
    {
        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta = $metaRepository->find((int) $order->id_cart);
        if (!$cartMeta) {
            throw new PostiGenericException($this->trans('Cart meta not found', 'Modules.Postishipping.Admin'));
        }

        // Must have at least one regular shipment registered
        if (!$cartMeta->isRegistered()) {
            throw new PostiGenericException($this->trans('Cannot create return label when no shipping labels exist', 'Modules.Postishipping.Admin'));
        }

        $serviceId = $cartMeta->getPostiServiceId();
        if (!$serviceId || !ShippingServiceConfig::hasReturnService($serviceId)) {
            throw new PostiGenericException($this->trans('Unable to create return label for this shipment type (%serviceId%)', 'Modules.Postishipping.Admin', ['%serviceId%' => $serviceId ?? 'unknown']));
        }

        $returnServiceId = ShippingServiceConfig::getReturnServiceId($serviceId);
        $switchSenderReceiver = ShippingServiceConfig::shouldSwitchSenderReceiver($serviceId);

        $context = Context::getContext();
        $languageIso = $context->language ? $context->language->iso_code : 'en';
        $languageIso = substr($languageIso, 0, 2);

        $shipmentData = new ShipmentData();
        $shipmentData->serviceId = $returnServiceId;
        $shipmentData->language = $languageIso;

        // Addresses
        $deliveryAddress = new \Address((int) $order->id_address_delivery);
        $invoiceAddress  = new \Address((int) $order->id_address_invoice);
        $customer = new \Customer((int) $order->id_customer);

        // Sender info (shop/merchant)
        $shopName = Configuration::get('POSTISHIPPING_SENDER_NAME');
        $shopAddr = Configuration::get('POSTISHIPPING_SENDER_ADDRESS');
        $shopPostcode = Configuration::get('POSTISHIPPING_SENDER_POSTAL_CODE');
        $shopCity = Configuration::get('POSTISHIPPING_SENDER_CITY');
        $shopPhone = Configuration::get('POSTISHIPPING_SENDER_PHONE');
        $shopCountry = Configuration::get('POSTISHIPPING_SENDER_COUNTRY');
        $shopEmail = Configuration::get('POSTISHIPPING_SENDER_EMAIL');

        // Receiver info (customer)
        $company = $deliveryAddress->company;
        $customerName = trim($deliveryAddress->firstname . ' ' . $deliveryAddress->lastname);
        $customerAddr = $deliveryAddress->address1;
        $customerAddr2 = $deliveryAddress->address2;
        $customerPostcode = $deliveryAddress->postcode;
        $customerCity = $deliveryAddress->city;

        $customerCountry = $deliveryAddress->id_country ? \Country::getIsoById($deliveryAddress->id_country) : null;
        if (!$customerCountry && $invoiceAddress->id_country) {
            $customerCountry = \Country::getIsoById($invoiceAddress->id_country);
        }
        if (!$customerCountry) {
            $customerCountry = $shopCountry;
        }
        $customerCountry = $customerCountry ?: 'FI';

        $customerEmail = $customer->email;
        $customerPhone = $deliveryAddress->phone_mobile ?: ($deliveryAddress->phone ?: ($invoiceAddress->phone_mobile ?: $invoiceAddress->phone));

        if ($switchSenderReceiver) {
            // For return: sender = customer, receiver = shop
            if (!empty($company)) {
                $shipmentData->sender->name1 = $company;
                $shipmentData->sender->name2 = $customerName;
            } else {
                $shipmentData->sender->name1 = $customerName;
            }
            $shipmentData->sender->addr1 = $customerAddr;
            $shipmentData->sender->postcode = $customerPostcode;
            $shipmentData->sender->city = $customerCity;
            $shipmentData->sender->country = $customerCountry;
            $shipmentData->sender->phone = $customerPhone;
            $shipmentData->sender->email = $customerEmail;

            $shipmentData->receiver->name1 = $shopName;
            $shipmentData->receiver->addr1 = $shopAddr;
            $shipmentData->receiver->postcode = $shopPostcode;
            $shipmentData->receiver->city = $shopCity;
            $shipmentData->receiver->country = $shopCountry ?: 'FI';
            $shipmentData->receiver->phone = $shopPhone;
            $shipmentData->receiver->email = $shopEmail;
        } else {
            // Normal direction: sender = shop, receiver = customer
            $shipmentData->sender->name1 = $shopName;
            $shipmentData->sender->addr1 = $shopAddr;
            $shipmentData->sender->postcode = $shopPostcode;
            $shipmentData->sender->city = $shopCity;
            $shipmentData->sender->country = $shopCountry ?: 'FI';
            $shipmentData->sender->phone = $shopPhone;
            $shipmentData->sender->email = $shopEmail;

            if (!empty($company)) {
                $shipmentData->receiver->name1 = $company;
                $shipmentData->receiver->name2 = $customerName;
            } else {
                $shipmentData->receiver->name1 = $customerName;
            }
            $shipmentData->receiver->addr1 = $customerAddr;
            $shipmentData->receiver->addr2 = $customerAddr2;
            $shipmentData->receiver->postcode = $customerPostcode;
            $shipmentData->receiver->city = $customerCity;
            $shipmentData->receiver->country = $customerCountry;
            $shipmentData->receiver->phone = $customerPhone;
            $shipmentData->receiver->email = $customerEmail;
        }

        // Additional services for return
        if ($returnServiceId === '2108') {
            $shipmentData->additionalServices[] = new AdditionalServiceData('9902');
        }

        // Weight
        $orderTotalWeight = $order->getTotalWeight();

        // Parcel
        $parcelData = new ParcelData();
        $parcelData->weight = round($orderTotalWeight, 2);
        $parcelData->infoCode = mb_substr((string) Configuration::get('POSTISHIPPING_SENDER_INFO_CODE'), 0, 15) ?: null;
        $shipmentData->parcels[] = $parcelData;

        // Info
        $shipmentData->reference = $order->reference;
        $shipmentData->currency = \Currency::getIsoCodeById((int) $order->id_currency);

        // Create shipment via API
        $postiApi = new PostiApi();

        try {
            $result = $postiApi->registerShipment($shipmentData);
        } catch (\Exception $e) {
            throw new PostiGenericException($this->trans('Failed to register return label: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $e->getMessage()]));
        }

        $reference = $result->getReference();
        $tracking = $result->getTrackingCode();

        if ($tracking) {
            $cartMeta->addReturnRegistration($tracking, $reference, $returnServiceId);
            $metaRepository->updateGeneral($cartMeta);
        }

        return [
            'message' => $this->trans(
                'Return label registered! Reference: %reference% | Tracking code: %tracking%',
                'Modules.Postishipping.Admin',
                [
                    '%reference%' => $reference,
                    '%tracking%' => '<b>' . $tracking . '</b>',
                ]
            ),
            'reference' => $reference,
            'trackingCode' => $tracking,
        ];
    }

    /**
     * Deletes a return label registration by tracking code
     */
    private function deleteReturnLabel($idOrder, $trackingCode, PostishippingCartMetaRepository $metaRepository)
    {
        if (!$trackingCode) {
            throw new PostiGenericException($this->trans('Missing tracking code', 'Modules.Postishipping.Admin'));
        }

        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta = $metaRepository->find((int) $order->id_cart);
        if (!$cartMeta) {
            throw new PostiGenericException($this->trans('Cart meta not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta->removeReturnRegistration((string) $trackingCode);
        $metaRepository->updateGeneral($cartMeta);

        return [
            'message' => $this->trans('Return label deleted', 'Modules.Postishipping.Admin'),
            'trackingCode' => (string) $trackingCode,
        ];
    }

    /**
     * Fetches shipment status from the Posti API and saves it to the registration
     */
    private function refreshLabel($idOrder, $trackingCode, PostishippingCartMetaRepository $metaRepository)
    {
        if (!$trackingCode) {
            throw new PostiGenericException($this->trans('Missing tracking code', 'Modules.Postishipping.Admin'));
        }

        $order = new \Order((int) $idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $cartMeta = $metaRepository->find((int) $order->id_cart);
        if (!$cartMeta) {
            throw new PostiGenericException($this->trans('Cart meta not found', 'Modules.Postishipping.Admin'));
        }

        $postiApi = new PostiApi();
        $statusData = $postiApi->getShipmentStatus((string) $trackingCode);

        $statusText = 'Unknown';
        if ($statusData && is_array($statusData)) {
            // The API returns an array of status events; take the latest one
            $latest = end($statusData);
            if (isset($latest->status_text)) {
                $statusText = (string) $latest->status_text;
            } elseif (isset($latest->statusText)) {
                $statusText = (string) $latest->statusText;
            } elseif (isset($latest->description)) {
                $statusText = (string) $latest->description;
            }
        } elseif ($statusData && is_object($statusData)) {
            if (isset($statusData->status_text)) {
                $statusText = (string) $statusData->status_text;
            } elseif (isset($statusData->statusText)) {
                $statusText = (string) $statusData->statusText;
            } elseif (isset($statusData->description)) {
                $statusText = (string) $statusData->description;
            }
        }

        $cartMeta->updateRegistrationStatus((string) $trackingCode, $statusText);
        $metaRepository->updateGeneral($cartMeta);

        return [
            'message' => $this->trans('Status updated', 'Modules.Postishipping.Admin'),
            'trackingCode' => (string) $trackingCode,
            'status' => $statusText,
        ];
    }

    private function getManifestRepository(): PostishippingManifestRepository
    {
        return $this->container->get(PostishippingManifestRepository::class);
    }

    /**
     * Adds a shipping label to the current open manifest
     */
    private function addToPickup(int $orderId, string $trackingCode): array
    {
        if (!$trackingCode) {
            throw new PostiGenericException($this->trans('Missing tracking code', 'Modules.Postishipping.Admin'));
        }

        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            throw new PostiGenericException($this->trans('Order not found', 'Modules.Postishipping.Admin'));
        }

        $manifestRepository = $this->getManifestRepository();
        $manifest = $manifestRepository->getOrCreateOpenManifest();

        $manifest->addLabel($orderId, $trackingCode);
        $manifestRepository->save($manifest);

        return [
            'message' => $this->trans(
                'Label %tracking% added to pickup manifest #%manifest_id%',
                'Modules.Postishipping.Admin',
                [
                    '%tracking%' => '<b>' . $trackingCode . '</b>',
                    '%manifest_id%' => $manifest->getId(),
                ]
            ),
            'manifestId' => $manifest->getId(),
        ];
    }

    /**
     * Bulk action handler for orders grid
     */
    public function bulkAction(Request $request): Response
    {
        $action = $request->attributes->get('action', '');
        $access = self::getEmployeeAccess();

        // Bulk create/add actions require 'add' permission
        $bulkAddActions = ['createAndFetchLabels', 'createNewLabels', 'createCustomLabels', 'addToPickup'];
        if (in_array($action, $bulkAddActions, true) && !$access['add']) {
            $this->addFlash('error', $this->trans('You do not have permission to perform this action.', 'Admin.Notifications.Error'));
            return $this->redirectToRoute('admin_orders_index');
        }

        $orderIds = $request->request->all()['order_orders_bulk'] ?? [];

        if (empty($orderIds)) {
            $this->addFlash('error', $this->trans('No orders selected', 'Modules.Postishipping.Admin'));
            return $this->redirectToRoute('admin_orders_index');
        }

        // Sanitize and deduplicate order IDs
        $orderIds = array_values(array_unique(array_filter(
            array_map('intval', $orderIds),
            function ($id) {
                return $id > 0;
            }
        )));

        if (empty($orderIds)) {
            $this->addFlash('error', $this->trans('No valid orders selected', 'Modules.Postishipping.Admin'));
            return $this->redirectToRoute('admin_orders_index');
        }

        if ($action === 'createAndFetchLabels') {
            return $this->bulkCreateAndFetchLabels($orderIds);
        }

        if ($action === 'createNewLabels') {
            return $this->bulkCreateNewLabels($orderIds);
        }

        if ($action === 'createCustomLabels') {
            return $this->bulkCreateCustomLabels($orderIds);
        }

        if ($action === 'downloadAllLabels') {
            return $this->bulkDownloadAllLabels($orderIds);
        }

        if ($action === 'addToPickup') {
            return $this->bulkAddToPickup($orderIds);
        }

        $this->addFlash('error', $this->trans('Unknown bulk action', 'Modules.Postishipping.Admin'));
        return $this->redirectToRoute('admin_orders_index');
    }

    /**
     * Tries to find cart meta via Doctrine ORM, falls back to direct DB if needed
     */
    private function findCartMeta(int $cartId, PostishippingCartMetaRepository $metaRepository)
    {
        $cartMeta = $metaRepository->find($cartId);

        if ($cartMeta) {
            return $cartMeta;
        }

        // Doctrine ORM returned null — check if data exists via direct DB query
        $exists = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'postishipping_cart_meta` WHERE `id_cart` = ' . (int) $cartId
        );

        if (!$exists) {
            return null;
        }

        // Data exists in DB but Doctrine didn't find it – clear identity map and retry
        $this->getEntityManager()->clear();

        return $metaRepository->find($cartId);
    }

    /**
     * Shared bulk loop: validates each order/cart meta, delegates tracking-code
     * resolution to $resolveTrackingCode, then returns a combined labels PDF.
     *
     * @param callable $resolveTrackingCode function(\Order $order, $cartMeta): ?string
     */
    private function bulkResolveAndFetchLabels(array $orderIds, callable $resolveTrackingCode, string $filePrefix): Response
    {
        $metaRepository = $this->getMetaRepository();

        $trackingCodes = [];
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = new \Order($orderId);
                if (!\Validate::isLoadedObject($order)) {
                    $errors[] = $this->trans('Order #%id% not found', 'Modules.Postishipping.Admin', ['%id%' => $orderId]);
                    continue;
                }

                $cartMeta = $this->findCartMeta((int) $order->id_cart, $metaRepository);
                if (!$cartMeta) {
                    $errors[] = $this->trans(
                        'Order #%id% (cart %cart_id%): not a Posti shipment',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId, '%cart_id%' => $order->id_cart]
                    );
                    continue;
                }

                try {
                    $trackingCode = $resolveTrackingCode($order, $cartMeta);
                    if (!empty($trackingCode)) {
                        $trackingCodes[] = $trackingCode;
                    }
                    // Clear entity manager for next iteration
                    $this->getEntityManager()->clear();
                } catch (\Throwable $e) {
                    $errors[] = $this->trans(
                        'Order #%id%: failed to register - %reason%',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                    );
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = $this->trans(
                    'Order #%id%: %reason%',
                    'Modules.Postishipping.Admin',
                    ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                );
            }
        }

        return $this->bulkReturnLabelsPdf($trackingCodes, $errors, $filePrefix);
    }

    /**
     * Creates and fetches a shipping label per order: reuses the latest existing
     * label if one is already registered, otherwise creates one from the current
     * order details.
     */
    private function bulkCreateAndFetchLabels(array $orderIds): Response
    {
        $module = $this->getModule();
        $metaRepository = $this->getMetaRepository();

        return $this->bulkResolveAndFetchLabels($orderIds, function (\Order $order, $cartMeta) use ($module, $metaRepository) {
            if ($cartMeta->isRegistered()) {
                return $cartMeta->getTrackingCode();
            }

            $result = $this->registerLabel($order->id, $metaRepository, $module);
            return $result['trackingCode'] ?? null;
        }, 'posti_labels');
    }

    /**
     * Creates a new shipping label using default registration params, downloads only the new label
     */
    private function bulkCreateNewLabels(array $orderIds): Response
    {
        $module = $this->getModule();
        $metaRepository = $this->getMetaRepository();

        return $this->bulkResolveAndFetchLabels($orderIds, function (\Order $order, $cartMeta) use ($module, $metaRepository) {
            $result = $this->registerLabel($order->id, $metaRepository, $module);
            return $result['trackingCode'] ?? null;
        }, 'posti_labels');
    }

    /**
     * Creates a new shipping label using custom registration params, downloads only the new label
     */
    private function bulkCreateCustomLabels(array $orderIds): Response
    {
        $module = $this->getModule();
        $metaRepository = $this->getMetaRepository();

        $newTrackingCodes = [];
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = new \Order($orderId);
                if (!\Validate::isLoadedObject($order)) {
                    $errors[] = $this->trans('Order #%id% not found', 'Modules.Postishipping.Admin', ['%id%' => $orderId]);
                    continue;
                }

                $cartMeta = $this->findCartMeta((int) $order->id_cart, $metaRepository);
                if (!$cartMeta) {
                    $errors[] = $this->trans(
                        'Order #%id% (cart %cart_id%): not a Posti shipment',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId, '%cart_id%' => $order->id_cart]
                    );
                    continue;
                }

                // Use saved custom params if metaSaved, otherwise fall back to defaults
                $meta = $cartMeta->getMeta();
                if (!empty($meta['metaSaved'])) {
                    $customParams = [
                        'serviceId' => $cartMeta->getPostiServiceId(),
                        'additionalServices' => $cartMeta->getAdditionalServices(),
                    ];
                } else {
                    $customParams = [
                        'serviceId' => $cartMeta->getDefaultPostiServiceId(),
                        'additionalServices' => $cartMeta->getDefaultAdditionalServices(),
                    ];
                }

                try {
                    $result = $this->registerLabel($orderId, $metaRepository, $module, $customParams);
                    if (!empty($result['trackingCode'])) {
                        $newTrackingCodes[] = $result['trackingCode'];
                    }
                    // Clear entity manager for next iteration
                    $this->getEntityManager()->clear();
                } catch (\Throwable $e) {
                    $errors[] = $this->trans(
                        'Order #%id%: failed to register - %reason%',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                    );
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = $this->trans(
                    'Order #%id%: %reason%',
                    'Modules.Postishipping.Admin',
                    ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                );
            }
        }

        return $this->bulkReturnLabelsPdf($newTrackingCodes, $errors, 'posti_custom_labels');
    }

    /**
     * Downloads all existing shipping labels for selected orders (does not create new ones)
     */
    private function bulkDownloadAllLabels(array $orderIds): Response
    {
        $metaRepository = $this->getMetaRepository();

        $allTrackingCodes = [];
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = new \Order($orderId);
                if (!\Validate::isLoadedObject($order)) {
                    $errors[] = $this->trans('Order #%id% not found', 'Modules.Postishipping.Admin', ['%id%' => $orderId]);
                    continue;
                }

                $cartMeta = $this->findCartMeta((int) $order->id_cart, $metaRepository);
                if (!$cartMeta) {
                    $errors[] = $this->trans(
                        'Order #%id% (cart %cart_id%): not a Posti shipment',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId, '%cart_id%' => $order->id_cart]
                    );
                    continue;
                }

                if (!$cartMeta->isRegistered()) {
                    $errors[] = $this->trans(
                        'Order #%id%: no registered labels',
                        'Modules.Postishipping.Admin',
                        ['%id%' => $orderId]
                    );
                    continue;
                }

                foreach ($cartMeta->getRegistrations() as $reg) {
                    if (!empty($reg['trackingCode'])) {
                        $allTrackingCodes[] = $reg['trackingCode'];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $this->trans(
                    'Order #%id%: %reason%',
                    'Modules.Postishipping.Admin',
                    ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                );
            }
        }

        return $this->bulkReturnLabelsPdf($allTrackingCodes, $errors, 'posti_all_labels');
    }

    /**
     * Helper: flashes errors & returns PDF response for given tracking codes, or redirects on failure
     */
    private function bulkReturnLabelsPdf(array $trackingCodes, array $errors, string $filePrefix): Response
    {
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        $trackingCodes = array_values(array_unique($trackingCodes));

        if (empty($trackingCodes)) {
            $this->addFlash('error', $this->trans('No shipping labels to fetch', 'Modules.Postishipping.Admin'));
            return $this->redirectToRoute('admin_orders_index');
        }

        try {
            $result = $this->printLabels($trackingCodes);
            $pdfData = base64_decode($result['base64']);

            return new Response($pdfData, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filePrefix . '_' . date('Y-m-d_His') . '.pdf"',
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->trans(
                'Failed to fetch labels: %reason%',
                'Modules.Postishipping.Admin',
                ['%reason%' => $e->getMessage()]
            ));
            return $this->redirectToRoute('admin_orders_index');
        }
    }

    /**
     * Adds all labels from selected orders to the current open pickup manifest
     */
    private function bulkAddToPickup(array $orderIds): Response
    {
        $metaRepository = $this->getMetaRepository();
        $manifestRepository = $this->getManifestRepository();
        $manifest = $manifestRepository->getOrCreateOpenManifest();

        $addedCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = new \Order($orderId);
                if (!\Validate::isLoadedObject($order)) {
                    $errors[] = $this->trans('Order #%id% not found', 'Modules.Postishipping.Admin', ['%id%' => $orderId]);
                    continue;
                }

                $cartMeta = $this->findCartMeta((int) $order->id_cart, $metaRepository);
                if (!$cartMeta) {
                    $skippedCount++;
                    continue;
                }

                if (!$cartMeta->isRegistered()) {
                    $skippedCount++;
                    continue;
                }

                foreach ($cartMeta->getRegistrations() as $reg) {
                    $trackingCode = $reg['trackingCode'] ?? '';
                    if (!$trackingCode) {
                        continue;
                    }

                    $labelsBefore = count($manifest->getLabels());
                    $manifest->addLabel($orderId, $trackingCode);
                    $labelsAfter = count($manifest->getLabels());

                    if ($labelsAfter > $labelsBefore) {
                        $addedCount++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $this->trans(
                    'Order #%id%: %reason%',
                    'Modules.Postishipping.Admin',
                    ['%id%' => $orderId, '%reason%' => $e->getMessage()]
                );
            }
        }

        $manifestRepository->save($manifest);

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        if ($addedCount > 0) {
            $this->addFlash('success', $this->trans(
                '%count% label(s) added to pickup manifest #%manifest_id%',
                'Modules.Postishipping.Admin',
                ['%count%' => $addedCount, '%manifest_id%' => $manifest->getId()]
            ));
        }

        if ($skippedCount > 0) {
            $this->addFlash('warning', $this->trans(
                '%count% order(s) skipped (no registered labels)',
                'Modules.Postishipping.Admin',
                ['%count%' => $skippedCount]
            ));
        }

        return $this->redirectToRoute('admin_orders_index');
    }

    private function getEntityManager()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }

    private function prepareAdditionalInfoText(array $values, string $template): string
    {
        $text = str_replace('\n', "\n", $template);

        $text = str_ireplace('{ORDER_ID}', $values['order_id'] ?? '', $text);
        $text = str_ireplace('{ORDER_NUMBER}', $values['order_number'] ?? '', $text);
        $text = str_ireplace('{ORDER_NOTE}', $values['order_note'] ?? '', $text);

        $productsNames = '';
        $productsNamesWithQty = '';
        $productsSku = '';
        $productsSkuWithQty = '';

        if (!empty($values['products']) && is_array($values['products'])) {
            $names = [];
            $namesQty = [];
            $skus = [];
            $skusQty = [];

            foreach ($values['products'] as $prod) {
                $names[] = $prod['name'];
                $namesQty[] = $prod['name'] . ' (' . $prod['qty'] . ')';
                $sku = !empty($prod['sku']) ? $prod['sku'] : '-';
                $skus[] = $sku;
                $skusQty[] = $sku . ' (' . $prod['qty'] . ')';
            }

            $productsNames = implode(', ', $names);
            $productsNamesWithQty = implode(', ', $namesQty);
            $productsSku = implode(', ', $skus);
            $productsSkuWithQty = implode(', ', $skusQty);
        }

        $text = str_ireplace('{PRODUCTS_NAMES}', $productsNames, $text);
        $text = str_ireplace('{PRODUCTS_NAME_WITH_QUANTITY}', $productsNamesWithQty, $text);
        $text = str_ireplace('{PRODUCTS_SKU_WITH_QUANTITY}', $productsSkuWithQty, $text);
        $text = str_ireplace('{PRODUCTS_SKU}', $productsSku, $text);

        return $text;
    }
}
