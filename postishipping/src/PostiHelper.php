<?php

namespace Posti\PrestaShop\Postishipping;

class PostiHelper
{
    public static function getOverrideConfig()
    {
        $configFile = __DIR__ . '/../posticonfig.json';
        if (!is_file($configFile)) {
            return null;
        }

        $config = @json_decode(file_get_contents($configFile), true);

        return is_array($config) ? $config : null;
    }

    public static function keyExists($key, &$array)
    {
        if (!$array || !is_array($array)) {
            return false;
        }

        return (isset($array[$key]) || array_key_exists($key, $array));
    }

    /**
     * @return bool True if PrestaShop version is 1.7.x
     */
    public static function isPs17(): bool
    {
        return version_compare(_PS_VERSION_, '8.0.0', '<');
    }

    /**
     * @return bool True if PrestaShop version is 8.x
     */
    public static function isPs8(): bool
    {
        return version_compare(_PS_VERSION_, '8.0.0', '>=') && version_compare(_PS_VERSION_, '9.0.0', '<');
    }

    /**
     * @return bool True if PrestaShop version is 9.x
     */
    public static function isPs9(): bool
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    /**
     * Returns CSS-friendly PS version identifier: "17", "8", or "9".
     */
    public static function getPsVersionClass(): string
    {
        if (self::isPs9()) {
            return '9';
        }
        if (self::isPs8()) {
            return '8';
        }
        return '17';
    }

    /**
     * Returns CSS-friendly PS admin theme identifier.
     * For PS 1.7.x returns "classic", for PS 8.x and above returns "modern".
     * If ps_edition_basic module is active, returns "modern" regardless of version.
     */
    public static function getPsAdminThemeClass(): string
    {
        if (self::isPs17() || !\Module::isEnabled('ps_edition_basic')) {
            return 'classic';
        }

        return 'modern';
    }

    /**
     * Generates reference code for COD using supplied ID (usualy order iD).
     * ID must be min. 3 digits long for correct calculation,
     * if given id is less than 3 digits it will be padded with given symbol (DEFAULT: '0')
     * eg. 1 => 001 
     * 
     * @param int|string $id
     * 
     * @return string Generated reference code
     */
    public static function generateCodReference($id, $pad = '0')
    {
        // pad if needed
        $id = str_pad(strval($id), 3, $pad, STR_PAD_LEFT);

        $weights = array(7, 3, 1);
        $sum = 0;
        $base = str_split($id);
        $reversed_base = array_reverse($base);
        $reversed_base_length = count($reversed_base);
        for ($i = 0; $i < $reversed_base_length; $i++) {
            $sum += $reversed_base[$i] * $weights[$i % 3];
        }
        $checksum = (10 - $sum % 10) % 10;
        return implode('', $base) . $checksum;
    }
}
