<?php

use Posti\PrestaShop\Postishipping\Exception\PostiBadPickupPointId;
use Posti\PrestaShop\Postishipping\Exception\PostiGenericException;
use Posti\PrestaShop\Postishipping\Model\PostishippingOrderMeta;
use Posti\PrestaShop\Postishipping\PostiApi;

class PostiShippingAjaxModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool
     */
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     *
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $translator = $this->module->getTranslator();

        if (!$this->isTokenValid()) {
            $this->ajaxRender(json_encode([
                'error' => $translator->trans('Invalid security token', [], 'Modules.Postishipping.Front'),
            ]));
            return;
        }

        $action = Tools::getValue('action');
        $error = '';

        $cart = $this->context->cart;

        if ($action === 'savePickupPointId') {
            $pickup_point_id = Tools::getValue('pickup_point_id', null);
            $carrier_id = Tools::getValue('carrier_id');
            try {
                $info = $this->savePickupPointId($pickup_point_id, (int) $cart->id, (int) $carrier_id);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $translator->trans('Something went wrong while trying to save Pickup Point', [], 'Modules.Postishipping.Front');
                \PrestaShopLogger::addLog('PostiShipping ajax savePickupPointId: ' . $th->getMessage(), 3, $th->getCode(), 'PostiShipping', 0, true);
            }

            $this->ajaxRender(json_encode([
                'error' => $error,
                'content' => !$error ? $info : '',
            ]));
            return;
        }

        if ($action === 'clearPickupPoint') {
            try {
                $this->clearPickupPoint((int) $cart->id);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $translator->trans('Something went wrong while trying to clear Pickup Point', [], 'Modules.Postishipping.Front');
                \PrestaShopLogger::addLog('PostiShipping ajax clearPickupPoint: ' . $th->getMessage(), 3, $th->getCode(), 'PostiShipping', 0, true);
            }

            $this->ajaxRender(json_encode([
                'error' => $error,
                'content' => '',
            ]));
            return;
        }

        if ($action === 'getPickupPoints') {
            $response = [];
            $provider = Tools::getValue('provider', '');
            $address = Tools::getValue('address', '');
            try {
                $response = $this->module->getPickupPoints((int) $cart->id, $provider, $address);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $translator->trans('Something went wrong while trying to get Pickup Points', [], 'Modules.Postishipping.Front');
                \PrestaShopLogger::addLog('PostiShipping ajax getPickupPoints: ' . $th->getMessage(), 3, $th->getCode(), 'PostiShipping', 0, true);
            }

            $this->ajaxRender(json_encode([
                'error' => $error,
                'content' => $response,
            ]));
            return;
        }

        $this->ajaxRender(json_encode([
            'error' => $translator->trans('Invalid request', [], 'Modules.Postishipping.Front'),
        ]));
    }

    public function savePickupPointId($pickup_point_id, $id_cart, $id_carrier = 0)
    {
        $translator = $this->module->getTranslator();

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');
        $cartMeta = $cartMetaRepository->find((int) $id_cart);

        if (!$cartMeta) {
            throw new PostiGenericException($translator->trans('Failed to find postishipping cart meta data', [], 'Modules.Postishipping.Front'));
        }

        // Ensure cart meta has carrier data (postiServiceId, shippingMethod, etc.)
        if (!$cartMeta->getPostiServiceId()) {
            /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
            $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

            $postiCarrier = null;

            // Prefer explicit carrier_id passed from JS (works even when cart->id_carrier is 0)
            if ($id_carrier > 0) {
                $carrier = new \Carrier($id_carrier);
                if (\Validate::isLoadedObject($carrier) && $carrier->id_reference) {
                    $postiCarrier = $postiCarrierRepository->findOneBy(['id_reference' => $carrier->id_reference]);
                }
            }

            // Fallback: derive from cart's currently selected carrier
            if (!$postiCarrier) {
                $cart = new \Cart((int) $id_cart);
                if (\Validate::isLoadedObject($cart) && $cart->id_carrier) {
                    $carrier = new \Carrier($cart->id_carrier);
                    if ($carrier->id_reference) {
                        $postiCarrier = $postiCarrierRepository->findOneBy(['id_reference' => $carrier->id_reference]);
                    }
                }
            }

            if ($postiCarrier) {
                $cartMeta->setCarrierDefaultData($postiCarrier);
                $cartMetaRepository->updateGeneral($cartMeta);
            } else {
                \PrestaShopLogger::addLog(
                    'PostiShipping savePickupPointId: Could not resolve Posti carrier. id_cart=' . $id_cart
                    . ', id_carrier=' . $id_carrier,
                    3, 0, 'PostiShipping', 0, true
                );
                throw new PostiGenericException($translator->trans('Failed to find postishipping cart meta data', [], 'Modules.Postishipping.Front'));
            }
        }

        $cartMeta->setPickupPointId($pickup_point_id);

        $cartMetaRepository->updateGeneral($cartMeta);

        return $cartMeta->getPickupPointInfo();
    }

    public function clearPickupPoint($id_cart)
    {
        $translator = $this->module->getTranslator();

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');
        $cartMeta = $cartMetaRepository->find((int) $id_cart);

        if (!$cartMeta) {
            throw new PostiGenericException($translator->trans('Failed to find postishipping cart meta data', [], 'Modules.Postishipping.Front'));
        }

        $cartMeta->clearPickupPoint();

        $cartMetaRepository->updateGeneral($cartMeta);
    }

}
