<?php

namespace Posti\PrestaShop\Postishipping;

use Configuration;
use Posti\PrestaShop\Postishipping\Dto\AdditionalServiceData;
use Posti\PrestaShop\Postishipping\Dto\ContentLineData;
use Posti\PrestaShop\Postishipping\Dto\LabelResult;
use Posti\PrestaShop\Postishipping\Dto\ParcelData;
use Posti\PrestaShop\Postishipping\Dto\PickupOrderData;
use Posti\PrestaShop\Postishipping\Dto\ShipmentData;
use Posti\PrestaShop\Postishipping\Dto\ShipmentResult;
use Posti\PrestaShop\Postishipping\Model\ShippingMethod;

class PostiApi
{
    const CACHE_TOKEN_KEY = 'POSTISHIPPING_TOKEN_CACHE';

    private $client;

    /**
     * $apiKey and $secret should be passed only when testing credentials
     */
    public function __construct($mode = 'production', $apiKey = null, $secret = null)
    {
        $configOverride = PostiHelper::getOverrideConfig();
        
        $mode = $configOverride['mode'] ?? $mode;
        $baseUrl = $configOverride['baseUrl'] ?? null;
        $authUrl = $configOverride['authUrl'] ?? null;

        $this->client = new \Pakettikauppa\Client([
            $mode => [
                'api_key'   => $apiKey ?? \Configuration::get('POSTISHIPPING_API_KEY'),
                'secret'    => $secret ?? \Configuration::get('POSTISHIPPING_API_SECRET'),
                'use_posti_auth' => true,
                'base_uri' => $baseUrl ?: 'https://nextshipping.posti.fi',
                'posti_auth_url' => $authUrl ?: 'https://oauth.posti.com',
            ],
        ], $mode);
    }

    private function saveCachedToken($data)
    {
        Configuration::updateValue(self::CACHE_TOKEN_KEY, base64_encode(json_encode($data)));
    }

    /**
     * @return \StdObject|null token data or null
     */
    public function getCachedToken()
    {
        $cacheData = Configuration::get(self::CACHE_TOKEN_KEY);

        if (!$cacheData) {
            return null;
        }

        $decoded = base64_decode($cacheData);

        return $decoded === false ? null : json_decode($decoded);
    }

    private function authenticate()
    {
        /** @var \StdObject|null */
        $token = $this->getCachedToken();

        if ($token === null || $token->enddate < time()) {
            $apiToken = null;
            try {
                /** @var \StdObject */
                $apiToken = $this->client->getToken();
            } catch (\Throwable $th) {
                //
            }

            if (!$apiToken || !isset($apiToken->access_token)) {
                throw new \Exception("Failed to get Posti Token");
            }

            $token = $apiToken;
            // reduce time by 60s just in case
            $token->enddate = time() + ($apiToken->expires_in - 60);

            $this->saveCachedToken($token);
        }

        $this->client->setAccessToken($token->access_token);
    }

    /**
     * Authenticates and returns true if token was received successfully.
     * Used for credential validation.
     */
    public function testCredentials(): bool
    {
        $this->authenticate();
        $token = $this->getCachedToken();

        return $token !== null && isset($token->access_token);
    }

    /**
     * Returns bearer token string for external API calls (e.g. pickup orders).
     *
     * @throws \Exception
     */
    public function getBearerToken(): string
    {
        $this->authenticate();
        $token = $this->getCachedToken();

        if (!$token || !isset($token->access_token)) {
            throw new \Exception("Failed to get Posti Token");
        }

        return $token->access_token;
    }

    /**
     * @return ShippingMethod[]
     */
    public function getShippingMethods()
    {
        $this->authenticate();

        /** @var \stdClass|null */
        $methods = $this->client->listShippingMethods();

        $collection = [];
        if (!$methods || !is_array($methods)) {
            return [];
        }

        /** @var \stdClass */
        foreach ($methods as $method) {
            $shippingMethod = ShippingMethod::getShippingMethod($method);

            $collection[$shippingMethod->shipping_method_code] = $shippingMethod;
        }

        return $collection;
    }

    public function getAdditionalServices()
    {
        $this->authenticate();

        $data = $this->client->listAdditionalServices();

        return $data;
    }

    public function getPickupPointsByAddress($serviceProvider, $address, $limit = 5, $type = null)
    {
        $this->authenticate();

        $data = $this->client->searchPickupPointsByText($address, $serviceProvider, (int) $limit, $type);

        return $data;
    }

    public function getPickupPointInfo($id, $serviceProvider)
    {
        $this->authenticate();

        $data = $this->client->getPickupPointInfo($id, $serviceProvider);

        return $data;
    }

    public function getShipmentStatus(string $trackingCode)
    {
        $this->authenticate();

        return $this->client->getShipmentStatus($trackingCode);
    }

    /**
     * Registers a shipment and returns a result with tracking code and reference.
     *
     * @throws \Exception
     */
    public function registerShipment(ShipmentData $data): ShipmentResult
    {
        $this->authenticate();

        $shipment = $this->buildShipment($data);

        $this->client->createTrackingCode($shipment, $data->language);

        return new ShipmentResult(
            (string) $shipment->getTrackingCode(),
            (string) $shipment->getReference()
        );
    }

    /**
     * Fetches shipping labels PDF for given tracking codes.
     *
     * @param string|string[] $trackingCodes
     * @throws \Exception
     */
    public function fetchLabels($trackingCodes, ?string $size = null): LabelResult
    {
        $this->authenticate();

        if (!is_array($trackingCodes)) {
            $trackingCodes = [$trackingCodes];
        }

        /** @var \SimpleXMLElement */
        $xml = $this->client->fetchShippingLabels($trackingCodes, $size);

        $base64 = (string) ($xml->{'response.file'} ?? '');

        if (!$base64) {
            throw new \Exception('Cannot find label for given tracking code');
        }

        return new LabelResult($base64);
    }

    /**
     * Places a pickup order via the Posti POSTRA XML API.
     *
     * @throws \Exception
     */
    public function placePickupOrder(PickupOrderData $data): array
    {
        $this->authenticate();

        $token = $this->getCachedToken();
        if (!$token || !isset($token->access_token)) {
            throw new \Exception('Token not found');
        }

        $pickupUrl = $data->pickupUrl ?: 'https://connect.posti.fi/transportation/v1/orders';

        $xmlData = $this->buildPickupXml($data);

        return $this->doPickupPost($pickupUrl, $xmlData, $token->access_token);
    }

    public static function resetTokenCache()
    {
        Configuration::deleteByName(self::CACHE_TOKEN_KEY);
    }

    // ---------------------------------------------------------------
    // Private: Pakettikauppa library object builders
    // ---------------------------------------------------------------

    private function buildShipment(ShipmentData $data): \Pakettikauppa\Shipment
    {
        $shipment = new \Pakettikauppa\Shipment();
        $shipment->setShippingMethod($data->serviceId);

        // Sender
        $sender = new \Pakettikauppa\Shipment\Sender();
        $sender->setName1($data->sender->name1);
        if ($data->sender->name2) {
            $sender->setName2($data->sender->name2);
        }
        $sender->setAddr1($data->sender->addr1);
        if ($data->sender->addr2) {
            $sender->setAddr2($data->sender->addr2);
        }
        $sender->setPostcode($data->sender->postcode);
        $sender->setCity($data->sender->city);
        $sender->setCountry($data->sender->country);
        $sender->setPhone($data->sender->phone);
        $sender->setEmail($data->sender->email);
        $shipment->setSender($sender);

        // Receiver
        $receiver = new \Pakettikauppa\Shipment\Receiver();
        $receiver->setName1($data->receiver->name1);
        if ($data->receiver->name2) {
            $receiver->setName2($data->receiver->name2);
        }
        $receiver->setAddr1($data->receiver->addr1);
        if ($data->receiver->addr2) {
            $receiver->setAddr2($data->receiver->addr2);
        }
        $receiver->setPostcode($data->receiver->postcode);
        $receiver->setCity($data->receiver->city);
        $receiver->setCountry($data->receiver->country);
        $receiver->setPhone($data->receiver->phone);
        $receiver->setEmail($data->receiver->email);
        $shipment->setReceiver($receiver);

        // Additional services
        foreach ($data->additionalServices as $svcData) {
            $additionalService = new \Pakettikauppa\Shipment\AdditionalService();
            $additionalService->setServiceCode($svcData->code);
            foreach ($svcData->specifiers as $name => $value) {
                $additionalService->addSpecifier($name, $value);
            }
            $shipment->addAdditionalService($additionalService);
        }

        // Parcels
        foreach ($data->parcels as $parcelData) {
            $parcel = new \Pakettikauppa\Shipment\Parcel();
            $parcel->setWeight($parcelData->weight);
            $parcel->setPackageType($parcelData->packageType);
            if ($parcelData->infoCode) {
                $parcel->setInfocode($parcelData->infoCode);
            }
            foreach ($parcelData->contentLines as $clData) {
                $contentLine = new \Pakettikauppa\Shipment\ContentLine();
                $contentLine->currency = $clData->currency;
                $contentLine->description = $clData->description;
                $contentLine->quantity = $clData->quantity;
                $contentLine->country_of_origin = $clData->countryOfOrigin;
                $contentLine->tariff_code = $clData->tariffCode;
                $contentLine->netweight = $clData->netweight;
                $contentLine->value = $clData->value;
                $parcel->addContentLine($contentLine);
            }
            $shipment->addParcel($parcel);
        }

        // Info
        $info = new \Pakettikauppa\Shipment\Info();
        $info->setReference($data->reference);
        $info->setCurrency($data->currency);
        if ($data->additionalInfoText) {
            $info->setAdditionalInfoText($data->additionalInfoText);
        }
        $shipment->setShipmentInfo($info);

        return $shipment;
    }

    private function buildPickupXml(PickupOrderData $data): string
    {
        $xml = new \SimpleXMLElement('<Postra/>');
        $xml->addAttribute('xmlns', 'http://api.posti.fi/xml/POSTRA/1');

        $header = $xml->addChild('Header');
        $header->addChild('SenderId', htmlspecialchars($data->senderId, ENT_XML1));
        $header->addChild('ReceiverId', '003715318644');
        $header->addChild('DocumentDateTime', gmdate('c'));
        $header->addChild('Sequence', (string) floor(microtime(true) * 1000));
        $header->addChild('MessageCode', 'POSTRA');
        $header->addChild('MessageVersion', '1');
        $header->addChild('MessageRelease', '2');
        $header->addChild('MessageAction', 'PICKUP_ORDER');

        $shipments = $xml->addChild('Shipments');

        foreach ($data->labels as $label) {
            $shipment = $shipments->addChild('Shipment');
            $shipment->addChild('MessageFunctionCode', 'ORIGINAL');
            $shipment->addChild('PickupOrderType', 'PICKUP');
            $shipment->addChild('ShipmentNumber', htmlspecialchars($label['trackingCode'], ENT_XML1));
            $shipment->addChild('ShipmentDateTime', gmdate('c'));

            $pickup = $shipment->addChild('PickupDate', $data->date);
            $pickup->addAttribute('timeEarliest', gmdate('H:i:sP', strtotime($data->date . ' ' . $data->timeFrom)));
            $pickup->addAttribute('timeLatest', gmdate('H:i:sP', strtotime($data->date . ' ' . $data->timeTo)));

            $instructions = $shipment->addChild('Instructions');
            $instruction = $instructions->addChild('Instruction', htmlspecialchars($data->additionalInfo, ENT_XML1));
            $instruction->addAttribute('type', 'GENERAL');

            $parties = $shipment->addChild('Parties');

            $consignor = $parties->addChild('Party');
            $consignor->addAttribute('role', 'CONSIGNOR');
            $consignor->addChild('Name1', htmlspecialchars($data->senderName, ENT_XML1));
            $consignorLocation = $consignor->addChild('Location');
            $consignorLocation->addChild('Street1', htmlspecialchars($data->senderAddress, ENT_XML1));
            $consignorLocation->addChild('Postcode', htmlspecialchars($data->senderPostcode, ENT_XML1));
            $consignorLocation->addChild('City', htmlspecialchars($data->senderCity, ENT_XML1));
            $consignorLocation->addChild('Country', htmlspecialchars($data->senderCountry, ENT_XML1));

            $payer = $parties->addChild('Party');
            $payer->addAttribute('role', 'PAYER');
            $account1 = $payer->addChild('Account', htmlspecialchars($data->customerId, ENT_XML1));
            $account1->addAttribute('type', 'SAP_CUSTOMER');
            $account2 = $payer->addChild('Account', htmlspecialchars($data->invoiceId, ENT_XML1));
            $account2->addAttribute('type', 'SAP_INVOICE');
            $payer->addChild('Name1', htmlspecialchars($data->senderName, ENT_XML1));

            $items = $shipment->addChild('GoodsItems');
            $item = $items->addChild('GoodsItem');
            $qty = $item->addChild('PackageQuantity', '1');
            $qty->addAttribute('type', 'CW');
        }

        return $xml->asXML();
    }

    private function doPickupPost(string $url, string $body, string $token): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_USERAGENT => 'pk-client-lib/2.0',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-type: text/xml; charset=utf-8',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['status' => 500, 'message' => 'cURL error: ' . $curlError];
        }

        // Try to parse XML response
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string((string) $response);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc !== false && empty($errors)) {
            $responseStr = str_replace('resultMessage', 'result_message', (string) $response);
            $doc = simplexml_load_string($responseStr);

            $status = (string) ($doc->result ?? '');
            $message = (string) ($doc->result_message ?? '');

            if ($status === 'FAILURE') {
                return ['status' => 500, 'message' => $status . ' - ' . $message];
            } elseif ($status === 'SUCCESS') {
                return ['status' => 200];
            }

            return ['status' => 500, 'message' => 'Unknown response status - ' . $status];
        }

        // Fallback: try JSON
        $jsonResponse = json_decode((string) $response, true);
        if (is_array($jsonResponse)) {
            return $jsonResponse;
        }

        // If HTTP code is 2xx, treat as success
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['status' => 200];
        }

        return ['status' => $httpCode, 'message' => 'HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500)];
    }
}
