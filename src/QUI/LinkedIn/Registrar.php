<?php

/**
 * This file contains QUI\LinkedIn\Registrar
 */

namespace QUI\LinkedIn;

use QUI;
use QUI\FrontendUsers;

/**
 * Class Registrar
 *
 * Registration via apple address
 */
class Registrar extends FrontendUsers\AbstractRegistrar
{
    // region auth stuff
    public function validate(): array
    {
        // TODO: Implement validate() method.
        return [];
    }

    public function createUser(): QUI\Interfaces\Users\User
    {
        $token = $this->getAttribute('token');
        $profileData = LinkedIn::validateAccessToken($token);

        if (LinkedIn::existsQuiqqerAccount($token, $profileData)) {
            return LinkedIn::getUserByToken($token, $profileData);
        }

        $User =  parent::createUser();
        $SystemUser = QUI::getUsers()->getSystemUser();

        $User->setAttributes([
            'email' => $profileData['email'],
            'firstname' => empty($profileData['given_name']) ? null : $profileData['given_name'],
            'lastname' => empty($profileData['family_name']) ? null : $profileData['family_name'],
        ]);


        $User->setAttribute(FrontendUsers\Handler::USER_ATTR_EMAIL_VERIFIED, boolval($profileData['email_verified']));

        $User->setPassword(QUI\Security\Password::generateRandom(), $SystemUser);
        $User->save($SystemUser);

        // connect Google account with QUIQQER account
        LinkedIn::connectQuiqqerAccount($User->getUUID(), $token, false);

        return $User;
    }

    public function onRegistered(QUI\Interfaces\Users\User $User): void
    {
    }

    public function getInvalidFields(): array
    {
        return [];
    }

    // endregion

    public function getUsername(): string
    {
        $token = $this->getAttribute('token');
        $profileData = LinkedIn::validateAccessToken($token);

        return $profileData['email'];
    }

    public function getControl(): QUI\Control
    {
        return new QUI\LinkedIn\Controls\Button();
    }

    public function getTitle(?QUI\Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/authlinkedin', 'registrar.title');
    }

    public function getDescription(?QUI\Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/authlinkedin', 'registrar.description');
    }

    public function getIcon(): string
    {
        return 'fa fa-brands fa-linkedin';
    }

    public function canSendPassword(): bool
    {
        return false;
    }
}
