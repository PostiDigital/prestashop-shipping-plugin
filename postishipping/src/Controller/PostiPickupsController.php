<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Controller;

use Configuration;
use Posti\PrestaShop\Postishipping\Entity\PostishippingManifest;
use Posti\PrestaShop\Postishipping\Exception\PostiGenericException;
use Posti\PrestaShop\Postishipping\PostiApi;
use Posti\PrestaShop\Postishipping\PostiHelper;
use Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository;
use Posti\PrestaShop\Postishipping\Repository\PostishippingManifestRepository;
use Posti\PrestaShop\Postishipping\Compat\AdminControllerCompat;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PostiPickupsController extends AdminControllerCompat
{
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            PostishippingManifestRepository::class => PostishippingManifestRepository::class,
            PostishippingCartMetaRepository::class => PostishippingCartMetaRepository::class,
        ]);
    }

    private function getManifestRepository(): PostishippingManifestRepository
    {
        return $this->container->get(PostishippingManifestRepository::class);
    }

    private const ITEMS_PER_PAGE_OPTIONS = [10, 20, 50, 100];
    private const DEFAULT_ITEMS_PER_PAGE = 20;

    public function indexAction(Request $request): Response
    {
        $manifestRepository = $this->getManifestRepository();

        $filters = [
            'id' => $request->query->get('filter_id', ''),
            'title_from' => $request->query->get('filter_title_from', ''),
            'title_to' => $request->query->get('filter_title_to', ''),
            'order_or_tracking' => $request->query->get('filter_order_or_tracking', ''),
            'status' => $request->query->get('filter_status', ''),
            'pickup_from' => $request->query->get('filter_pickup_from', ''),
            'pickup_to' => $request->query->get('filter_pickup_to', ''),
        ];

        $limit = (int) $request->query->get('limit', self::DEFAULT_ITEMS_PER_PAGE);
        if (!in_array($limit, self::ITEMS_PER_PAGE_OPTIONS, true)) {
            $limit = self::DEFAULT_ITEMS_PER_PAGE;
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $limit;

        $hasFilters = !empty(array_filter($filters));

        if ($hasFilters) {
            $totalItems = $manifestRepository->countFilteredManifests($filters);
            $manifests = $manifestRepository->getFilteredManifestsPaginated($filters, $limit, $offset);
        } else {
            $totalItems = $manifestRepository->countAllManifests();
            $manifests = $manifestRepository->getPaginatedManifests($limit, $offset);
        }

        $totalPages = max(1, (int) ceil($totalItems / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        // Collect all labels from manifests and find which tracking codes were deleted
        $allLabels = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->getLabels() as $label) {
                $allLabels[] = $label;
            }
        }

        $cartMetaRepository = $this->container->get(PostishippingCartMetaRepository::class);
        $existingCodes = $cartMetaRepository->filterExistingTrackingCodes($allLabels);
        $existingCodesSet = array_flip($existingCodes);

        $deletedTrackingCodes = [];
        foreach ($allLabels as $label) {
            if (!isset($existingCodesSet[$label['trackingCode']])) {
                $deletedTrackingCodes[$label['trackingCode']] = true;
            }
        }

        $router = $this->container->get('router');
        $ajaxUrl = $router->generate('postishipping_pickups_ajax');

        return $this->render('@Modules/postishipping/views/templates/admin/pickups.html.twig', [
            'manifests' => $manifests,
            'deletedTrackingCodes' => $deletedTrackingCodes,
            'ajaxUrl' => $ajaxUrl,
            'psThemeClass' => PostiHelper::getPsAdminThemeClass(),
            'filters' => $filters,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
                'limitOptions' => self::ITEMS_PER_PAGE_OPTIONS,
            ],
        ]);
    }

    public function ajaxAction(Request $request): JsonResponse
    {
        $action = $request->request->get('action', '');
        $error = '';
        $response = [];

        if ('placePickupOrder' === $action) {
            $manifestId = (int) $request->request->get('manifest_id', 0);
            $date = $request->request->get('date', '');
            $timeFrom = $request->request->get('time_from', '');
            $timeTo = $request->request->get('time_to', '');
            $additionalInfo = $request->request->get('additional_info', '');

            try {
                $response = $this->placePickupOrder($manifestId, $date, $timeFrom, $timeTo, $additionalInfo);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while placing pickup order: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        if ('cancelPickupOrder' === $action) {
            $manifestId = (int) $request->request->get('manifest_id', 0);

            try {
                $response = $this->cancelPickupOrder($manifestId);
            } catch (PostiGenericException $pe) {
                $error = $pe->getMessage();
            } catch (\Throwable $th) {
                $error = $this->trans('Something went wrong while canceling pickup order: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $th->getMessage()]);
            }

            return $this->json([
                'error' => $error,
                'content' => $response,
                'action' => $action,
            ]);
        }

        return $this->json([
            'error' => $this->trans('Unknown action', 'Modules.Postishipping.Admin'),
            'content' => $response,
            'action' => $action,
        ]);
    }

    private function placePickupOrder(int $manifestId, string $date, string $timeFrom, string $timeTo, string $additionalInfo): array
    {
        if (!$date || !$timeFrom || !$timeTo) {
            throw new PostiGenericException($this->trans('Date and time fields are required', 'Modules.Postishipping.Admin'));
        }

        // Validate date format
        $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            throw new PostiGenericException($this->trans('Invalid date format', 'Modules.Postishipping.Admin'));
        }

        $today = new \DateTime('today');
        if ($dateObj < $today) {
            throw new PostiGenericException($this->trans('Date cannot be in the past', 'Modules.Postishipping.Admin'));
        }

        // Validate time format (HH:MM)
        if (!preg_match('/^\d{2}:\d{2}$/', $timeFrom) || !preg_match('/^\d{2}:\d{2}$/', $timeTo)) {
            throw new PostiGenericException($this->trans('Invalid time format', 'Modules.Postishipping.Admin'));
        }

        if ($timeTo <= $timeFrom) {
            throw new PostiGenericException($this->trans('Latest time must be after earliest time', 'Modules.Postishipping.Admin'));
        }

        $manifestRepository = $this->getManifestRepository();
        $manifest = $manifestRepository->find($manifestId);

        if (!$manifest) {
            throw new PostiGenericException($this->trans('Manifest not found', 'Modules.Postishipping.Admin'));
        }

        if ($manifest->getStatus() !== PostishippingManifest::STATUS_OPEN) {
            throw new PostiGenericException($this->trans('Manifest is not open', 'Modules.Postishipping.Admin'));
        }

        $trackingCodes = $manifest->getTrackingCodes();
        if (empty($trackingCodes)) {
            throw new PostiGenericException($this->trans('Manifest has no labels', 'Modules.Postishipping.Admin'));
        }

        // Build POSTRA XML and call the pickup API
        $this->callCourierPickup($manifest, $date, $timeFrom, $timeTo, $additionalInfo);

        // Update manifest status
        $pickupTimeStr = $date . ' ' . $timeFrom . ' - ' . $timeTo;
        $manifest->setStatus(PostishippingManifest::STATUS_CLOSED);
        $manifest->setPickupTime($pickupTimeStr);
        $manifestRepository->save($manifest);

        return [
            'message' => $this->trans('Pickup order placed successfully', 'Modules.Postishipping.Admin'),
            'manifestId' => $manifest->getId(),
            'pickupTime' => $pickupTimeStr,
        ];
    }

    private function cancelPickupOrder(int $manifestId): array
    {
        $manifestRepository = $this->getManifestRepository();
        $manifest = $manifestRepository->find($manifestId);

        if (!$manifest) {
            throw new PostiGenericException($this->trans('Manifest not found', 'Modules.Postishipping.Admin'));
        }

        if ($manifest->getStatus() !== PostishippingManifest::STATUS_OPEN) {
            throw new PostiGenericException($this->trans('Manifest is not open', 'Modules.Postishipping.Admin'));
        }

        $employee = \Context::getContext()->employee;
        $canceledBy = trim($employee->firstname . ' ' . $employee->lastname);

        $manifest->setStatus(PostishippingManifest::STATUS_CANCELED);
        $manifest->setCanceledBy($canceledBy);
        $manifestRepository->save($manifest);

        return [
            'message' => $this->trans('Pickup order canceled successfully', 'Modules.Postishipping.Admin'),
            'manifestId' => $manifest->getId(),
            'canceledBy' => $manifest->getCanceledBy(),
        ];
    }

    private function callCourierPickup(PostishippingManifest $manifest, string $date, string $timeFrom, string $timeTo, string $additionalInfo): void
    {
        $configOverride = PostiHelper::getOverrideConfig();

        $pickupData = new \Posti\PrestaShop\Postishipping\Dto\PickupOrderData();
        $pickupData->pickupUrl = $configOverride['pickupUrl'] ?? '';
        $pickupData->date = $date;
        $pickupData->timeFrom = $timeFrom;
        $pickupData->timeTo = $timeTo;
        $pickupData->additionalInfo = $additionalInfo;
        $pickupData->senderId = Configuration::get('POSTISHIPPING_PICKUP_SENDER_ID') ?: '';
        $pickupData->customerId = Configuration::get('POSTISHIPPING_PICKUP_CUSTOMER_ID') ?: '';
        $pickupData->invoiceId = Configuration::get('POSTISHIPPING_PICKUP_INVOICE_ID') ?: '';
        $pickupData->senderName = Configuration::get('POSTISHIPPING_SENDER_NAME');
        $pickupData->senderAddress = Configuration::get('POSTISHIPPING_SENDER_ADDRESS');
        $pickupData->senderPostcode = Configuration::get('POSTISHIPPING_SENDER_POSTAL_CODE');
        $pickupData->senderCity = Configuration::get('POSTISHIPPING_SENDER_CITY');
        $pickupData->senderCountry = Configuration::get('POSTISHIPPING_SENDER_COUNTRY');
        $pickupData->labels = $manifest->getLabels();

        $postiApi = new PostiApi();
        $response = $postiApi->placePickupOrder($pickupData);

        if (!isset($response['status']) || $response['status'] != 200) {
            $errorMsg = $response['message'] ?? 'Unknown error';
            throw new PostiGenericException($this->trans('Pickup order failed: %reason%', 'Modules.Postishipping.Admin', ['%reason%' => $errorMsg]));
        }
    }
}
