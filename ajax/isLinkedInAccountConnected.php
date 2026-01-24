<?php

/**
 * Check if a LinkedIn account is connected to a QUIQQER user account
 *
 * @param string $idToken - LinkedIn ID token
 * @return array|false - Details to a connected LinkedIn account
 */

use QUI\LinkedIn\LinkedIn;

QUI::$Ajax->registerFunction(
    'package_quiqqer_authlinkedin_ajax_isLinkedInAccountConnected',
    function ($idToken) {
        return LinkedIn::existsQuiqqerAccount($idToken);
    },
    ['idToken']
);
