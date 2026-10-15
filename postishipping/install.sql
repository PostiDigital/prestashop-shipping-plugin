CREATE TABLE IF NOT EXISTS `PREFIX_postishipping_carrier` (
  `id_postishipping_carrier` int(10) unsigned NOT NULL auto_increment,
  `id_carrier_reference` int(10) unsigned NOT NULL,
  `id_posti_service` varchar(64) NULL,
  `service_data` text NOT NULL,
  `selected_add_services` text NULL,
  `active` tinyint(1) NOT NULL,
  PRIMARY KEY (`id_postishipping_carrier`),
  KEY `id_carrier_reference` (`id_carrier_reference`),
  KEY `id_posti_service` (`id_posti_service`)
) ENGINE=ENGINE_TYPE  DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_postishipping_cart_meta` (
  `id_cart` int(10) unsigned NOT NULL,
  `meta_data` longtext NOT NULL,
  PRIMARY KEY (`id_cart`)
) ENGINE=ENGINE_TYPE  DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_postishipping_manifest` (
  `id_postishipping_manifest` int(10) unsigned NOT NULL auto_increment,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `labels` longtext NOT NULL,
  `pickup_time` varchar(32) NULL,
  `canceled_by` varchar(255) NULL,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_postishipping_manifest`),
  KEY `status` (`status`)
) ENGINE=ENGINE_TYPE  DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_postishipping_product_extra` (
  `id_product` int(10) unsigned NOT NULL,
  `hs_tariff` varchar(64) NULL DEFAULT NULL,
  `country_of_origin` varchar(3) NULL DEFAULT NULL,
  `dg_weight` int(10) unsigned NULL DEFAULT 0,
  PRIMARY KEY (`id_product`)
) ENGINE=ENGINE_TYPE  DEFAULT CHARSET=utf8mb4;
