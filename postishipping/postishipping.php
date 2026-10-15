<?php

use Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier;
use Posti\PrestaShop\Postishipping\Entity\PostishippingCartMeta;
use Posti\PrestaShop\Postishipping\Exception\PostiGenericException;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceCod;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServiceDangerousGoods;
use Posti\PrestaShop\Postishipping\Model\AdditionalServices\AdditionalServicePickupPoint;
use Posti\PrestaShop\Postishipping\Model\ShippingServiceConfig;
use Posti\PrestaShop\Postishipping\Model\PostishippingOrderMeta;
use Posti\PrestaShop\Postishipping\PostiApi;
use Posti\PrestaShop\Postishipping\PostiHelper;
use Posti\PrestaShop\Postishipping\PostiUpdater;
use Posti\PrestaShop\Postishipping\Controller\PostiOrderController;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use Posti\PrestaShop\Postishipping\Form\Type\PostiProductExtraType;

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

class PostiShipping extends CarrierModule
{
    const INSTALL_SQL_FILE = 'install.sql';

    public $id_carrier;

    public $configuredCarriers;

    private $_html = '';

    private $_baseUrl;

    private $langId;
    private $shopId;

    private $hooks = [
        'actionAdminControllerSetMedia',
        'displayBackOfficeHeader',
        'displayCarrierExtraContent',
        'actionFrontControllerSetMedia',
        'actionCarrierProcess',
        'displayAdminOrderMainBottom',
        'actionValidateOrder',
        'actionOrderGridDefinitionModifier',
        'displayAdminProductsExtra',
        'actionProductFormBuilderModifier',
        'actionAfterUpdateProductFormHandler',
        'actionAfterCreateProductFormHandler',
        'actionProductSave',
        'actionEmailSendBefore',
        'displayOrderConfirmation',
    ];

    private $childTabs = [
        'AdminPostiShippingPickups' => [
            'en' => 'Pickup orders',
            'fi' => 'Noutotilaukset',
        ]
    ];

    private $hiddenTabs = [
        'PostishippingPostiOrderController' => 'Posti Shipping Orders',
    ];

    public function __construct()
    {
        $this->name = 'postishipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Posti';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Posti Shipping', [], 'Modules.Postishipping.Admin');
        $this->description = $this->trans('Posti shipping service for Prestashop', [], 'Modules.Postishipping.Admin');

        $this->langId = $this->context->language->id;
        $this->shopId = $this->context->shop->id ? $this->context->shop->id : Configuration::get('PS_SHOP_DEFAULT');

        $this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => _PS_VERSION_];
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!file_exists(dirname(__FILE__) . '/' . self::INSTALL_SQL_FILE)) {
            return false;
        } elseif (!$sql = file_get_contents(dirname(__FILE__) . '/' . self::INSTALL_SQL_FILE)) {
            return false;
        }
        $sql = str_replace(['PREFIX_', 'ENGINE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_], $sql);
        $sql = preg_split("/;\s*[\r\n]+/", trim($sql));

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute(trim($query))) {
                return false;
            }
        }

        return (
            parent::install()
            && $this->installTabs()
            && $this->registerHooks()
            && Configuration::updateValue('POSTISHIPPING', 'Posti Shipping')
        );
    }

    private function installTabs()
    {
        // Parent tab
        $parentTab = new Tab();
        $parentTab->active = 1;
        $parentTab->class_name = 'AdminPostiShipping';
        $parentTab->id_parent = Tab::getIdFromClassName('SELL');
        $parentTab->module = $this->name;
        $parentTab->icon = 'posti';
        $parentTab->wording = 'Posti Shipping';
        $parentTab->wording_domain = 'Modules.Postishipping.Admin';
        foreach (Language::getLanguages() as $lang) {
            $titles = [
                'en' => 'Posti',
            ];
            $parentTab->name[$lang['id_lang']] = $titles[$lang['iso_code']] ?? 'Posti';
        }
        if (!$parentTab->add()) {
            return false;
        }

        $ordersTab = new Tab(Tab::getIdFromClassName('AdminParentOrders'));
        $ordersTabPosition = (Validate::isLoadedObject($ordersTab) && (int) $ordersTab->id_parent === (int) $parentTab->id_parent) ? (int) $ordersTab->position : 0;
        $this->moveTabToPosition($parentTab, $ordersTabPosition + 1);

        // Child tabs
        foreach ($this->childTabs as $className => $name) {
            $this->createChildTab($className, $name, $parentTab->id);
        }

        // Hidden tabs
        foreach ($this->hiddenTabs as $className => $name) {
            $this->createHiddenTab($className, $name);
        }
        
        return true;
    }

    private function moveTabToPosition($tab, $position)
    {
        $way = $tab->position < $position;
        $tab->updatePosition($way, $position);
    }

    private function createChildTab($className, $names, $parentId)
    {
        if (!$parentId || !is_array($names) || !isset($names['en'])) {
            return false;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->id_parent = $parentId;
        $tab->module = $this->name;
        foreach (Language::getLanguages() as $lang) {
            $tab->name[$lang['id_lang']] = $names[$lang['iso_code']] ?? $names['en'];
        }
        return $tab->add();
    }

    private function createHiddenTab($className, $name)
    {
        if (!is_string($name) || empty($name)) {
            return false;
        }

        $tab = new Tab();
        $tab->class_name = $className;
        $tab->id_parent = 0;
        $tab->module = $this->name;
        foreach (Language::getLanguages() as $lang) {
            $tab->name[$lang['id_lang']] = $name;
        }
        return $tab->add();
    }

    private function registerHooks()
    {
        foreach ($this->hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall()
    {
        $deleteData = (bool) Configuration::get('POSTISHIPPING_DELETE_DATA');
        Configuration::deleteByName('POSTISHIPPING_DELETE_DATA');
        Configuration::deleteByName('POSTISHIPPING_LAST_UPDATE_CHECK');

        if (!$this->unregisterHooks() || !$this->uninstallTabs()) {
            return false;
        }

        if ($deleteData) {
            $this->deleteCarriers();
            if (!$this->deleteConfiguration() || !$this->deleteTables()) {
                return false;
            }
        }

        return parent::uninstall();
    }

    private function uninstallTabs()
    {
        // Find all tabs related to this module
        $tabs = Tab::getCollectionFromModule($this->name);
        if (!$tabs) {
            return true;
        }

        // Delete all found tabs
        foreach ($tabs as $tab) {
            if (Validate::isLoadedObject($tab)) {
                if (!$tab->delete()) {
                    return false;
                }
            }
        }
        return true;
    }

    private function unregisterHooks()
    {
        foreach ($this->hooks as $hook) {
            $this->unregisterHook($hook);
        }

        return true;
    }

    public function deleteCarriers()
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'postishipping_carrier';

        $rows = $db->executeS('SELECT `id_carrier_reference` FROM `' . $table . '`');
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $reference = (int) $row['id_carrier_reference'];
            if ($reference <= 0) {
                continue;
            }

            $carrierIds = $db->executeS(
                'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_reference` = ' . $reference
            );
            if (!$carrierIds) {
                continue;
            }

            foreach ($carrierIds as $cRow) {
                $carrier = new Carrier((int) $cRow['id_carrier']);
                if (Validate::isLoadedObject($carrier)) {
                    $carrier->deleted = true;
                    $carrier->update();
                }
            }
        }
    }

    public function deleteConfiguration()
    {
        $keys = [
            'POSTISHIPPING',
            'POSTISHIPPING_API_VALID',
            'POSTISHIPPING_API_KEY',
            'POSTISHIPPING_API_SECRET',
            'POSTISHIPPING_TOKEN_CACHE',
            'POSTISHIPPING_SENDER_NAME',
            'POSTISHIPPING_SENDER_ADDRESS',
            'POSTISHIPPING_SENDER_POSTAL_CODE',
            'POSTISHIPPING_SENDER_CITY',
            'POSTISHIPPING_SENDER_COUNTRY',
            'POSTISHIPPING_SENDER_PHONE',
            'POSTISHIPPING_SENDER_EMAIL',
            'POSTISHIPPING_SENDER_INFO_CODE',
            'POSTISHIPPING_COD_ACCOUNT',
            'POSTISHIPPING_COD_CODBIC',
            'POSTISHIPPING_COD_MODULES',
            'POSTISHIPPING_PICKUP_SENDER_ID',
            'POSTISHIPPING_PICKUP_CUSTOMER_ID',
            'POSTISHIPPING_PICKUP_INVOICE_ID',
            'POSTISHIPPING_LABEL_SIZE',
            'POSTISHIPPING_LABEL_ADDITIONAL_INFO',
            'POSTISHIPPING_DELETE_DATA',
            'POSTISHIPPING_STATE_REGISTERED',
            'POSTISHIPPING_EXCLUDE_NO_HS_CODE',
            'POSTISHIPPING_PALLET_TYPE',
            'POSTISHIPPING_IGNORE_PRODUCT_WEIGHT',
            'POSTISHIPPING_CHECKOUT_PICKUP_LIMIT',
            'POSTISHIPPING_CHECKOUT_SHOW_TYPE',
            'POSTISHIPPING_CHECKOUT_PP_TYPE',
            'POSTISHIPPING_LATEST_VERSION',
            'POSTISHIPPING_LATEST_VERSION_URL',
            'POSTISHIPPING_LATEST_CHANGELOG',
            'POSTISHIPPING_LAST_UPDATE_CHECK',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    public function deleteTables()
    {
        $db = Db::getInstance();
        $tables = [
            'postishipping_carrier',
            'postishipping_cart_meta',
            'postishipping_manifest',
            'postishipping_product_extra',
        ];

        foreach ($tables as $table) {
            if (!$db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                return false;
            }
        }

        return true;
    }

    public function hookDisplayBackOfficeHeader(array $params)
    {
        $controller = $this->context->controller ?? null;
        $controllerName = '';
        if ($controller && property_exists($controller, 'controller_name')) {
            $controllerName = $controller->controller_name ?? '';
        }

        // Return CSS as link tags for Symfony admin pages compatibility
        $css = '<link rel="stylesheet" href="' . $this->_path . 'views/css/postishipping_admin.css" type="text/css" media="all">';

        if ($controllerName === 'AdminModules' && Tools::getValue('configure') === 'postishipping') {
            $css .= "\n" . '<link rel="stylesheet" href="' . $this->_path . 'views/css/postishipping_admin_config.css" type="text/css" media="all">';
        }

        if ($controllerName === 'AdminOrders') {
            $css .= "\n" . '<link rel="stylesheet" href="' . $this->_path . 'views/css/postishipping_admin_order.css" type="text/css" media="all">';
        }

        if ($controllerName === 'AdminPostiShippingPickups') {
            $css .= "\n" . '<link rel="stylesheet" href="' . $this->_path . 'views/css/postishipping_admin_pickups.css" type="text/css" media="all">';
        }

        if ($controllerName === 'AdminProducts') {
            $css .= "\n" . '<link rel="stylesheet" href="' . $this->_path . 'views/css/postishipping_admin_product.css" type="text/css" media="all">';
        }

        // Also use addCSS for legacy admin pages compatibility
        if ($controller && method_exists($controller, 'addCSS')) {
            $controller->addCSS($this->_path . 'views/css/postishipping_admin.css');
        }

        // Show version update notification on orders list page (inline script — works on all PS versions)
        if ($controllerName === 'AdminOrders') {
            $updateInfo = $this->getVersionUpdateNotification();
            if ($updateInfo) {
                $settingsUrl = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
                $updateMessage = htmlspecialchars($this->trans(
                    'A new version %version% is available. Go to [1]module settings[/1] to update.',
                    ['%version%' => $updateInfo['version']],
                    'Modules.Postishipping.Admin'
                ), ENT_QUOTES, 'UTF-8');
                // Replace [1]...[/1] with actual link
                $updateMessage = str_replace('[1]', '<a href="' . htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') . '">', $updateMessage);
                $updateMessage = str_replace('[/1]', '</a>', $updateMessage);
                $css .= "\n<script>document.addEventListener('DOMContentLoaded',function(){"
                    . "if(document.getElementById('order-postishipping'))return;"
                    . "var c=document.getElementById('content')||document.getElementById('main-div')||document.querySelector('main')||document.body;"
                    . "var t=c.querySelector('.content-div')||c;"
                    . "var d=document.createElement('div');"
                    . "d.className='alert alert-info';"
                    . "d.innerHTML='<strong>Posti Shipping:</strong> " . addslashes($updateMessage) . "';"
                    . "t.insertBefore(d,t.firstChild)"
                    . "});</script>";
            }
        }

        // Fix for PS 9.x breadcrumb showing invalid material-icons "posti" text as garbage characters.
        // Replaces <i class="material-icons">posti</i> (without mi-posti class) with module icon image.
        // Safe for PS 1.7.8/8.x — if no such elements exist, nothing happens.
        $iconUrl = htmlspecialchars($this->_path . 'views/img/icon-simple.svg', ENT_QUOTES, 'UTF-8');
        $css .= "\n<script>document.addEventListener('DOMContentLoaded',function(){"
            . "document.querySelectorAll('i.material-icons:not(.mi-posti)').forEach(function(el){"
            . "if(el.textContent.trim()==='posti'){"
            . "var img=document.createElement('img');"
            . "img.src='" . $iconUrl . "';"
            . "img.alt='Posti';"
            . "img.style.cssText='width:16px;height:16px;vertical-align:middle;margin-right:4px';"
            . "el.parentNode.replaceChild(img,el)"
            . "}})"
            . "});</script>";

        return $css;
    }

    public function hookActionAdminControllerSetMedia(array $params)
    {
        $controller = $this->context->controller;

        Media::addJsDef([
            'PostiMedia' => [
                'name' => $controller->controller_name ?? '',
                'config' => Tools::getValue('configure'),
            ]
        ]);

        $controllerName = $controller->controller_name ?? '';

        // CSS added here as backup for legacy admin pages
        $controller->addCSS($this->_path . 'views/css/postishipping_admin.css');

        if ($controllerName === 'AdminModules' && Tools::getValue('configure') === 'postishipping') {
            $controller->addJS($this->_path . 'views/js/postishipping_admin_config.js');
            $controller->addCSS($this->_path . 'views/css/postishipping_admin_config.css');
        }

        if ($controllerName === 'AdminOrders') {
            $controller->addJS($this->_path . 'views/js/postishipping_admin_order.js');
            $controller->addCSS($this->_path . 'views/css/postishipping_admin_order.css');
        }

        if ($controllerName === 'AdminPostiShippingPickups') {
            $controller->addJS($this->_path . 'views/js/postishipping_admin_pickups.js');
            $controller->addCSS($this->_path . 'views/css/postishipping_admin_pickups.css');
        }

        if ($controllerName === 'AdminProducts') {
            $controller->addCSS($this->_path . 'views/css/postishipping_admin_product.css');
        }
    }

    public function hookActionOrderGridDefinitionModifier(array $params)
    {
        /** @var \PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface $definition */
        $definition = $params['definition'];

        $definition->getBulkActions()
            ->add(
                (new SubmitBulkAction('posti_create_fetch_labels'))
                    ->setName($this->trans('Posti: Create and fetch shipping labels', [], 'Modules.Postishipping.Admin'))
                    ->setOptions([
                        'submit_route' => 'postishipping_bulk_action',
                        'route_params' => [
                            'action' => 'createAndFetchLabels',
                        ],
                    ])
            )
            /*->add(
                (new SubmitBulkAction('posti_create_labels'))
                    ->setName($this->trans('Posti: Create new shipping labels', [], 'Modules.Postishipping.Admin'))
                    ->setOptions([
                        'submit_route' => 'postishipping_bulk_action',
                        'route_params' => [
                            'action' => 'createNewLabels',
                        ],
                    ])
            )*/
            /*->add(
                (new SubmitBulkAction('posti_create_custom_labels'))
                    ->setName($this->trans('Posti: Create custom shipping labels', [], 'Modules.Postishipping.Admin'))
                    ->setOptions([
                        'submit_route' => 'postishipping_bulk_action',
                        'route_params' => [
                            'action' => 'createCustomLabels',
                        ],
                    ])
            )*/
            /*->add(
                (new SubmitBulkAction('posti_download_all_labels'))
                    ->setName($this->trans('Posti: Download all shipping labels', [], 'Modules.Postishipping.Admin'))
                    ->setOptions([
                        'submit_route' => 'postishipping_bulk_action',
                        'route_params' => [
                            'action' => 'downloadAllLabels',
                        ],
                    ])
            )*/
            /*->add(
                (new SubmitBulkAction('posti_add_to_pickup'))
                    ->setName($this->trans('Posti: Add shipping labels to pickup', [], 'Modules.Postishipping.Admin'))
                    ->setOptions([
                        'submit_route' => 'postishipping_bulk_action',
                        'route_params' => [
                            'action' => 'addToPickup',
                        ],
                    ])
            )*/
        ;
    }

    /**
     * Detect file version > DB version and trigger PrestaShop's native upgrade.
     *
     * Called at the start of getContent() after a self-update redirect so that
     * the new PHP files are already loaded. Mirrors the logic of PrestaShop's
     * ModuleDataUpdater::upgrade() — loads the full module list to populate
     * caches, then runs initUpgradeModule() + runUpgradeModule() which execute
     * upgrade/upgrade-X.Y.Z.php scripts. Finally syncs ps_module.version to
     * the file version.
     */
    private function runPendingUpgrade(): void
    {
        $dbVersion = Db::getInstance()->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'' . pSQL($this->name) . '\''
        );

        if (!$dbVersion || !version_compare($this->version, $dbVersion, '>')) {
            return;
        }

        // Populate module cache — required by initUpgradeModule / needUpgrade
        $moduleList = Module::getModulesOnDisk();

        foreach ($moduleList as $module) {
            if ($module->name !== $this->name) {
                continue;
            }

            if (Module::initUpgradeModule($module)) {
                $instance = Module::getInstanceByName($this->name);
                $instance->runUpgradeModule();
            }

            // Sync DB version to file version (same as ModuleDataUpdater)
            Module::upgradeModuleVersion($this->name, $this->version);
            break;
        }
    }

    protected function _postProcess()
    {
        $errors = [];
        if (Tools::isSubmit('submitApiConfig')) {
            PostiApi::resetTokenCache();
            Configuration::deleteByName('POSTISHIPPING_API_VALID');

            $apiKey = Tools::getValue('POSTISHIPPING_API_KEY');
            $apiKeyValid = $apiKey && Validate::isString($apiKey);
            if (!$apiKeyValid) {
                $errors[] = $this->trans(
                    '%s is required',
                    [$this->trans('API Key', [], 'Modules.Postishipping.Admin')],
                    'Admin.Notifications.Error'
                );
            }

            $apiSecret = Tools::getValue('POSTISHIPPING_API_SECRET');
            $apiSecretValid = $apiSecret && Validate::isString($apiSecret);
            if (!$apiSecretValid) {
                $errors[] = $this->trans(
                    '%s is required',
                    [$this->trans('API Secret', [], 'Modules.Postishipping.Admin')],
                    'Admin.Notifications.Error'
                );
            }

            if ($apiKeyValid && $apiSecretValid) {
                Configuration::updateValue('POSTISHIPPING_API_KEY', (string) $apiKey);
                Configuration::updateValue('POSTISHIPPING_API_SECRET', (string) $apiSecret);
            }

            if (!$errors) {
                try {
                    $postiApi = new PostiApi('production', $apiKey, $apiSecret);
                    if ($postiApi->testCredentials()) {
                        Configuration::updateValue('POSTISHIPPING_API_VALID', 1);
                    }
                } catch (\Throwable $th) {
                    $errors[] = $this->trans('Failed to validate credentials, please check your API key and secret', [], 'Modules.Postishipping.Admin');
                }
            }

            if (count($errors)) {
                $this->_html .= $this->displayError(implode('<br />', $errors));
            } else {
                $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
            }
        } elseif (Tools::isSubmit('submitSenderConfig')) {
            PostiApi::resetTokenCache();

            $requiredSender = [
                'POSTISHIPPING_SENDER_NAME' => $this->trans('Sender name', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_ADDRESS' => $this->trans('Sender address', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_POSTAL_CODE' => $this->trans('Sender postal code', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_CITY' => $this->trans('Sender city', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_COUNTRY' => $this->trans('Sender country', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_PHONE' => $this->trans('Sender phone', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_SENDER_EMAIL' => $this->trans('Sender email', [], 'Modules.Postishipping.Admin'),
            ];
            foreach ($requiredSender as $key => $text) {
                $senderData = Tools::getValue($key);
                if (!$senderData) {
                    $errors[] = $this->trans(
                        '%s is required',
                        [$text],
                        'Admin.Notifications.Error'
                    );
                }

                if ('POSTISHIPPING_SENDER_PHONE' === $key && !Validate::isPhoneNumber($senderData)) {
                    $errors[] = $this->trans(
                        '%s is not valid phone number',
                        [$text],
                        'Admin.Notifications.Error'
                    );
                }

                if ('POSTISHIPPING_SENDER_EMAIL' === $key && !Validate::isEmail($senderData)) {
                    $errors[] = $this->trans(
                        '%s is not valid email',
                        [$text],
                        'Admin.Notifications.Error'
                    );
                }
            }

            if (count($errors)) {
                $this->_html .= $this->displayError(implode('<br />', $errors));
            } else {
                Configuration::updateValue('POSTISHIPPING_SENDER_NAME', (string) Tools::getValue('POSTISHIPPING_SENDER_NAME'));
                Configuration::updateValue('POSTISHIPPING_SENDER_ADDRESS', (string) Tools::getValue('POSTISHIPPING_SENDER_ADDRESS'));
                Configuration::updateValue('POSTISHIPPING_SENDER_POSTAL_CODE', (string) Tools::getValue('POSTISHIPPING_SENDER_POSTAL_CODE'));
                Configuration::updateValue('POSTISHIPPING_SENDER_CITY', (string) Tools::getValue('POSTISHIPPING_SENDER_CITY'));
                Configuration::updateValue('POSTISHIPPING_SENDER_COUNTRY', (string) Tools::getValue('POSTISHIPPING_SENDER_COUNTRY'));
                Configuration::updateValue('POSTISHIPPING_SENDER_PHONE', (string) Tools::getValue('POSTISHIPPING_SENDER_PHONE'));
                Configuration::updateValue('POSTISHIPPING_SENDER_EMAIL', (string) Tools::getValue('POSTISHIPPING_SENDER_EMAIL'));
                Configuration::updateValue('POSTISHIPPING_SENDER_INFO_CODE', mb_substr((string) Tools::getValue('POSTISHIPPING_SENDER_INFO_CODE'), 0, 15));

                $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
            }
        } elseif (Tools::isSubmit('submitCodConfig')) {
            $codFields = [
                'POSTISHIPPING_COD_ACCOUNT' => $this->trans('Bank account (IBAN)', [], 'Modules.Postishipping.Admin'),
                'POSTISHIPPING_COD_CODBIC' => $this->trans('Bic code', [], 'Modules.Postishipping.Admin'),
            ];
            foreach ($codFields as $key => $text) {
                $codFieldData = (string) Tools::getValue($key, '');
                if ($codFieldData !== '' && !ctype_alnum($codFieldData)) {
                    $errors[] = $this->trans(
                        '%s must contain only letters and digits',
                        [$text],
                        'Modules.Postishipping.Admin'
                    );
                }
            }

            if (count($errors)) {
                $this->_html .= $this->displayError(implode('<br />', $errors));
            } else {
                Configuration::updateValue('POSTISHIPPING_COD_ACCOUNT', (string) Tools::getValue('POSTISHIPPING_COD_ACCOUNT'));
                Configuration::updateValue('POSTISHIPPING_COD_CODBIC', (string) Tools::getValue('POSTISHIPPING_COD_CODBIC'));

                $codModuleValues = [];
                foreach (PaymentModule::getInstalledPaymentModules() as $payModule) {
                    if (Tools::getValue('POSTISHIPPING_COD_MODULES_' . $payModule['name'])) {
                        $codModuleValues[] = $payModule['name'];
                    }
                }
                Configuration::updateValue('POSTISHIPPING_COD_MODULES', implode(',', $codModuleValues));

                $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
            }
        } elseif (Tools::isSubmit('submitCheckoutConfig')) {
            $pickupLimit = (int) Tools::getValue('POSTISHIPPING_CHECKOUT_PICKUP_LIMIT', 5);
            if ($pickupLimit < 1) {
                $pickupLimit = 1;
            }
            Configuration::updateValue('POSTISHIPPING_CHECKOUT_PICKUP_LIMIT', $pickupLimit);

            $showAs = (string) Tools::getValue('POSTISHIPPING_CHECKOUT_SHOW_TYPE', 'menu');
            if (!in_array($showAs, ['menu', 'list'])) {
                $showAs = 'menu';
            }
            Configuration::updateValue('POSTISHIPPING_CHECKOUT_SHOW_TYPE', $showAs);

            $allowedPpTypes = ['PRIVATE_LOCKER', 'OUTDOOR_LOCKER', 'PARCEL_LOCKER', 'PICKUP_POINT'];
            $ppTypeValues = [];
            foreach ($allowedPpTypes as $ppType) {
                if (Tools::getValue('POSTISHIPPING_CHECKOUT_PP_TYPE_' . $ppType)) {
                    $ppTypeValues[] = $ppType;
                }
            }
            $ppTypeValues = array_intersect($ppTypeValues, $allowedPpTypes);
            if (empty($ppTypeValues)) {
                $ppTypeValues = $allowedPpTypes;
            }
            Configuration::updateValue('POSTISHIPPING_CHECKOUT_PP_TYPE', implode('|', $ppTypeValues));

            $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
        } elseif (Tools::isSubmit('submitShipmentConfig')) {
            Configuration::updateValue('POSTISHIPPING_LABEL_SIZE', (string) Tools::getValue('POSTISHIPPING_LABEL_SIZE', 'A5'));
            Configuration::updateValue('POSTISHIPPING_IGNORE_PRODUCT_WEIGHT', (int) Tools::getValue('POSTISHIPPING_IGNORE_PRODUCT_WEIGHT', 0));
            Configuration::updateValue('POSTISHIPPING_EXCLUDE_NO_HS_CODE', (int) Tools::getValue('POSTISHIPPING_EXCLUDE_NO_HS_CODE', 0));
            Configuration::updateValue('POSTISHIPPING_PALLET_TYPE', (string) Tools::getValue('POSTISHIPPING_PALLET_TYPE', ShippingServiceConfig::DEFAULT_PALLET_TYPE));
            Configuration::updateValue('POSTISHIPPING_STATE_REGISTERED', (int) Tools::getValue('POSTISHIPPING_STATE_REGISTERED', 0));
            Configuration::updateValue('POSTISHIPPING_LABEL_ADDITIONAL_INFO', (string) Tools::getValue('POSTISHIPPING_LABEL_ADDITIONAL_INFO', ''));

            $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
        } elseif (Tools::isSubmit('submitPickupConfig')) {
            Configuration::updateValue('POSTISHIPPING_PICKUP_SENDER_ID', (string) Tools::getValue('POSTISHIPPING_PICKUP_SENDER_ID', ''));
            Configuration::updateValue('POSTISHIPPING_PICKUP_CUSTOMER_ID', (string) Tools::getValue('POSTISHIPPING_PICKUP_CUSTOMER_ID', ''));
            Configuration::updateValue('POSTISHIPPING_PICKUP_INVOICE_ID', (string) Tools::getValue('POSTISHIPPING_PICKUP_INVOICE_ID', ''));

            $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
        } elseif (Tools::isSubmit('submitOtherConfig')) {
            Configuration::updateValue('POSTISHIPPING_DELETE_DATA', (int) Tools::getValue('POSTISHIPPING_DELETE_DATA', 0));

            $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Postishipping.Admin'));
        } elseif (Tools::isSubmit('submitModuleUpdate')) {
            $updater = new PostiUpdater($this->version);
            $result = $updater->performUpdate();
            if ($result['success']) {
                // Do NOT update ps_module.version here — the redirect will
                // reload the module from updated files and runPendingUpgrade()
                // will detect the version mismatch, execute upgrade scripts,
                // and then sync the DB version.
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], [
                    'configure' => $this->name,
                    'tab_module' => $this->tab,
                    'module_name' => $this->name,
                    'postiUpdated' => $result['version'],
                ]));
            } else {
                $this->_html .= $this->displayError($result['message']);
            }
        } elseif (Tools::isSubmit('submitEditCarrier')) {

            /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
            $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

            $id_postishipping_carrier = (int) Tools::getValue('id_postishipping_carrier');

            if ($id_postishipping_carrier > 0) {
                /** @var \Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier */
                $carrier = $postiCarrierRepository->find($id_postishipping_carrier);
                $psCarrier = $carrier ? $carrier->getPsCarrier() : false;

                if (!$psCarrier) {
                    $this->_html .= $this->displayError($this->trans('This Posti carrier cannot be saved because the associated PrestaShop carrier has been deleted.', [], 'Modules.Postishipping.Admin'));
                    return;
                }
            } else {
                $carrier = new PostishippingCarrier();

                $carrierName = (string) Tools::getValue('name', PostishippingCarrier::DEFAULT_NAME);
                try {
                    if (!Validate::isCarrierName($carrierName)) {
                        throw new \Exception("Invalid carrier name");
                    }
                    $psCarrier = $this->addCarrier($carrierName, (bool) Tools::getValue('active'));

                    if ($psCarrier) {
                        $this->addGroups($psCarrier);
                        // Ranges must be created before zones
                        $this->addRanges($psCarrier);
                        $this->addZones($psCarrier);
                    }
                } catch (\Throwable $th) {
                    $psCarrier = false;
                    $error = $th->getMessage();
                }

                if (!$psCarrier) {
                    $this->_html .= $this->displayError($this->trans('Failed to create Posti carrier: ' . $error, [], 'Modules.Postishipping.Admin'));
                    return;
                }

                $carrier->setCarrierReference($psCarrier->id);
            }

            // manage additional services selection and default values
            $selectedAddServices = Tools::getValue('posti_additional_service', []);
            $selectedSpec = [];
            foreach (Tools::getValue('posti_additional_service_spec', []) as $addServiceId => $specData) {
                if (in_array($addServiceId, $selectedAddServices)) {
                    $selectedSpec[$addServiceId] = $specData;
                }
            }

            // update entity values
            $carrier
                ->setPostiServiceId((string) Tools::getValue('id_service'))
                ->setPostiServiceData((string) Tools::getValue('service_base64'))
                ->setSelectedAdditionalServices($selectedAddServices)
                ->setSelectedAdditionalServicesSpec($selectedSpec)
                ->setActive(Tools::getValue('active'))
            ;

            if ($postiCarrierRepository->update($carrier)) {
                Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'conf' => 4]));
            } else {
                $this->_html .= $this->displayError($this->trans('Could not save Posti carrier', [], 'Modules.Postishipping.Admin'));
            }
        } elseif (Tools::isSubmit('deletepostishippingcarrier')) {
            /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
            $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

            $id_postishipping_carrier = (int) Tools::getValue('id_postishipping_carrier');

            $carrier = null;
            if ($id_postishipping_carrier > 0) {
                /** @var \Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier */
                $carrier = $postiCarrierRepository->find($id_postishipping_carrier);
            }

            if (!$carrier) {
                $this->_html .= $this->displayError($this->trans('Posti carrier for deletion not found', [], 'Modules.Postishipping.Admin'));
                return;
            }

            $psCarrier = $carrier->getPsCarrier();
            if ($psCarrier) {
                $psCarrier->delete();
            }
            $postiCarrierRepository->delete($carrier);
            $this->_html .= $this->displayError($this->trans('Posti carrier deleted', [], 'Modules.Postishipping.Admin'));
        } elseif (Tools::isSubmit('statuspostishippingcarrier')) {
            $id_postishipping_carrier = (int) Tools::getValue('id_postishipping_carrier');
            if ($id_postishipping_carrier > 0) {
                /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
                $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

                /** @var \Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier */
                $carrier = $postiCarrierRepository->find($id_postishipping_carrier);

                if ($carrier) {
                    $carrier->setActive(!$carrier->isActive());
                    $postiCarrierRepository->update($carrier);

                    Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'conf' => 4]));
                }
            }
        }
    }

    /**
     * Re-verifies the stored API credentials against the live Posti API.
     * Mirrors the submitApiConfig failure handling in _postProcess(): shows
     * an error and reports invalid when the stored key/secret are rejected.
     * When no credentials are stored yet, fails silently (no error shown).
     */
    protected function checkApiCredentials(): bool
    {
        $apiKey = Configuration::get('POSTISHIPPING_API_KEY');
        $apiSecret = Configuration::get('POSTISHIPPING_API_SECRET');

        if (!$apiKey || !$apiSecret) {
            Configuration::deleteByName('POSTISHIPPING_API_VALID');

            return false;
        }

        try {
            $postiApi = new PostiApi('production', $apiKey, $apiSecret);
            if ($postiApi->testCredentials()) {
                Configuration::updateValue('POSTISHIPPING_API_VALID', 1);

                return true;
            }
        } catch (\Throwable $th) {
            $this->_html .= $this->displayError(
                $this->trans('Failed to validate credentials, please check your API key and secret', [], 'Modules.Postishipping.Admin')
            );
        }

        Configuration::deleteByName('POSTISHIPPING_API_VALID');

        return false;
    }

    public function getContent()
    {
        $this->_html = '';

        if (!Module::isEnabled($this->name)) {
            return $this->displayError(
                $this->trans('This module is currently disabled. Please enable it first to access its settings.', [], 'Modules.Postishipping.Admin')
            );
        }

        // After a file update + redirect, run pending upgrade scripts and
        // sync the DB version before showing any content.
        $this->runPendingUpgrade();

        // Show success message after module file update + redirect
        $postiUpdated = Tools::getValue('postiUpdated');
        if ($postiUpdated) {
            $this->_html .= $this->displayConfirmation(
                sprintf($this->trans('Module updated successfully to version %s.', [], 'Modules.Postishipping.Admin'), htmlspecialchars($postiUpdated, ENT_QUOTES, 'UTF-8'))
            );
        }

        if (!is_dir(__DIR__ . '/vendor')) {
            return $this->displayError(
                $this->trans('The "vendor" directory is missing. Please run "composer install" in the module directory or re-upload the module with all dependencies.', [], 'Modules.Postishipping.Admin')
            );
        }

        if (Tools::isSubmit('updatepostishippingcarrier')) {
            $this->_html .= $this->renderCarrierForm((int) Tools::getValue('id_postishipping_carrier'));
        } else {
            $this->_postProcess();

            // On submitApiConfig, _postProcess() already ran a fresh live check (and appended its error) above;
            // don't repeat the API call. Otherwise, run it now, before any other section, so the error message
            // always appears in the same place regardless of whether it came from a Save or a plain page load.
            $apiValid = Tools::isSubmit('submitApiConfig')
                ? (bool) Configuration::get('POSTISHIPPING_API_VALID')
                : $this->checkApiCredentials();

            $this->_html .= $this->renderUpdateSection();
            $this->_html .= $this->renderApiConfigForm();

            if ($apiValid) {
                $this->_html .= $this->renderCarrierList();
                $this->_html .= $this->renderSenderConfigForm();
                $this->_html .= $this->renderCodConfigForm();
                $this->_html .= $this->renderCheckoutConfigForm();
                $this->_html .= $this->renderShipmentConfigForm();
                $this->_html .= $this->renderPickupConfigForm();
            }
            $this->_html .= $this->renderOtherConfigForm();
        }

        $this->_setBaseUrl();

        return $this->_html;
    }

    private function _setBaseUrl()
    {
        $this->_baseUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ]);
    }

    private function createFormHelper(array $fieldsValues, array $options = []): HelperForm
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->name;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = $options['submit_action'] ?? 'submitPostiShippingConfiguration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false, [], ['configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name]);
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        if (!empty($options['show_cancel_button'])) {
            $helper->show_cancel_button = true;
        }

        $helper->tpl_vars = [
            'fields_value' => $fieldsValues,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->langId,
        ];

        return $helper;
    }

    public function renderUpdateSection(): string
    {
        $updater = new PostiUpdater($this->version);

        if (!$updater->isConfigured()) {
            return '';
        }

        $forceCheck = (bool) Tools::getValue('forceUpdateCheck');
        $updateInfo = $updater->checkForUpdates($forceCheck);
        $hasUpdate = $updateInfo && $updateInfo['has_update'];
        $checkFailed = $updateInfo === null;

        // Show nothing if up to date
        if (!$hasUpdate && !$checkFailed) {
            return '';
        }

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-refresh"></i> '
            . $this->trans('Module updates', [], 'Modules.Postishipping.Admin') . '</div>';

        if ($checkFailed) {
            $html .= '<div class="alert alert-warning">'
                . $this->trans('Failed to check for module updates. Please try again later.', [], 'Modules.Postishipping.Admin')
                . '</div>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<div class="alert alert-info">';
        $html .= '<p>' . sprintf(
            $this->trans('A new version %s is available! (current: %s)', [], 'Modules.Postishipping.Admin'),
            '<strong>' . htmlspecialchars($updateInfo['version'], ENT_QUOTES, 'UTF-8') . '</strong>',
            '<strong class="text-muted">' . htmlspecialchars($this->version, ENT_QUOTES, 'UTF-8') . '</strong>'
        ) . '</p>';
        $html .= '</div>';

        $formAction = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ]);

        $html .= '<form method="post" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<button type="submit" name="submitModuleUpdate" class="btn btn-primary" '
            . 'onclick="return confirm(\'' . addslashes($this->trans('Are you sure you want to update the module?', [], 'Modules.Postishipping.Admin')) . '\');">' 
            . '<i class="icon-download" style="margin-right:5px;"></i>'
            . sprintf($this->trans('Update to %s', [], 'Modules.Postishipping.Admin'), htmlspecialchars($updateInfo['version'], ENT_QUOTES, 'UTF-8'))
            . '</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    public function renderApiConfigForm()
    {
        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('API configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-key',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('API Key', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_API_KEY',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('API Secret', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_API_SECRET',
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitApiConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getApiConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderSenderConfigForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Sender information', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-user',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender name', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_NAME',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender address', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_ADDRESS',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender postal code', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_POSTAL_CODE',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender city', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_CITY',
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Sender country', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_COUNTRY',
                        'required' => true,
                        'bootstrap' => true,
                        'options' => [
                            'query' => Country::getCountries($defaultLang, false),
                            'id' => 'iso_code',
                            'name' => 'name',
                            'default' => [
                                'value' => '',
                                'label' => '--',
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender phone', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_PHONE',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Sender email', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_EMAIL',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Info-code for shipments', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_SENDER_INFO_CODE',
                        'maxlength' => 15,
                        'desc' => $this->trans('Info-code length must be 15 characters or less', [], 'Modules.Postishipping.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitSenderConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getSenderConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderCodConfigForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $cod_modules = [];
        foreach (PaymentModule::getInstalledPaymentModules() as $module) {
            $moduleInstance = Module::getInstanceByName($module['name']);
            $displayName = $moduleInstance ? $moduleInstance->displayName : $module['name'];
            $cod_modules[] = [
                'id' => $module['name'],
                'name' => $displayName . ' <small class="text-muted">(' . $module['name'] . ')</small>',
            ];
        }

        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Cash on delivery (COD) configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-money',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Bank account (IBAN)', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_COD_ACCOUNT',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('BIC code', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_COD_CODBIC',
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('COD modules', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_COD_MODULES',
                        'desc' => $this->trans('Select payment modules that are for COD', [], 'Modules.Postishipping.Admin'),
                        'values' => [
                            'query' => $cod_modules,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitCodConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getCodConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderCheckoutConfigForm()
    {
        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Checkout configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-shopping-cart',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Pickup point search limit', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_CHECKOUT_PICKUP_LIMIT',
                        'desc' => $this->trans('Limit the amount of nearest pickup points shown', [], 'Modules.Postishipping.Admin'),
                        'class' => 'fixed-width-sm',
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Show pickup points as', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_CHECKOUT_SHOW_TYPE',
                        'options' => [
                            'query' => [
                                ['id' => 'menu', 'name' => $this->trans('Menu', [], 'Modules.Postishipping.Admin')],
                                ['id' => 'list', 'name' => $this->trans('List', [], 'Modules.Postishipping.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('Pickup points type', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_CHECKOUT_PP_TYPE',
                        'desc' => $this->trans('Choose which type of pickup points will be displayed in the list of pickup points', [], 'Modules.Postishipping.Admin'),
                        'values' => [
                            'query' => [
                                ['id' => 'PRIVATE_LOCKER', 'name' => $this->trans('Private lockers', [], 'Modules.Postishipping.Admin')],
                                ['id' => 'OUTDOOR_LOCKER', 'name' => $this->trans('Outdoor lockers', [], 'Modules.Postishipping.Admin')],
                                ['id' => 'PARCEL_LOCKER', 'name' => $this->trans('Parcel lockers', [], 'Modules.Postishipping.Admin')],
                                ['id' => 'PICKUP_POINT', 'name' => $this->trans('Pickup points', [], 'Modules.Postishipping.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitCheckoutConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getCheckoutConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderShipmentConfigForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Shipment registration configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-archive',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->trans('Shipping label size', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_LABEL_SIZE',
                        'options' => [
                            'query' => [
                                ['id' => 'A5', 'name' => 'A5'],
                                ['id' => '107x225', 'name' => '107x225'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Ignore product weight', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_IGNORE_PRODUCT_WEIGHT',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Modules.Postishipping.Admin'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Modules.Postishipping.Admin'),
                            ],
                        ],
                        'desc' => $this->trans('Ignore the specified product weight and assign a weight of 1 kg to each product unit', [], 'Modules.Postishipping.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Exclude products without HS code from CN23', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_EXCLUDE_NO_HS_CODE',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Modules.Postishipping.Admin')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Modules.Postishipping.Admin')
                            ]
                        ],
                        'desc' => $this->trans('Exclude products without HS tariff code from the CN23 customs document', [], 'Modules.Postishipping.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Express freight pallet type', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_PALLET_TYPE',
                        'options' => [
                            'query' => array_map(fn($code, $label) => [
                                'id' => $code,
                                'name' => $this->trans($label, [], 'Modules.Postishipping.Admin'),
                            ], array_keys(ShippingServiceConfig::PALLET_TYPES), ShippingServiceConfig::PALLET_TYPES),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    $this->getAdditionalTextOnLabelsField(),
                    [
                        'type' => 'select',
                        'label' => $this->trans('Label registered Order status', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_STATE_REGISTERED',
                        'options' => [
                            'query' => OrderState::getOrderStates($defaultLang),
                            'id' => 'id_order_state',
                            'name' => 'name',
                            'default' => [
                                'value' => '0',
                                'label' => '--',
                            ],
                        ],
                        'desc' => $this->trans('Order status to set after registering label. Select "--" to not change status.', [], 'Modules.Postishipping.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitShipmentConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getShipmentConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderPickupConfigForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Pickup configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-truck',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Pickup Sender ID', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_PICKUP_SENDER_ID',
                        'desc' => $this->trans('Sender ID for courier pickup orders (POSTRA XML)', [], 'Modules.Postishipping.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Pickup SAP Customer ID', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_PICKUP_CUSTOMER_ID',
                        'desc' => $this->trans('SAP Customer account ID for courier pickup orders', [], 'Modules.Postishipping.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Pickup SAP Invoice ID', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_PICKUP_INVOICE_ID',
                        'desc' => $this->trans('SAP Invoice account ID for courier pickup orders', [], 'Modules.Postishipping.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitPickupConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getPickupConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    private function getAdditionalTextOnLabelsField()
    {
        $shortcodes = [
            'ORDER_ID' => $this->trans('Order ID', [], 'Modules.Postishipping.Admin'),
            'ORDER_NUMBER' => $this->trans('Order number', [], 'Modules.Postishipping.Admin'),
            'ORDER_NOTE' => $this->trans('Order note', [], 'Modules.Postishipping.Admin'),
            'PRODUCTS_NAMES' => $this->trans('Product names', [], 'Modules.Postishipping.Admin'),
            'PRODUCTS_NAME_WITH_QUANTITY' => $this->trans('Product names with quantity', [], 'Modules.Postishipping.Admin'),
            'PRODUCTS_SKU' => $this->trans('Product SKU codes', [], 'Modules.Postishipping.Admin'),
            'PRODUCTS_SKU_WITH_QUANTITY' => $this->trans('Product SKU codes with quantity', [], 'Modules.Postishipping.Admin'),
        ];

        $shortcodeHtml = '<div class="posti-shortcodes" style="margin-top: 5px;">';
        foreach ($shortcodes as $code => $label) {
            $shortcodeHtml .= '<span class="posti-shortcode-btn" '
                . 'data-shortcode="{' . $code . '}" '
                . 'style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; '
                . 'background: #f5f5f5; border: 1px solid #ccc; border-radius: 3px; cursor: pointer; font-family: monospace; font-size: 12px;" '
                . 'title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
                . '<code>{' . $code . '}</code></span> '
                . '<span style="font-size: 12px; color: #666;">' . htmlspecialchars($label, ENT_QUOTES) . '</span><br/>';
        }
        $shortcodeHtml .= '</div>';

        return [
            'type' => 'textarea',
            'label' => $this->trans('Add additional text on labels', [], 'Modules.Postishipping.Admin'),
            'name' => 'POSTISHIPPING_LABEL_ADDITIONAL_INFO',
            'cols' => 60,
            'rows' => 3,
            'desc' => $this->trans('Click on a shortcode to insert it into the field:', [], 'Modules.Postishipping.Admin') . $shortcodeHtml,
        ];
    }

    public function renderOtherConfigForm()
    {
        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Other configuration', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Delete module data', [], 'Modules.Postishipping.Admin'),
                        'name' => 'POSTISHIPPING_DELETE_DATA',
                        'is_bool' => true,
                        'desc' => $this->trans('Delete all module data when the module is uninstalled', [], 'Modules.Postishipping.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Modules.Postishipping.Admin'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Modules.Postishipping.Admin'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitOtherConfig',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getOtherConfigFieldsValues());

        return $helper->generateForm([$fields_form_1]);
    }

    public function renderCarrierList()
    {
        $carriers = $this->get('postishipping.module.repository.carrier_repository')->getCarriers($this->langId, false, false);

        try {
            $posti = new PostiApi();
            $shippingMethods = $posti->getShippingMethods();

            $postiShippingMethodsNames = [];
            foreach ($shippingMethods as $key => $value) {
                $postiShippingMethodsNames[$value->shipping_method_code] = $value->getName();
            }
        } catch (\Throwable $th) {
            return $this->displayError($th->getMessage());
        }

        Media::addJsDef([
            'PostiShippingMethodsNames' => $postiShippingMethodsNames ?? [],
        ]);

        $deletedCarrierIds = [];
        $carriers = array_map(function ($item) use ($postiShippingMethodsNames, &$deletedCarrierIds) {
            $item['posti_service'] = $item['id_posti_service'] . ' - ' . ($postiShippingMethodsNames[$item['id_posti_service']] ?? '');

            if ($item['id_ps_carrier'] === null) {
                $deletedCarrierIds[] = $item['id_postishipping_carrier'];
            }

            return $item;
        }, $carriers);

        $fields_list = [
            'id_postishipping_carrier' => [
                'title' => $this->trans('ID', [], 'Modules.Postishipping.Admin'),
                'type' => 'text',
                'class' => 'fixed-width-xs center',
                'search' => false,
            ],
            'id_ps_carrier' => [
                'title' => $this->trans('PS Carrier ID', [], 'Modules.Postishipping.Admin'),
                'type' => 'text',
                'class' => 'fixed-width-xs center',
                'search' => false,
            ],
            'name' => [
                'title' => $this->trans('Name', [], 'Modules.Postishipping.Admin'),
                'type' => 'text',
                'search' => false,
            ],
            'posti_service' => [
                'title' => $this->trans('Posti Service ID', [], 'Modules.Postishipping.Admin'),
                'type' => 'text',
                'search' => false,
            ],
            'active' => [
                'title' => $this->trans('Status', [], 'Modules.Postishipping.Admin'),
                'active' => 'status',
                'type' => 'bool',
                'class' => 'fixed-width-xs center',
                'search' => false,
            ],
        ];

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->actions = ['edit', 'delete'];
        $helper->list_skip_actions = ['edit' => $deletedCarrierIds];
        $helper->show_toolbar = true;
        $helper->toolbar_btn['new'] = [
            'href' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'module_name' => $this->name, 'updatepostishippingcarrier' => '']),
            'desc' => $this->trans('Add New Carrier', [], 'Modules.Postishipping.Admin'),
        ];
        $helper->module = $this;
        $helper->identifier = 'id_postishipping_carrier';
        $helper->title = $this->trans('Posti Carriers', [], 'Modules.Postishipping.Admin');
        $helper->title_icon = 'icon-truck';
        $helper->table = $this->name . 'carrier';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;

        $helper->listTotal = count($carriers);

        $addCarrierUrl = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'module_name' => $this->name, 'updatepostishippingcarrier' => '']);
        $addCarrierLabel = $this->trans('Add New Carrier', [], 'Modules.Postishipping.Admin');

        $this->context->smarty->assign('posti_footer_btn', [
            'href' => $addCarrierUrl,
            'desc' => $addCarrierLabel,
            'icon' => 'process-icon-new',
        ]);

        return $helper->generateList($carriers, $fields_list);
    }

    public function getPostiServiceName($obj)
    {
        return json_encode($obj);
    }

    public function getApiConfigFieldsValues()
    {
        return [
            'POSTISHIPPING_API_KEY' => Tools::getValue('POSTISHIPPING_API_KEY', Configuration::get('POSTISHIPPING_API_KEY')),
            'POSTISHIPPING_API_SECRET' => Tools::getValue('POSTISHIPPING_API_SECRET', Configuration::get('POSTISHIPPING_API_SECRET')),
        ];
    }

    public function getSenderConfigFieldsValues()
    {
        return [
            'POSTISHIPPING_SENDER_NAME' => Tools::getValue('POSTISHIPPING_SENDER_NAME', Configuration::get('POSTISHIPPING_SENDER_NAME')),
            'POSTISHIPPING_SENDER_ADDRESS' => Tools::getValue('POSTISHIPPING_SENDER_ADDRESS', Configuration::get('POSTISHIPPING_SENDER_ADDRESS')),
            'POSTISHIPPING_SENDER_POSTAL_CODE' => Tools::getValue('POSTISHIPPING_SENDER_POSTAL_CODE', Configuration::get('POSTISHIPPING_SENDER_POSTAL_CODE')),
            'POSTISHIPPING_SENDER_CITY' => Tools::getValue('POSTISHIPPING_SENDER_CITY', Configuration::get('POSTISHIPPING_SENDER_CITY')),
            'POSTISHIPPING_SENDER_COUNTRY' => Tools::getValue('POSTISHIPPING_SENDER_COUNTRY', Configuration::get('POSTISHIPPING_SENDER_COUNTRY')),
            'POSTISHIPPING_SENDER_PHONE' => Tools::getValue('POSTISHIPPING_SENDER_PHONE', Configuration::get('POSTISHIPPING_SENDER_PHONE')),
            'POSTISHIPPING_SENDER_EMAIL' => Tools::getValue('POSTISHIPPING_SENDER_EMAIL', Configuration::get('POSTISHIPPING_SENDER_EMAIL')),
            'POSTISHIPPING_SENDER_INFO_CODE' => Tools::getValue('POSTISHIPPING_SENDER_INFO_CODE', Configuration::get('POSTISHIPPING_SENDER_INFO_CODE')),
        ];
    }

    public function getCodConfigFieldsValues()
    {
        $values = [
            'POSTISHIPPING_COD_ACCOUNT' => Tools::getValue('POSTISHIPPING_COD_ACCOUNT', Configuration::get('POSTISHIPPING_COD_ACCOUNT')),
            'POSTISHIPPING_COD_CODBIC' => Tools::getValue('POSTISHIPPING_COD_CODBIC', Configuration::get('POSTISHIPPING_COD_CODBIC')),
        ];

        $codModulesRaw = Configuration::get('POSTISHIPPING_COD_MODULES') ?? '';
        $savedModules = ($codModulesRaw !== '') ? explode(',', $codModulesRaw) : [];
        foreach ($savedModules as $moduleName) {
            $values['POSTISHIPPING_COD_MODULES_' . $moduleName] = 'on';
        }
        foreach (PaymentModule::getInstalledPaymentModules() as $payModule) {
            $key = 'POSTISHIPPING_COD_MODULES_' . $payModule['name'];
            $val = Tools::getValue($key);
            if ($val) {
                $values[$key] = $val;
            }
        }

        return $values;
    }

    public function getCheckoutConfigFieldsValues()
    {
        $allPpTypes = ['PRIVATE_LOCKER', 'OUTDOOR_LOCKER', 'PARCEL_LOCKER', 'PICKUP_POINT'];
        $ppTypesRaw = Configuration::get('POSTISHIPPING_CHECKOUT_PP_TYPE');
        $savedTypes = ($ppTypesRaw !== false && $ppTypesRaw !== '') ? explode('|', $ppTypesRaw) : $allPpTypes;

        $values = [
            'POSTISHIPPING_CHECKOUT_PICKUP_LIMIT' => Tools::getValue('POSTISHIPPING_CHECKOUT_PICKUP_LIMIT', Configuration::get('POSTISHIPPING_CHECKOUT_PICKUP_LIMIT') ?: 5),
            'POSTISHIPPING_CHECKOUT_SHOW_TYPE' => Tools::getValue('POSTISHIPPING_CHECKOUT_SHOW_TYPE', Configuration::get('POSTISHIPPING_CHECKOUT_SHOW_TYPE') ?: 'menu'),
        ];

        foreach ($savedTypes as $ppType) {
            $values['POSTISHIPPING_CHECKOUT_PP_TYPE_' . $ppType] = 'on';
        }
        foreach ($allPpTypes as $ppType) {
            $key = 'POSTISHIPPING_CHECKOUT_PP_TYPE_' . $ppType;
            $val = Tools::getValue($key);
            if ($val) {
                $values[$key] = $val;
            }
        }

        return $values;
    }

    public function getShipmentConfigFieldsValues()
    {
        return [
            'POSTISHIPPING_LABEL_SIZE' => Tools::getValue('POSTISHIPPING_LABEL_SIZE', Configuration::get('POSTISHIPPING_LABEL_SIZE') ?: 'A5'),
            'POSTISHIPPING_IGNORE_PRODUCT_WEIGHT' => Tools::getValue('POSTISHIPPING_IGNORE_PRODUCT_WEIGHT', Configuration::get('POSTISHIPPING_IGNORE_PRODUCT_WEIGHT')),
            'POSTISHIPPING_EXCLUDE_NO_HS_CODE' => Tools::getValue('POSTISHIPPING_EXCLUDE_NO_HS_CODE', Configuration::get('POSTISHIPPING_EXCLUDE_NO_HS_CODE')),
            'POSTISHIPPING_PALLET_TYPE' => Tools::getValue('POSTISHIPPING_PALLET_TYPE', Configuration::get('POSTISHIPPING_PALLET_TYPE') ?: ShippingServiceConfig::DEFAULT_PALLET_TYPE),
            'POSTISHIPPING_STATE_REGISTERED' => Tools::getValue('POSTISHIPPING_STATE_REGISTERED', Configuration::get('POSTISHIPPING_STATE_REGISTERED')),
            'POSTISHIPPING_LABEL_ADDITIONAL_INFO' => Tools::getValue('POSTISHIPPING_LABEL_ADDITIONAL_INFO', Configuration::get('POSTISHIPPING_LABEL_ADDITIONAL_INFO')),
        ];
    }

    public function getPickupConfigFieldsValues()
    {
        return [
            'POSTISHIPPING_PICKUP_SENDER_ID' => Tools::getValue('POSTISHIPPING_PICKUP_SENDER_ID', Configuration::get('POSTISHIPPING_PICKUP_SENDER_ID')),
            'POSTISHIPPING_PICKUP_CUSTOMER_ID' => Tools::getValue('POSTISHIPPING_PICKUP_CUSTOMER_ID', Configuration::get('POSTISHIPPING_PICKUP_CUSTOMER_ID')),
            'POSTISHIPPING_PICKUP_INVOICE_ID' => Tools::getValue('POSTISHIPPING_PICKUP_INVOICE_ID', Configuration::get('POSTISHIPPING_PICKUP_INVOICE_ID')),
        ];
    }

    public function getOtherConfigFieldsValues()
    {
        return [
            'POSTISHIPPING_DELETE_DATA' => Tools::getValue('POSTISHIPPING_DELETE_DATA', Configuration::get('POSTISHIPPING_DELETE_DATA')),
        ];
    }

    public function renderCarrierForm($id_carrier = 0)
    {
        if ((int) $id_carrier > 0) {
            $carrierRepository = $this->get('postishipping.module.repository.carrier_repository');
            $carrier = $carrierRepository->find($id_carrier);

            if (!$carrier || !$carrier->getPsCarrier()) {
                return $this->displayError($this->trans('This Posti carrier cannot be edited because the associated PrestaShop carrier has been deleted.', [], 'Modules.Postishipping.Admin'));
            }
        }

        try {
            $posti = new PostiApi();
            $shippingMethods = $posti->getShippingMethods();
        } catch (\Throwable $th) {
            return $this->displayError($th->getMessage());
        }
        $query = [];
        $postiShippingMethods = [];
        foreach ($shippingMethods as $key => $value) {
            $query[] = [
                'id' => $value->shipping_method_code,
                'label' => $value->getName(),
            ];

            $postiShippingMethods[$value->shipping_method_code] = base64_encode($value->getRaw());
        }

        Media::addJsDef([
            'PostiShippingMethods' => $postiShippingMethods,
            'PostiShippingPickupPointServiceCode' => AdditionalServicePickupPoint::CODE,
        ]);

        $fields_form_1 = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Carrier options', [], 'Modules.Postishipping.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'hidden',
                        'name' => 'id_postishipping_carrier',
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'service_base64',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Carrier name', [], 'Modules.Postishipping.Admin'),
                        'name' => 'name',
                        'readonly' => ((int) $id_carrier > 0),
                    ],
                    [
                        'type' => 'select',
                        'name' => 'id_service',
                        'label' => $this->trans('Posti service', [], 'Modules.Postishipping.Admin'),
                        'options' => [
                            'query' => $query,
                            'id' => 'id',
                            'name' => 'label',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->trans('Active', [], 'Modules.Postishipping.Admin'),
                        'name' => 'active',
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Modules.Postishipping.Admin'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Modules.Postishipping.Admin'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'html',
                        'label' => $this->trans('Supported Countries', [], 'Modules.Postishipping.Admin'),
                        'name' => 'posti_supported_countries_container',
                        'html_content' => '<div id="posti_supported_countries_container"></div>',
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Postishipping.Admin'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitEditCarrier',
                ],
            ],
        ];

        $helper = $this->createFormHelper($this->getCarrierFieldsValues($id_carrier), [
            'submit_action' => 'submitEditCarrier',
            'show_cancel_button' => true,
        ]);

        return $helper->generateForm([$fields_form_1]);
    }

    public function getCarrierFieldsValues(int $id = 0)
    {
        $carrierRepository = $this->get('postishipping.module.repository.carrier_repository');

        if ($id > 0) {
            /** @var \Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier */
            $carrier = $carrierRepository->find($id);
        } else {
            Media::addJsDef([
                'PostiShippingSelectedAddServices' => [],
                'PostiShippingSelectedAddServicesSpec' => [],
            ]);
            return [
                'name' => PostishippingCarrier::DEFAULT_NAME,
                'id_service' => null,
                'active' => false,
                'id_postishipping_carrier' => 0,
                'service_base64' => '',
            ];
        }

        Media::addJsDef([
            'PostiShippingSelectedAddServices' => $carrier->getSelectedAdditionalServices(),
            'PostiShippingSelectedAddServicesSpec' => $carrier->getSelectedAdditionalServicesSpec(),
        ]);

        return [
            'name' => $carrier->getPsCarrier()->name,
            'service_base64' => $carrier->getPostiServiceData(),
            'id_service' => $carrier->getPostiServiceId(),
            'active' => $carrier->isActive(),
            'id_postishipping_carrier' => $id,
        ];
    }

    /**
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function addCarrier($name, $active = false)
    {
        $carrier = new Carrier();

        $carrier->name = $name;
        $carrier->is_module = true;
        $carrier->active = (bool) $active;
        $carrier->range_behavior = false;
        $carrier->need_range = true;
        $carrier->shipping_handling = false;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_DEFAULT;
        $carrier->position = Carrier::getHigherPosition() + 1;

        foreach (Language::getLanguages() as $lang) {
            $carrier->delay[$lang['id_lang']] = '1-2 business days';
        }

        if ($carrier->add() === true) {
            try {
                $image_path = __DIR__ . '/logo.png';
                copy($image_path, _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');
            } catch (Exception $e) {
            }

            return $carrier;
        }

        return false;
    }

    protected function addGroups($carrier)
    {
        // Group assign
        $carrier->setGroups(array_column(Group::getGroups($this->context->language->id), 'id_group'));
        // Shop assign
        if (Shop::isTableAssociated('carrier')) {
            $assos_data = Shop::getShops(false, null, true);
            $exclude_ids = $assos_data;
            foreach (Db::getInstance()->executeS('SELECT id_shop FROM ' . _DB_PREFIX_ . 'shop') as $row) {
                if (!$this->context->employee->hasAuthOnShop($row['id_shop'])) {
                    $exclude_ids[] = $row['id_shop'];
                }
            }
            Db::getInstance()->delete('carrier_shop', '`id_carrier` = ' . (int) $carrier->id . ($exclude_ids ? ' AND id_shop NOT IN (' . implode(', ', array_map('intval', $exclude_ids)) . ')' : ''));
            $insert = [];
            foreach ($assos_data as $id_shop) {
                $insert[] = [
                    'id_carrier' => (int) $carrier->id,
                    'id_shop' => (int) $id_shop,
                ];
            }
            Db::getInstance()->insert('carrier_shop', $insert, false, true, Db::INSERT_IGNORE);
        }
    }

    protected function addRanges($carrier)
    {
        $range_price = new RangePrice();
        $range_price->id_carrier = $carrier->id;
        $range_price->delimiter1 = '0';
        $range_price->delimiter2 = '10000';
        $range_price->add();

        $range_weight = new RangeWeight();
        $range_weight->id_carrier = $carrier->id;
        $range_weight->delimiter1 = '0';
        $range_weight->delimiter2 = '10000';
        $range_weight->add();
    }

    protected function addZones($carrier)
    {
        $zones = Zone::getZones();

        foreach ($zones as $zone)
            $carrier->addZone($zone['id_zone']);
    }

    public function getConfiguredCarriers()
    {
        if ($this->configuredCarriers) {
            return $this->configuredCarriers;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
        $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

        $configuredCarriers = $postiCarrierRepository->findAll();

        $this->configuredCarriers = [];
        foreach ($configuredCarriers as $configuredCarrier) {
            $psCarrier = $configuredCarrier->getPsCarrier();
            if (!$psCarrier) {
                continue;
            }
            $this->configuredCarriers[$psCarrier->id] = $configuredCarrier;
        }

        return $this->configuredCarriers;
    }

    public function hookDisplayCarrierExtraContent($params)
    {

        $id_reference = $params['carrier']['id_reference'] ?? null;
        $id_carrier = $params['carrier']['id'] ?? null;

        if (!$id_reference) {
            return;
        }

        // using caching in memory for current request due to prestashop calling this hook multiple times
        $cacheKey = 'postishipping_extra_content_' . $id_reference;
        if (Cache::isStored($cacheKey)) {
            return Cache::retrieve($cacheKey);
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
        $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

        $config = $postiCarrierRepository->findOneBy(['id_reference' => $id_reference]);

        // extra content is only for pickup points
        if (!$config || !$config->hasPickupPointsEnabled()) {
            Cache::store($cacheKey, '');
            return;
        }

        $postiShippingMethod = $config->getPostiServiceData(true);

        $showType = Configuration::get('POSTISHIPPING_CHECKOUT_SHOW_TYPE') ?: 'menu';

        $this->context->smarty->assign([
            'postiServiceProvider' => $postiShippingMethod->getServiceProvider(),
            'postiIdCarrier' => $id_carrier,
            'error' => $this->context->cookie->qaplapudoError ?? '',
            'showType' => $showType,
        ]);

        $html = $this->display(__FILE__, 'views/templates/front/displayCarrierExtraContent.tpl');
        Cache::store($cacheKey, $html);
        return $html;
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        if ($this->context->controller->php_self !== 'order') {
            return;
        }

        /** @var \Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier[] */
        $carriers = $this->getConfiguredCarriers();

        $pickupCarriersConfig = [];
        foreach ($carriers as $id => $carrier) {
            if (!$carrier->hasPickupPointsEnabled()) {
                continue;
            }

            $provider = $carrier->getPostiServiceData(true)->getServiceProvider() ?? '';

            $pickupCarriersConfig[$id] = [
                'provider' => $provider,
                'initialized' => false,
            ];
        }

        $deliveryAddress = new Address($this->context->cart->id_address_delivery);
        $selectedAddressParts = array_filter([trim($deliveryAddress->address1), trim($deliveryAddress->city)]);

        Media::addJsDef(['postishipping' => [
            'ajaxController' => $this->context->link->getModuleLink($this->name, 'ajax', ['ajax' => 1, 'token' => Tools::getToken(false)]),
            'selectedAddress' => implode(', ', $selectedAddressParts),
            'carriersConfig' => $pickupCarriersConfig,
            'selectPickupText' => $this->trans('Select a Pickup Point', [], 'Modules.Postishipping.Front'),
            'continueText' => $this->trans('Continue', [], 'Shop.Theme.Actions'),
            'showType' => Configuration::get('POSTISHIPPING_CHECKOUT_SHOW_TYPE') ?: 'menu',
            'privatePickupPointConfirm' => $this->trans('The pickup point you have chosen is a private locker. You will need a PIN code to access it. Do you want to continue?', [], 'Modules.Postishipping.Front'),
        ]]);

        $this->context->controller->registerStylesheet('postishipping-front-deliverystep', "modules/{$this->name}/views/css/postishipping_front.css");
        $this->context->controller->registerJavascript('postishipping-front-deliverystep', "modules/{$this->name}/views/js/postishipping_front_deliverystep.js", [
            'attribute' => 'defer',
        ]);
    }

    public function hookActionCarrierProcess($params)
    {
        $errors = [];
        $this->validateAndSaveOrder($params['cart']->id, $errors);
    }

    public function validateAndSaveOrder($idCart, &$errors = [])
    {
        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart)) {
            $errors[] = $this->trans('Id cart is not valid', [], 'Modules.Postishipping.Front');
            return false;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
        $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

        if (!$postiCarrierRepository->isPostishippingCarrier($cart->id_carrier)) {
            return true;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');

        $carrier = new Carrier($cart->id_carrier);

        $postiCarrier = $postiCarrierRepository->findOneBy(['id_reference' => $carrier->id_reference]);

        $cartMeta = $cartMetaRepository->find((int) $cart->id);
        if (!$cartMeta) {
            $cartMeta = new PostishippingCartMeta();
            $cartMeta->setIdCart((int) $cart->id);
        }

        // if meta carrier reference doesnt match carrier reference found in posti carrier settings, or meta is missing service data, need to change meta data
        if ((int) $cartMeta->getCarrierReferenceId() !== (int) $postiCarrier->getCarrierReference() || !$cartMeta->getPostiServiceId()) {
            $cartMeta->setCarrierDefaultData($postiCarrier);
        }

        // check if for this configuration exists selection
        $pickup_point_id = Tools::getValue('selected_pickup_point_' . $postiCarrier->getCarrierReference());
        if ($pickup_point_id && (int) $carrier->id_reference === (int) $postiCarrier->getCarrierReference()) {
            if ($pickup_point_id === 'no_pickup') {
                $cartMeta->clearPickupPoint();
            } else {
                try {
                    $metaPickupPointId = $cartMeta->getPickupPointInfo()['pickup_point_id'] ?? 0;
                    if ((int) $pickup_point_id !== (int) $metaPickupPointId) {
                        $cartMeta->setPickupPointId($pickup_point_id);
                    }
                } catch (\Throwable $th) {
                    // if bad pickup point do nothing
                }
            }
        }

        $cartMetaRepository->updateGeneral($cartMeta);

        return true;
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        // we need delivery address for validating country
        $deliveryAddressId = $params->id_address_delivery ?? 0;
        if (!$deliveryAddressId) {
            return false;
        }

        // make sure credentials are valid
        if (!Configuration::get('POSTISHIPPING_API_VALID')) {
            return false;
        }

        $cacheKey = 'PostiShipping::getOrderShippingCost::' . $this->id_carrier . '::' . $deliveryAddressId;
        if (Cache::isStored($cacheKey)) {
            return Cache::retrieve($cacheKey);
        }

        $carriers = $this->getConfiguredCarriers();
        if (!isset($carriers[$this->id_carrier])) {
            Cache::store($cacheKey, false);
            return false;
        }

        // check if we have it disabled within module config
        if (!$carriers[$this->id_carrier]->isActive()) {
            Cache::store($cacheKey, false);
            return false;
        }

        try {
            $supportedCountries = $carriers[$this->id_carrier]->getPostiServiceData(true)->getSupportedCountries();
        } catch (\Throwable $th) {
            $supportedCountries = [];
        }

        $idCountry = Address::getCountryAndState($deliveryAddressId)['id_country'] ?? 0;
        $iso = Country::getIsoById($idCountry);

        if (!$iso || !in_array($iso, $supportedCountries)) {
            Cache::store($cacheKey, false);
            return false;
        }

        Cache::store($cacheKey, $shipping_cost);

        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return false;
    }

    public function hookActionValidateOrder($params)
    {
        // using this hook to remove cartMeta if order was created with other carriers
        $cart = $params['cart'] ?? null;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            return;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');
        $cartMeta = $cartMetaRepository->find((int) $cart->id);

        // postishipping was not used
        if (!$cartMeta) {
            return;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
        $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');

        // check if order was made with postishipping carrier
        if ($postiCarrierRepository->isPostishippingCarrier($cart->id_carrier)) {
            // Auto-add COD (3101) service if order payment module is in configured COD modules
            $this->autoAddCodService($params, $cartMeta, $cartMetaRepository);
            return;
        }

        // if carrier was not identified as postishipping one, delete found meta
        $cartMetaRepository->delete($cartMeta);
    }

    /**
     * Automatically adds COD (3101) additional service if order was paid with a configured COD payment module.
     */
    private function autoAddCodService($params, PostishippingCartMeta $cartMeta, $cartMetaRepository)
    {
        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        $codModulesRaw = Configuration::get('POSTISHIPPING_COD_MODULES');
        if (empty($codModulesRaw)) {
            return;
        }

        $codModules = array_map('trim', explode(',', $codModulesRaw));
        if (!in_array($order->module, $codModules, true)) {
            return;
        }

        $codSpec = [
            'amount' => round((float) $order->total_paid_tax_incl, 2),
            'account' => Configuration::get('POSTISHIPPING_COD_ACCOUNT') ?: '',
            'reference' => PostiHelper::generateCodReference($order->id),
            'codbic' => Configuration::get('POSTISHIPPING_COD_CODBIC') ?: '',
        ];

        $cartMeta->addAdditionalServiceToAll(AdditionalServiceCod::CODE, $codSpec);
        $cartMetaRepository->update($cartMeta);
    }

    /**
     * Returns version update info array if an update is available, or null.
     * Uses PostiUpdater's built-in 1-hour cache to minimize external requests.
     */
    private function getVersionUpdateNotification(): ?array
    {
        try {
            $updater = new PostiUpdater($this->version);
            if (!$updater->isConfigured()) {
                return null;
            }
            $result = $updater->checkForUpdates(false);
            if ($result && !empty($result['has_update'])) {
                return [
                    'version' => $result['version'],
                    'changelog' => $result['changelog'] ?? '',
                    'settingsUrl' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
                ];
            }
        } catch (\Throwable $e) {
            // Silently ignore errors — don't break the page for a notification
        }

        return null;
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        try {
            return $this->renderPostiOrderPanel($params);
        } catch (\Throwable $e) {
            return '<div class="alert alert-danger">Posti Shipping: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }

    /**
     * Returns the inner order block HTML + JS state for AJAX refresh (no page reload).
     */
    public function getPostiOrderBlockData(int $orderId): array
    {
        return $this->renderPostiOrderPanel(['id_order' => $orderId, 'posti_ajax' => true]);
    }

    private function renderPostiOrderPanel($params)
    {
        // Check PostishippingPostiOrderController view permission (Team > Permissions)
        $postiAccess = PostiOrderController::getEmployeeAccess();
        if (!$postiAccess['view']) {
            return;
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');

        $order = new Order($params['id_order']);
        $cartMeta = $cartMetaRepository->find((int) $order->id_cart);

        if (!$cartMeta) {
            return;
        }

        $orderTotal = round($order->total_paid_tax_incl, 2);
        $metaSaved = !empty($cartMeta->getMeta()['metaSaved']);

        $template = "@Modules/{$this->name}/views/templates/admin/order_main.html.twig";

        $router = $this->get('router');

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingManifestRepository */
        $manifestRepository = $this->get('postishipping.module.repository.manifest');
        $orderTrackingCodes = array_column($cartMeta->getRegistrations(), 'trackingCode');
        $pickupTrackingCodes = $manifestRepository->filterPickupTrackingCodes($orderTrackingCodes);

        // Calculate DG weight and build order products list in a single pass
        $dgTotalWeight = 0;
        $dgProductCount = 0;
        $orderProductsList = [];
        $orderProducts = $order->getProducts();
        if (!empty($orderProducts)) {
            $productIds = array_column($orderProducts, 'product_id');
            $dgMap = [];
            if (!empty($productIds)) {
                $productIdsStr = implode(',', array_map('intval', $productIds));
                $dgRows = Db::getInstance()->executeS(
                    'SELECT `id_product`, `dg_weight` FROM `' . _DB_PREFIX_ . 'postishipping_product_extra`
                    WHERE `id_product` IN (' . $productIdsStr . ') AND `dg_weight` > 0'
                );
                foreach ($dgRows ?: [] as $dgRow) {
                    $dgMap[(int) $dgRow['id_product']] = (int) $dgRow['dg_weight'];
                }
            }

            foreach ($orderProducts as $row) {
                $pid = (int) $row['product_id'];
                $qty = (int) $row['product_quantity'];

                if (isset($dgMap[$pid])) {
                    $dgTotalWeight += $dgMap[$pid] * $qty;
                    $dgProductCount += $qty;
                }

                $orderProductsList[] = [
                    'id_product' => $pid,
                    'id_product_attribute' => (int) ($row['product_attribute_id'] ?? 0),
                    'name' => $row['product_name'] ?? '',
                    'reference' => $row['product_reference'] ?? '',
                    'quantity' => $qty,
                ];
            }
        }

        $lqweight = $dgTotalWeight > 0 ? round($dgTotalWeight / 1000, 3) : 0;

        // Load carrier defaults for the default registration display
        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
        $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');
        $postiCarrier = $postiCarrierRepository->findOneBy(['id_reference' => $cartMeta->getCarrierReferenceId()]);

        $defaultServiceName = '';
        $defaultServiceCode = $cartMeta->getDefaultPostiServiceId() ?: '';
        $defaultAdditionalServiceCodes = $cartMeta->getDefaultServices();
        $defaultShippingMethodBase64 = $cartMeta->getDefaultShippingMethodBase64();

        // Fetch supported additional service codes for the default service
        $supportedAdditionalServiceCodes = [];
        try {
            $postiApi = new PostiApi();
            $allMethods = $postiApi->getShippingMethods();
            if ($defaultServiceCode && isset($allMethods[$defaultServiceCode])) {
                foreach ($allMethods[$defaultServiceCode]->getAdditionalServices() as $supportedSvc) {
                    $supportedAdditionalServiceCodes[] = $supportedSvc->getCode();
                }
            }
        } catch (\Throwable $e) {
            // If API call fails, skip filtering
        }

        // Filter default additional services by what the service actually supports
        if (!empty($supportedAdditionalServiceCodes)) {
            $defaultAdditionalServiceCodes = array_values(array_filter(
                $defaultAdditionalServiceCodes,
                function ($code) use ($supportedAdditionalServiceCodes) {
                    return in_array((string) $code, $supportedAdditionalServiceCodes, true);
                }
            ));
        }

        if ($postiCarrier) {
            $defaultShippingMethod = $postiCarrier->getPostiServiceData(true);
            $defaultServiceName = $defaultShippingMethod ? $defaultShippingMethod->getName() : '';
            if (!$defaultServiceCode) {
                $defaultServiceCode = $postiCarrier->getPostiServiceId();
            }
        } else {
            // Fallback: use current shipping method name
            $defaultServiceName = $cartMeta->getShippingMethod() ? $cartMeta->getShippingMethod()->getName() : '';
        }

        // Auto-include DG service if it will be auto-added at registration time (only if service supports it)
        $serviceSupportsDg = empty($supportedAdditionalServiceCodes) || in_array(AdditionalServiceDangerousGoods::CODE, $supportedAdditionalServiceCodes, true);
        if ($dgTotalWeight > 0 && !in_array(AdditionalServiceDangerousGoods::CODE, $defaultAdditionalServiceCodes) && $serviceSupportsDg) {
            $defaultAdditionalServiceCodes[] = AdditionalServiceDangerousGoods::CODE;
        }

        // Build per-service display info for the Default Registration section
        $defaultServicesInfo = [];
        $originalPickupPointId = $cartMeta->getOriginalPickupPointId();
        if ($originalPickupPointId) {
            $defaultServicesInfo[AdditionalServicePickupPoint::CODE] = '#' . $originalPickupPointId;
        }
        if (in_array(AdditionalServiceCod::CODE, $defaultAdditionalServiceCodes)) {
            $currency = new \Currency($order->id_currency);
            $currencySign = $currency->sign ?: $currency->iso_code;
            $defaultServicesInfo[AdditionalServiceCod::CODE] = number_format((float) $orderTotal, 2, '.', '') . ' ' . $currencySign;
        }
        if ($lqweight > 0) {
            $defaultServicesInfo[AdditionalServiceDangerousGoods::CODE] = 'Lqweight: ' . $lqweight . ' kg, Lqcount: ' . $dgProductCount;
        }

        $shippingAddress = AddressFormat::generateAddress(new Address($order->id_address_delivery), ['avoid' => ['firstname', 'lastname', 'company', 'vat_number', 'phone', 'phone_mobile', 'dni', 'Customer:company']], ', ');
        $codData = [
            'serviceCode' => AdditionalServiceCod::CODE,
            'account' => Configuration::get('POSTISHIPPING_COD_ACCOUNT', null, null, null, null) ?? '',
            'codbic' => Configuration::get('POSTISHIPPING_COD_CODBIC', null, null, null, null) ?? '',
            'reference' => PostiHelper::generateCodReference($order->id),
            'amount' => $orderTotal,
        ];
        $dgData = [
            'serviceCode' => AdditionalServiceDangerousGoods::CODE,
            'lqweight' => $lqweight,
            'lqcount' => $dgProductCount,
            'autoSelect' => $dgTotalWeight > 0 && !$metaSaved,
        ];
        $defaultRegistrationData = [
            'serviceName' => $defaultServiceName,
            'serviceCode' => $defaultServiceCode,
            'additionalServiceCodes' => $defaultAdditionalServiceCodes,
            'shippingMethodBase64' => $defaultShippingMethodBase64,
            'pickupPointInfo' => $cartMeta->getOriginalPickupPointInfo(),
            'servicesInfo' => $defaultServicesInfo,
        ];

        $templateVars = [
            'ajaxController' => $router->generate('postishipping_posti_order'),
            'trackingNumber' => $order->getWsShippingNumber(),
            'cartMeta' => $cartMeta,
            'cartMetaId' => $cartMeta->getId(),
            'selectedServices' => $cartMeta->getServices(),
            'selectedServicesSpec' => $cartMeta->getServicesSpec(),
            'selectedPickupPoint' => $cartMeta->getPickupPointInfo(),
            'shippingMethod' => $cartMeta->getShippingMethod(),
            'customSpecifiersMap' => $cartMeta->getShippingMethod()->getCustomSpecifiersMap(),
            'shippingAddress' => $shippingAddress,
            'codData' => $codData,
            'orderTotal' => $orderTotal,
            'order' => $order,
            'pickupTrackingCodes' => $pickupTrackingCodes,
            'dgData' => $dgData,
            'defaultRegistration' => $defaultRegistrationData,
            'orderProducts' => $orderProductsList,
            'postiAccess' => $postiAccess,
            'versionUpdate' => $this->getVersionUpdateNotification(),
        ];

        if ($params['posti_ajax'] ?? false) {
            $blockHtml = $this->get('twig')->render(
                "@Modules/{$this->name}/views/templates/admin/order.html.twig",
                $templateVars
            );
            return [
                'html' => $blockHtml,
                'state' => [
                    'cartMeta' => $cartMeta,
                    'metaId' => $cartMeta->getId(),
                    'selectedServices' => $cartMeta->getServices(),
                    'selectedServicesSpec' => $cartMeta->getServicesSpec(),
                    'selectedPickupPoint' => $cartMeta->getPickupPointInfo(),
                    'shippingMethod' => $cartMeta->getShippingMethod(),
                    'codData' => $codData,
                    'dgData' => $dgData,
                    'customSpecifiersMap' => $cartMeta->getShippingMethod()->getCustomSpecifiersMap(),
                    'orderTotal' => $orderTotal,
                    'shippingAddress' => $shippingAddress,
                    'defaultRegistration' => $defaultRegistrationData,
                    'orderProducts' => $orderProductsList,
                    'postiAccess' => $postiAccess,
                ],
            ];
        }

        return $this->get('twig')->render($template, $templateVars);
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        // In PS 8.1+ use actionProductFormBuilderModifier instead (Symfony form fields)
        if (version_compare(_PS_VERSION_, '8.1.0', '>=')) {
            $shippingTabName = $this->trans('Shipping', [], 'Admin.Catalog.Feature');

            return '<div class="alert alert-info" style="margin-top: 1rem;">'
                . $this->trans(
                    'Module parameters have been moved to the %tab_name% tab.',
                    ['%tab_name%' => $shippingTabName],
                    'Modules.Postishipping.Admin'
                )
                . '</div>';
        }

        $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product', 0));

        $productExtra = [];
        if ($idProduct > 0) {
            $productExtra = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'postishipping_product_extra` WHERE `id_product` = ' . (int) $idProduct
            );
        }

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $this->context->smarty->assign([
            'posti_hs_tariff' => $productExtra['hs_tariff'] ?? '',
            'posti_country_of_origin' => $productExtra['country_of_origin'] ?? '',
            'posti_dg_weight' => $productExtra['dg_weight'] ?? '',
            'posti_countries' => Country::getCountries($defaultLang, false),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product_extra.tpl');
    }

    /**
     * PS 8.1+ product form: add Posti fields to the Shipping tab via Symfony FormBuilder.
     */
    public function hookActionProductFormBuilderModifier(array $params): void
    {
        $productId = (int) $params['id'];
        $formBuilder = $params['form_builder'];

        /** @var \PrestaShopBundle\Form\FormBuilderModifier $formBuilderModifier */
        $formBuilderModifier = $this->get('form.form_builder_modifier');

        $productExtra = [];
        if ($productId > 0) {
            $productExtra = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'postishipping_product_extra` WHERE `id_product` = ' . (int) $productId
            );
        }

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $countries = Country::getCountries($defaultLang, false);
        $countryChoices = ['--' => ''];
        foreach ($countries as $country) {
            $countryChoices[$country['name']] = $country['iso_code'];
        }

        $shippingTab = $formBuilder->get('shipping');

        $formBuilderModifier->addAfter(
            $shippingTab,
            'dimensions',
            'posti_customs',
            PostiProductExtraType::class,
            [
                'label' => $this->trans('Posti shipping', [], 'Modules.Postishipping.Admin'),
                'label_tag_name' => 'h3',
                'label_help_box' => $this->trans(
                    'This section is added by the %module_name% module',
                    ['%module_name%' => $this->displayName],
                    'Modules.Postishipping.Admin'
                ),
                'data' => [
                    'posti_hs_tariff' => $productExtra['hs_tariff'] ?? '',
                    'posti_country_of_origin' => $productExtra['country_of_origin'] ?? '',
                    'posti_dg_weight' => (int) ($productExtra['dg_weight'] ?? 0),
                ],
                'hs_tariff_label' => $this->trans('HS tariff number', [], 'Modules.Postishipping.Admin'),
                'hs_tariff_help' => $this->trans(
                    'The HS tariff number must be based on the Harmonized Commodity Description and Coding System developed by the World Customs Organization.',
                    [],
                    'Modules.Postishipping.Admin'
                ),
                'country_label' => $this->trans('Country of origin', [], 'Modules.Postishipping.Admin'),
                'country_help' => $this->trans(
                    'The country where the goods originated, e.g. were produced/manufactured or assembled.',
                    [],
                    'Modules.Postishipping.Admin'
                ),
                'country_choices' => $countryChoices,
                'dg_weight_label' => $this->trans('DG weight', [], 'Modules.Postishipping.Admin'),
                'dg_weight_help' => $this->trans(
                    'Amount of hazardous substances in the product as grams.',
                    [],
                    'Modules.Postishipping.Admin'
                ),
                'form_theme' => '@PrestaShop/Admin/TwigTemplateForm/prestashop_ui_kit_base.html.twig',
            ]
        );
    }

    /**
     * PS 8.1+ product form: save Posti fields after product update.
     */
    public function hookActionAfterUpdateProductFormHandler(array $params): void
    {
        $this->savePostiProductExtraFromFormData($params);
    }

    /**
     * PS 8.1+ product form: save Posti fields after product create.
     */
    public function hookActionAfterCreateProductFormHandler(array $params): void
    {
        $this->savePostiProductExtraFromFormData($params);
    }

    private function savePostiProductExtraFromFormData(array $params): void
    {
        $idProduct = (int) ($params['id'] ?? 0);
        if (!$idProduct) {
            return;
        }

        $formData = $params['form_data'] ?? [];
        $shipping = $formData['shipping'] ?? [];
        $postiCustoms = $shipping['posti_customs'] ?? [];

        if (!isset($postiCustoms['posti_hs_tariff'])
            && !isset($postiCustoms['posti_country_of_origin'])
            && !isset($postiCustoms['posti_dg_weight'])
        ) {
            return;
        }

        $hsTariff = pSQL($postiCustoms['posti_hs_tariff'] ?? '');
        $countryOfOrigin = pSQL($postiCustoms['posti_country_of_origin'] ?? '');
        $dgWeight = max(0, (int) ($postiCustoms['posti_dg_weight'] ?? 0));

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'postishipping_product_extra`
            (`id_product`, `hs_tariff`, `country_of_origin`, `dg_weight`)
            VALUES (' . (int) $idProduct . ', \'' . $hsTariff . '\', \'' . $countryOfOrigin . '\', ' . $dgWeight . ')
            ON DUPLICATE KEY UPDATE
            `hs_tariff` = \'' . $hsTariff . '\',
            `country_of_origin` = \'' . $countryOfOrigin . '\',
            `dg_weight` = ' . $dgWeight
        );
    }

    public function hookActionProductSave($params)
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if (!$idProduct) {
            return;
        }

        // Only process if at least one of our fields is present in POST
        if (!Tools::getIsset('posti_hs_tariff')
            && !Tools::getIsset('posti_country_of_origin')
            && !Tools::getIsset('posti_dg_weight')
        ) {
            return;
        }

        $hsTariff = pSQL(Tools::getValue('posti_hs_tariff', ''));
        $countryOfOrigin = pSQL(Tools::getValue('posti_country_of_origin', ''));
        $dgWeight = max(0, (int) Tools::getValue('posti_dg_weight', 0));

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'postishipping_product_extra`
            (`id_product`, `hs_tariff`, `country_of_origin`, `dg_weight`)
            VALUES (' . (int) $idProduct . ', \'' . $hsTariff . '\', \'' . $countryOfOrigin . '\', ' . $dgWeight . ')
            ON DUPLICATE KEY UPDATE
            `hs_tariff` = \'' . $hsTariff . '\',
            `country_of_origin` = \'' . $countryOfOrigin . '\',
            `dg_weight` = ' . $dgWeight
        );
    }

    /** FOR AJAX calls */
    public function getPickupPoints($id_cart, $provider, $address)
    {
        if (!$address || !Validate::isAddress($address)) {
            throw new PostiGenericException($this->trans('Not a valid address search', [], 'Modules.Postishipping.Front'));
        }

        if (!$provider || !Validate::isGenericName($provider)) {
            throw new PostiGenericException($this->trans('Invalid provider name', [], 'Modules.Postishipping.Front'));
        }

        $limit = (int) Configuration::get('POSTISHIPPING_CHECKOUT_PICKUP_LIMIT') ?: 5;
        $allPpTypes = ['PRIVATE_LOCKER', 'OUTDOOR_LOCKER', 'PARCEL_LOCKER', 'PICKUP_POINT'];
        $ppTypesRaw = Configuration::get('POSTISHIPPING_CHECKOUT_PP_TYPE');
        $ppTypes = ($ppTypesRaw !== false && $ppTypesRaw !== '') ? explode('|', $ppTypesRaw) : $allPpTypes;
        if (count($ppTypes) === count($allPpTypes) && empty(array_diff($allPpTypes, $ppTypes))) {
            $ppTypes = null;
        }

        // PICKUP_POINT checkbox also includes AGENCY type for API calls
        if ($ppTypes !== null && in_array('PICKUP_POINT', $ppTypes)) {
            $ppTypes[] = 'AGENCY';
        }

        $postiApi = new PostiApi();

        if ($ppTypes) {
            $ppTypeFilter = implode(',', $ppTypes);
            $list = $postiApi->getPickupPointsByAddress($provider, $address, $limit, $ppTypeFilter);
            if (!is_array($list)) {
                $list = [];
            }
        } else {
            $list = $postiApi->getPickupPointsByAddress($provider, $address, $limit);
        }

        /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCartMetaRepository */
        $cartMetaRepository = $this->get('postishipping.module.repository.cart_meta');
        $cartMeta = $cartMetaRepository->find((int) $id_cart);

        if (!$cartMeta) {
            $cart = new Cart((int) $id_cart);
            if (!Validate::isLoadedObject($cart)) {
                throw new PostiGenericException($this->trans('CartMeta not found', [], 'Modules.Postishipping.Front'));
            }

            /** @var \Posti\PrestaShop\Postishipping\Repository\PostishippingCarrierRepository */
            $postiCarrierRepository = $this->get('postishipping.module.repository.carrier_repository');
            $carrier = new Carrier($cart->id_carrier);
            $postiCarrier = $postiCarrierRepository->findOneBy(['id_reference' => $carrier->id_reference]);

            $cartMeta = new PostishippingCartMeta();
            $cartMeta->setIdCart((int) $cart->id);
            if ($postiCarrier) {
                $cartMeta->setCarrierDefaultData($postiCarrier);
            }
            $cartMetaRepository->updateGeneral($cartMeta);
        }

        $selectedPP = $cartMeta->getPickupPointInfo();

        $foundSelected = false;
        if ($selectedPP) {
            foreach ($list ?? [] as $point) {
                if ((int) $point->pickup_point_id === (int) $selectedPP['pickup_point_id']) {
                    $foundSelected = true;
                    break;
                }
            }
        }

        $showType = Configuration::get('POSTISHIPPING_CHECKOUT_SHOW_TYPE') ?: 'menu';

        $this->context->smarty->assign([
            'pickupList' => $list,
            'selectedPP' => $selectedPP ?: null,
            'uniqueIdSuffix' => uniqid(),
            'foundSelectedInList' => $foundSelected,
            'idReference' => $cartMeta->getCarrierReferenceId(),
            'error' => '',
            'showType' => $showType,
            'noPickupPointSelected' => $cartMeta->hasNoPickupPoint(),
        ]);

        return $this->fetch(
            $this->getLocalPath() . 'views/templates/front/_partials/pickuplist.tpl'
        );
    }

    /**
     * Adds pickup point template variables to all order-related emails:
     *  - {postishipping_pickup_point}       — pickup point address only
     *  - {postishipping_pickup_point_title}  — "Pickup point:" label + address (empty when no pickup point)
     */
    public function hookActionEmailSendBefore($params)
    {
        $params['templateVars']['{postishipping_pickup_point}'] = '';
        $params['templateVars']['{postishipping_pickup_point_title}'] = '';

        $idOrder = isset($params['templateVars']['{id_order}']) ? (int) $params['templateVars']['{id_order}'] : 0;
        if (!$idOrder) {
            return;
        }

        try {
            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            $row = Db::getInstance()->getRow(
                'SELECT `meta_data` FROM `' . _DB_PREFIX_ . 'postishipping_cart_meta` WHERE `id_cart` = ' . (int) $order->id_cart
            );

            if (!$row || empty($row['meta_data'])) {
                return;
            }

            $meta = json_decode($row['meta_data'], true);
            if (!is_array($meta)) {
                return;
            }

            $pickupPointInfo = !empty($meta['originalPickupPointInfo'])
                ? $meta['originalPickupPointInfo']
                : ($meta['pickupPointInfo'] ?? []);

            if (empty($pickupPointInfo)) {
                return;
            }

            $name = $pickupPointInfo['name'] ?? '';
            $streetAddress = $pickupPointInfo['street_address'] ?? '';
            $postcode = $pickupPointInfo['postcode'] ?? '';
            $city = $pickupPointInfo['city'] ?? '';

            $addressLine = implode(' ', array_filter([$postcode, $city]));
            $parts = array_filter([$name, $streetAddress, $addressLine]);
            $pickupPointFormatted = implode(', ', $parts);

            $params['templateVars']['{postishipping_pickup_point}'] = $pickupPointFormatted;
            $params['templateVars']['{postishipping_pickup_point_title}'] = $this->trans('Pickup point:', [], 'Modules.Postishipping.Front') . ' ' . $pickupPointFormatted;
        } catch (\Throwable $th) {
            \PrestaShopLogger::addLog(
                'PostiShipping hookActionEmailSendBefore: ' . $th->getMessage(),
                3,
                $th->getCode(),
                'PostiShipping',
                0,
                true
            );
        }
    }

    public function hookDisplayOrderConfirmation($params)
    {
        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return '';
        }

        try {
            $row = Db::getInstance()->getRow(
                'SELECT `meta_data` FROM `' . _DB_PREFIX_ . 'postishipping_cart_meta` WHERE `id_cart` = ' . (int) $order->id_cart
            );

            if (!$row || empty($row['meta_data'])) {
                return '';
            }

            $meta = json_decode($row['meta_data'], true);
            if (!is_array($meta)) {
                return '';
            }

            $pickupPointInfo = !empty($meta['originalPickupPointInfo'])
                ? $meta['originalPickupPointInfo']
                : ($meta['pickupPointInfo'] ?? []);

            if (empty($pickupPointInfo)) {
                return '';
            }

            $name = $pickupPointInfo['name'] ?? '';
            $streetAddress = $pickupPointInfo['street_address'] ?? '';
            $postcode = $pickupPointInfo['postcode'] ?? '';
            $city = $pickupPointInfo['city'] ?? '';

            $this->context->smarty->assign([
                'posti_pickup_name' => $name,
                'posti_pickup_street' => $streetAddress,
                'posti_pickup_postcode' => $postcode,
                'posti_pickup_city' => $city,
            ]);

            return $this->display(__FILE__, 'views/templates/front/displayOrderConfirmation.tpl');
        } catch (\Throwable $th) {
            \PrestaShopLogger::addLog(
                'PostiShipping hookDisplayOrderConfirmation: ' . $th->getMessage(),
                3,
                $th->getCode(),
                'PostiShipping',
                0,
                true
            );
            return '';
        }
    }

    /**
     * @param Order|int $id_order can be either order object or order id
     */
    public function changeOrderStatus($id_order, $status)
    {
        $order = (is_int($id_order) ? new Order((int) $id_order) : $id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        if ($order->current_state != (int) $status) {
            $history = new OrderHistory();
            $history->id_order = $order->id;
            $history->id_employee = (int) Context::getContext()->employee->id;
            $history->changeIdOrderState((int) $status, $order);
            $history->addWithemail(true);
        }
    }
}
