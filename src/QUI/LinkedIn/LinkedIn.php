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

    public static function getClientId(): string
    {
        return (string)QUI::getPackage('quiqqer/authlinkedin')
            ->getConfig()
            ?->get('apiSettings', 'linkedInClientId');
    }

    public static function getClientSecret(): string
    {
        return (string)QUI::getPackage('quiqqer/authlinkedin')
            ->getConfig()
            ?->get('apiSettings', 'linkedInClientSecret');
    }

    /**
     * Exchange LinkedIn OAuth code for tokens
     *
     * @param string $code
     * @param string $redirectUri
     * @return array<string, mixed>
     * @throws QUI\Exception
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        $clientId = self::getClientId();
        $clientSecret = self::getClientSecret();

        if (empty($clientId) || empty($clientSecret)) {
            throw new QUI\Exception('LinkedIn client credentials missing');
        }

        $payload = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ], '', '&');

        $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 15
        ]);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new QUI\Exception('LinkedIn token request failed: ' . $error);
        }

        curl_close($ch);

        if ($status >= 400) {
            throw new QUI\Exception('LinkedIn token request failed with status ' . $status);
        }

        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            throw new QUI\Exception('LinkedIn token response invalid');
        }

        return $data;
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
        if ($checkPermission !== false) {
            self::checkEditPermission($uid);
        }

        $User = QUI::getUsers()->get($uid);
        $profileData = self::getProfileData($accessToken);

        if (self::existsQuiqqerAccount($accessToken)) {
            throw new QUI\Exception([
                'quiqqer/authlinkedin',
                'exception.linkedin.account_already_connected',
                ['email' => $profileData['email']]
            ]);
        }

        self::validateAccessToken($accessToken);

        QUI::getDataBase()->insert(
            self::table(),
            [
                'userId' => $User->getUUID(),
                'linkedInSub' => $profileData['sub'],
                'email' => $profileData['email'],
                'name' => $profileData['email']
            ]
        );

        try {
            $User->enableAuthenticator(
                Auth::class,
                QUI::getUsers()->getSystemUser()
            );
        } catch (\Exception $e) {
            QUI\System\Log::addError($e->getMessage(), [
                'class' => LinkedIn::class,
                'type' => 'enableAuthenticator',
                'userId' => $User->getUUID(),
                'linkedInSub' => $profileData['sub'],
                'email' => $profileData['email']
            ]);
        }
    }

    /**
     * @return bool|array<string, mixed>
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public static function getConnectedAccountByToken(string $idToken): bool | array
    {
        self::validateAccessToken($idToken);
        $profile = self::getProfileData($idToken);

        $result = QUI::getDataBase()->fetch([
            'from' => self::table(),
            'where' => [
                'linkedInSub' => $profile['sub']
            ]
        ]);

        if (empty($result)) {
            return false;
        }

        return current($result);
    }

    /**
     * @throws Exception
     * @throws QUI\Database\Exception
     */
    public static function disconnectAccount(
        int | string $userId,
        bool $checkPermission = true
    ): void {
        if ($checkPermission !== false) {
            self::checkEditPermission($userId);
        }

        try {
            $User = QUI::getUsers()->get($userId);
            $userUuid = $User->getUUID();
        } catch (QUI\Exception) {
            return;
        }

        QUI::getDataBase()->delete(
            self::table(),
            ['userId' => $userUuid]
        );
    }

    /**
     * @throws Exception
     */
    public static function checkEditPermission(string | int $userId): void
    {
        if (QUI::getUserBySession()->getUUID() === QUI::getUsers()->getSystemUser()->getUUID()) {
            return;
        }

        if (QUI::getSession()?->get('uid') !== $userId || !$userId) {
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
     * Checks if a LinkedIn API access token is valid and if the user has provided
     * the necessary information (email)
     *
     * @param string $idToken
     * @return void
     * @throws Exception
     * @throws QUI\Exception
     */
    public static function validateAccessToken(string $idToken): void
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new QUI\Exception('LinkedIn token invalid');
        }

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );

        if (!is_array($payload)) {
            throw new QUI\Exception('LinkedIn token payload invalid');
        }

        $clientId = self::getClientId();
        $issuer = $payload['iss'] ?? null;
        $audience = $payload['aud'] ?? null;
        $exp = $payload['exp'] ?? null;
        $nbf = $payload['nbf'] ?? null;
        $now = time();

        if (empty($clientId)) {
            throw new QUI\Exception('LinkedIn client id missing');
        }

        if (
            $issuer !== 'https://www.linkedin.com'
            && $issuer !== 'https://www.linkedin.com/oauth'
        ) {
            throw new QUI\Exception('LinkedIn token issuer invalid');
        }

        if (is_array($audience)) {
            if (!in_array($clientId, $audience, true)) {
                throw new QUI\Exception('LinkedIn token audience invalid');
            }
        } elseif ($audience !== $clientId) {
            throw new QUI\Exception('LinkedIn token audience invalid');
        }

        if (!is_int($exp) && !ctype_digit((string)$exp)) {
            throw new QUI\Exception('LinkedIn token expiry invalid');
        }

        if ((int)$exp < ($now - 60)) {
            throw new QUI\Exception('LinkedIn token expired');
        }

        if (!is_null($nbf) && (int)$nbf > ($now + 60)) {
            throw new QUI\Exception('LinkedIn token not active');
        }
    }

    public static function existsQuiqqerAccount(string $idToken): bool
    {
        $data = self::getProfileData($idToken);
        $linkedInSub = $data['sub'] ?? null;

        if (empty($linkedInSub)) {
            return false;
        }

        $result = QUI::getDataBase()->fetch([
            'from' => self::table(),
            'where' => [
                'linkedInSub' => $linkedInSub
            ],
            'limit' => 1
        ]);

        if (empty($result)) {
            return false;
        }

        $userId = $result[0]['userId'];

        if (empty($userId)) {
            return false;
        }

        try {
            $user = QUI::getUsers()->get($userId);
        } catch (QUI\Exception) {
            return false;
        }

        try {
            $user->getAuthenticator(Auth::class);
            return true;
        } catch (QUI\Exception) {
        }

        try {
            // add authenticator
            $user->enableAuthenticator(Auth::class, QUI::getUsers()->getSystemUser());
        } catch (QUI\Exception) {
            return false;
        }

        return true;
    }

    /**
     * @throws QUI\Users\Exception
     */
    public static function getUserByToken(string $idToken): QUI\Interfaces\Users\User
    {
        $data = self::getProfileData($idToken);
        $linkedInSub = $data['sub'] ?? null;

        if (empty($linkedInSub)) {
            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.wrong.uid'
                ),
                404
            );
        }

        try {
            $result = QUI::getDataBase()->fetch([
                'from' => self::table(),
                'where' => [
                    'linkedInSub' => $linkedInSub
                ],
                'limit' => 1
            ]);

            if (isset($result[0]['userId'])) {
                return QUI::getUsers()->get($result[0]['userId']);
            }
        } catch (\Exception $e) {
            QUI\System\Log::addError($e->getMessage());
        }

        throw new QUI\Users\Exception(
            QUI::getLocale()->get(
                'quiqqer/core',
                'exception.lib.user.wrong.uid'
            ),
            404
        );
    }

    /**
     * @param string $idToken
     * @return array<string, mixed>
     */
    public static function getProfileData(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );

        return is_array($payload) ? $payload : [];
    }
}
