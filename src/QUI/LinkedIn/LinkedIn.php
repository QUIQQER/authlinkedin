<?php

namespace QUI\LinkedIn;

use QUI;
use QUI\ExceptionStack;
use QUI\Permissions\Exception;

class LinkedIn
{
    public static function table(): string
    {
        return QUI::getDBTableName('quiqqer_auth_linkedin');
    }


    /**
     * @throws ExceptionStack
     * @throws QUI\Exception
     * @throws Exception
     * @throws QUI\Users\Exception
     * @throws QUI\Database\Exception
     */
    public static function connectQuiqqerAccount(
        int | string $uid,
        string $accessToken,
        bool $checkPermission = true
    ): void {
    }

    /**
     * @throws Exception
     * @throws QUI\Database\Exception
     */
    public static function getConnectedAccountByToken(string $idToken): bool | array
    {
    }

    /**
     * @throws Exception
     * @throws QUI\Database\Exception
     */
    public static function disconnectAccount(
        int | string $userId,
        bool $checkPermission = true
    ): void {
    }

    /**
     * @throws Exception
     */
    public static function checkEditPermission($userId): void
    {
        if (QUI::getUserBySession()->getUUID() === QUI::getUsers()->getSystemUser()->getUUID()) {
            return;
        }

        if (QUI::getSession()->get('uid') !== $userId || !$userId) {
            throw new QUI\Permissions\Exception(
                QUI::getLocale()->get(
                    'quiqqer/authgoogle',
                    'exception.operation.only.allowed.by.own.user'
                ),
                401
            );
        }
    }

    /**
     * Checks if a Google API access token is valid and if the user has provided
     * the necessary information (email)
     *
     * @param string $idToken
     * @return void
     * @throws Exception
     */
    public static function validateAccessToken(string $idToken): void
    {
    }

    public static function existsQuiqqerAccount(string $idToken): bool
    {
    }

    public static function getUserByToken($idToken): QUI\Interfaces\Users\User
    {
    }

    public static function getProfileData($idToken)
    {
    }
}
