<?php

/**
 * Get LinkedIn API Client-ID
 *
 * @return string - Client-ID
 */

use QUI\LinkedIn\LinkedIn;

QUI::$Ajax->registerFunction(
    'package_quiqqer_authlinkedin_ajax_getClientId',
    function () {
        return LinkedIn::getClientId();
    }
);
