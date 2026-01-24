<?php

/**
 * Exchange LinkedIn OAuth code for tokens
 *
 * @param string $code
 * @param string $redirectUri
 * @return array<string, mixed>
 */

use QUI\LinkedIn\LinkedIn;

QUI::getAjax()->registerFunction(
    'package_quiqqer_authlinkedin_ajax_exchangeCode',
    function ($code, $redirectUri) {
        return LinkedIn::exchangeCode($code, $redirectUri);
    },
    ['code', 'redirectUri']
);
