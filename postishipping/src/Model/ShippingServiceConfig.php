<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Model;

class ShippingServiceConfig
{
    /**
     * Return service mapping: product code => return product code
     * Based on Posti/WooCommerce mapping
     */
    public const RETURN_SERVICES_MAP = [
        '2101' => '2102',
        '2102' => '2102',
        '2103' => '2108',
        '2104' => '2108',
        '2124' => '2102',
        '2142' => '2144',
        '2143' => '2144',
        '2144' => '2144',
        '2145' => '2144',
        '2331' => '2338',
        '2351' => '2358',
        '2352' => '2358',
        '2354' => '2359',
        '2461' => '2108',
        '2711' => '2718',
    ];

    /**
     * Services where sender and receiver should be swapped for return label
     */
    public const SWITCH_SENDER_RECEIVER_SERVICES = [
        '2101', '2102', '2124', '2142', '2143', '2144', '2145',
    ];

    /**
     * Express freight services using pallet packaging
     */
    public const EXPRESS_FREIGHT_SERVICES = [
        '2142', '2143', '2144', '2145',
    ];

    /**
     * Pallet/package type options for express freight services
     * Code => translation key
     */
    public const PALLET_TYPES = [
        'CC'  => 'Colli',
        'HP'  => 'Half pallet',
        'EUR' => 'EUR-pallet',
        'FIN' => 'FIN-pallet',
        'CW'  => 'Rollcage',
        'FPL' => 'Furniture pallet',
        'KK'  => 'Loose colli',
    ];

    public const DEFAULT_PALLET_TYPE = 'CC';

    public static function getReturnServiceId(string $serviceId): ?string
    {
        return self::RETURN_SERVICES_MAP[$serviceId] ?? null;
    }

    public static function hasReturnService(string $serviceId): bool
    {
        return isset(self::RETURN_SERVICES_MAP[$serviceId]);
    }

    public static function shouldSwitchSenderReceiver(string $serviceId): bool
    {
        return in_array($serviceId, self::SWITCH_SENDER_RECEIVER_SERVICES, true);
    }

    public static function isExpressFreight(string $serviceId): bool
    {
        return in_array($serviceId, self::EXPRESS_FREIGHT_SERVICES, true);
    }
}
