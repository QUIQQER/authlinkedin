<?php

namespace QUITests\LinkedIn\Integration;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\LinkedIn\LinkedIn;
use Throwable;

class DatabaseAccessTest extends TestCase
{
    private ?string $userUuid = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!QUI::getSchemaManager()->tablesExist([LinkedIn::table()])) {
            self::markTestSkipped('The LinkedIn authentication database table is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->userUuid !== null) {
            try {
                QUI::getDataBaseConnection()->delete(
                    QUI\Utils\Doctrine::quoteIdentifier(LinkedIn::table()),
                    [QUI\Utils\Doctrine::quoteIdentifier('userId') => $this->userUuid]
                );

                QUI::getUsers()->deleteUser($this->userUuid);
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testDisconnectAccountDeletesLinkedInConnectionThroughDbal(): void
    {
        $Users = QUI::getUsers();
        $SystemUser = $Users->getSystemUser();
        $suffix = bin2hex(random_bytes(8));
        $username = 'phpunit-authlinkedin-' . $suffix;

        try {
            $User = $Users->createChildWithAttributes([
                'username' => $username,
                'email' => $username . '@example.invalid',
                'firstname' => 'LinkedIn',
                'lastname' => 'DBAL'
            ], $SystemUser);
        } catch (Throwable $Exception) {
            self::markTestSkipped('No usable super-user fixture is available: ' . $Exception->getMessage());
        }

        $this->userUuid = $User->getUUID();
        $linkedInSub = 'phpunit-linkedin-sub-' . $suffix;
        $Connection = QUI::getDataBaseConnection();

        $Connection->insert(
            QUI\Utils\Doctrine::quoteIdentifier(LinkedIn::table()),
            [
                QUI\Utils\Doctrine::quoteIdentifier('userId') => $this->userUuid,
                QUI\Utils\Doctrine::quoteIdentifier('linkedInSub') => $linkedInSub,
                QUI\Utils\Doctrine::quoteIdentifier('email') => $username . '@example.invalid',
                QUI\Utils\Doctrine::quoteIdentifier('name') => 'LinkedIn DBAL'
            ]
        );

        $QueryBuilder = QUI::getQueryBuilder();
        $storedUserUuid = $QueryBuilder
            ->select('userId')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(LinkedIn::table()))
            ->where($QueryBuilder->expr()->eq('linkedInSub', ':linkedInSub'))
            ->setParameter('linkedInSub', $linkedInSub)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        self::assertSame($this->userUuid, $storedUserUuid);

        LinkedIn::disconnectAccount($this->userUuid, false);

        $QueryBuilder = QUI::getQueryBuilder();
        $storedUserUuid = $QueryBuilder
            ->select('userId')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(LinkedIn::table()))
            ->where($QueryBuilder->expr()->eq('linkedInSub', ':linkedInSub'))
            ->setParameter('linkedInSub', $linkedInSub)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        self::assertFalse($storedUserUuid);
    }
}
