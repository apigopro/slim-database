<?php

declare(strict_types=1);

namespace SlimDatabase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SlimDatabase\AppDatabaseConnection;
use SlimDatabase\DatabaseCredentials;

/**
 * Requires a real, reachable PostgreSQL server — set these env vars to
 * point at a disposable test database before running:
 *
 *   TEST_PG_HOST, TEST_PG_PORT, TEST_PG_NAME, TEST_PG_USER, TEST_PG_PASSWORD
 *
 * Skips automatically if TEST_PG_HOST isn't set, so `composer test` still
 * runs cleanly without a database available.
 */
final class AbstractDatabaseConnectionTest extends TestCase
{
    private function credentials(): DatabaseCredentials
    {
        return new DatabaseCredentials(
            dns: 'pgsql:host=%s;port=%d;dbname=%s',
            host: getenv('TEST_PG_HOST') ?: '127.0.0.1',
            port: (int) (getenv('TEST_PG_PORT') ?: 5432),
            name: getenv('TEST_PG_NAME') ?: 'myapp',
            user: getenv('TEST_PG_USER') ?: 'myapp_user',
            password: getenv('TEST_PG_PASSWORD') ?: 'secret123',
            timezone: 'Europe/Sarajevo',
            encoding: 'UTF8',
            persistent: false,
            collation: '',
            autocommit: false,
        );
    }

    protected function setUp(): void
    {
        if (getenv('TEST_PG_HOST') === false && !@fsockopen('127.0.0.1', 5432)) {
            $this->markTestSkipped('No reachable PostgreSQL test server configured.');
        }
    }

    public function testConnectionIsLazy(): void
    {
        $db = new AppDatabaseConnection($this->credentials());

        $this->assertFalse($db->isConnected());

        $db->get();

        $this->assertTrue($db->isConnected());
    }

    public function testGetReturnsSameInstanceOnSubsequentCalls(): void
    {
        $db = new AppDatabaseConnection($this->credentials());

        $first = $db->get();
        $second = $db->get();

        $this->assertSame($first, $second);
    }

    public function testCloseDropsTheConnection(): void
    {
        $db = new AppDatabaseConnection($this->credentials());
        $db->get();

        $db->close();

        $this->assertFalse($db->isConnected());
    }

    public function testCloseIsSafeToCallWithoutEverConnecting(): void
    {
        $db = new AppDatabaseConnection($this->credentials());

        $db->close();

        $this->assertFalse($db->isConnected());
    }

    public function testTimezoneAndEncodingAreAppliedOnConnect(): void
    {
        $db = new AppDatabaseConnection($this->credentials());
        $pdo = $db->get();

        $this->assertSame('Europe/Sarajevo', $pdo->query('SHOW TIME ZONE')->fetchColumn());
        $this->assertSame('UTF8', $pdo->query('SHOW client_encoding')->fetchColumn());
    }

    public function testInvalidCredentialsThrowRuntimeExceptionNotPdoException(): void
    {
        $badCredentials = new DatabaseCredentials(
            dns: 'pgsql:host=%s;port=%d;dbname=%s',
            host: $this->credentials()->host,
            port: $this->credentials()->port,
            name: $this->credentials()->name,
            user: $this->credentials()->user,
            password: 'definitely-the-wrong-password',
            timezone: 'UTC',
            encoding: 'UTF8',
            persistent: false,
            collation: '',
            autocommit: false,
        );

        $db = new AppDatabaseConnection($badCredentials);

        $this->expectException(RuntimeException::class);
        $db->get();
    }
}
