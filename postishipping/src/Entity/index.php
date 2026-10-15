<?php

// PrestaShop 9 (API Platform) includes every PHP file in src/Entity while
// building the container, so this file must be a no-op when loaded that way.
if (defined('_PS_VERSION_')) {
    return;
}

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

header('Location: ../');
exit;
