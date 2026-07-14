<?php

namespace QUI\LinkedIn;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use QUI;
use QUI\ExceptionStack;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Exception;

class LinkedIn
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $jwksCache = null;
    private static int $jwksCacheExpiresAt = 0;

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
        $profileData = self::validateAccessToken($accessToken);

        if (self::existsQuiqqerAccount($accessToken, $profileData)) {
            throw new QUI\Exception([
                'quiqqer/authlinkedin',
                'exception.linkedin.account_already_connected',
                ['email' => $profileData['email']]
            ]);
        }

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
     * @param string $idToken
     * @param array<string, mixed>|null $payload
     * @return bool|array<string, mixed>
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public static function getConnectedAccountByToken(string $idToken, ?array $payload = null): bool | array
    {
        $profile = $payload ?? self::validateAccessToken($idToken);
        $linkedInSub = $profile['sub'] ?? null;

        if (empty($linkedInSub)) {
            return false;
        }

        $result = QUI::getDataBase()->fetch([
            'from' => self::table(),
            'where' => [
                'linkedInSub' => $linkedInSub
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
                    'quiqqer/authlinkedin',
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
     * @return array<string, mixed>
     * @throws Exception
     * @throws QUI\Exception
     */
    public static function validateAccessToken(string $idToken): array
    {
        $header = self::getJwtHeader($idToken);
        $alg = $header['alg'] ?? null;

        if ($alg !== 'RS256') {
            throw new QUI\Exception('LinkedIn token algorithm invalid');
        }

        $jwks = self::getJwks();
        $keys = JWK::parseKeySet($jwks);

        if (empty($header['kid']) && count($keys) === 1) {
            $keys = current($keys);
        }

        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (\Exception) {
            throw new QUI\Exception('LinkedIn token signature invalid');
        }

        $payload = json_decode((string)json_encode($decoded), true);

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

        return $payload;
    }

    /**
     * @param string $idToken
     * @param array<string, mixed>|null $payload
     * @return bool
     * @throws Exception
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public static function existsQuiqqerAccount(string $idToken, ?array $payload = null): bool
    {
        $data = $payload ?? self::validateAccessToken($idToken);
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
     * @param string $idToken
     * @param array<string, mixed>|null $payload
     * @return User
     * @throws Exception
     * @throws QUI\Exception
     * @throws QUI\Users\Exception
     */
    public static function getUserByToken(string $idToken, ?array $payload = null): QUI\Interfaces\Users\User
    {
        $data = $payload ?? self::validateAccessToken($idToken);
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
     * @throws Exception|QUI\Exception
     */
    public static function getProfileData(string $idToken): array
    {
        return self::validateAccessToken($idToken);
    }

    /**
     * @return array<string, mixed>
     * @throws QUI\Exception
     */
    private static function getJwtHeader(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new QUI\Exception('LinkedIn token invalid');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);

        if (!is_array($header)) {
            throw new QUI\Exception('LinkedIn token header invalid');
        }

        return $header;
    }

    /**
     * @return array<string, mixed>
     * @throws QUI\Exception
     */
    private static function getJwks(): array
    {
        $now = time();

        if (self::$jwksCache !== null && self::$jwksCacheExpiresAt > $now) {
            return self::$jwksCache;
        }

        $ch = curl_init('https://www.linkedin.com/oauth/openid/jwks');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new QUI\Exception('LinkedIn JWKS request failed: ' . $error);
        }

        curl_close($ch);

        if ($status >= 400) {
            throw new QUI\Exception('LinkedIn JWKS request failed with status ' . $status);
        }

        $jwks = json_decode((string)$raw, true);

        if (!is_array($jwks) || empty($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new QUI\Exception('LinkedIn JWKS response invalid');
        }

        self::$jwksCache = $jwks;
        self::$jwksCacheExpiresAt = $now + 3600;

        return $jwks;
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }
}
