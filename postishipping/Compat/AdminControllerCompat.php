<?php

namespace Posti\PrestaShop\Postishipping\Compat;

if (class_exists('PrestaShopBundle\Controller\Admin\PrestaShopAdminController')) {
    // PS 9+ — bridge trans() to accept old PS 8 calling convention: trans($key, $domain, $params)
    abstract class AdminControllerCompat extends \PrestaShopBundle\Controller\Admin\PrestaShopAdminController
    {
        protected function trans($id, $parameters = [], $domain = null, $locale = null): string
        {
            if (is_string($parameters)) {
                // Old convention: trans($key, $domain, $params, $locale)
                return parent::trans($id, is_array($domain) ? $domain : [], $parameters, $locale);
            }
            return parent::trans($id, $parameters, $domain, $locale);
        }
    }
} else {
    // PS 1.7.8 / 8.x
    abstract class AdminControllerCompat extends \PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController
    {
        public static function getSubscribedServices(): array
        {
            $services = parent::getSubscribedServices();
            if (!isset($services['translator'])) {
                if (interface_exists('Symfony\\Contracts\\Translation\\TranslatorInterface')) {
                    $services['translator'] = '?Symfony\\Contracts\\Translation\\TranslatorInterface';
                } elseif (interface_exists('Symfony\\Component\\Translation\\TranslatorInterface')) {
                    $services['translator'] = '?Symfony\\Component\\Translation\\TranslatorInterface';
                }
            }
            return $services;
        }
    }
}
