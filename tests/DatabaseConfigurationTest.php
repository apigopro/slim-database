<?php

declare(strict_types=1);

namespace SlimDatabase\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SlimDatabase\DatabaseConfiguration;

final class DatabaseConfigurationTest extends TestCase
{
    private array $originalEnv = [];

    private const KEYS = [
        'DB_DNS', 'DB_HOST', 'DB_PORT', 'DB_NAME',
        'DB_AUTH_USER', 'DB_AUTH_PASSWORD', 'DB_APP_USER', 'DB_APP_PASSWORD',
        'DB_TIMEZONE', 'DB_ENCODING', 'DB_PERSISTENT', 'DB_COLLATION', 'DB_AUTOCOMMIT',
    ];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function setRequiredEnv(): void
    {
        $_ENV['DB_DNS'] = 'pgsql:host=%s;port=%d;dbname=%s';
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_NAME'] = 'myapp';
        $_ENV['DB_AUTH_USER'] = 'auth_user';
        $_ENV['DB_AUTH_PASSWORD'] = 'auth_pw';
        $_ENV['DB_APP_USER'] = 'app_user';
        $_ENV['DB_APP_PASSWORD'] = 'app_pw';
    }

    public function testBuildsBothCredentialSetsFromEnv(): void
    {
        $this->setRequiredEnv();

        $config = new DatabaseConfiguration();

        $this->assertSame('auth_user', $config->authDb->user);
        $this->assertSame('auth_pw', $config->authDb->password);
        $this->assertSame('app_user', $config->appDb->user);
        $this->assertSame('app_pw', $config->appDb->password);
    }

    public function testAuthAndAppShareHostPortNameDns(): void
    {
        $this->setRequiredEnv();

        $config = new DatabaseConfiguration();

        $this->assertSame($config->authDb->host, $config->appDb->host);
        $this->assertSame($config->authDb->port, $config->appDb->port);
        $this->assertSame($config->authDb->name, $config->appDb->name);
        $this->assertSame($config->authDb->dns, $config->appDb->dns);
    }

    public function testMissingRequiredVarThrows(): void
    {
        $this->setRequiredEnv();
        unset($_ENV['DB_APP_PASSWORD']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_APP_PASSWORD');

        new DatabaseConfiguration();
    }

    public function testPortDefaultsTo5432(): void
    {
        $this->setRequiredEnv();

        $config = new DatabaseConfiguration();

        $this->assertSame(5432, $config->authDb->port);
    }

    public function testTimezoneDefaultsToUtc(): void
    {
        $this->setRequiredEnv();

        $config = new DatabaseConfiguration();

        $this->assertSame('UTC', $config->authDb->timezone);
    }

    public function testPersistentDefaultsToFalse(): void
    {
        $this->setRequiredEnv();

        $config = new DatabaseConfiguration();

        $this->assertFalse($config->authDb->persistent);
    }

    public function testPersistentParsesTrueStringCorrectly(): void
    {
        $this->setRequiredEnv();
        $_ENV['DB_PERSISTENT'] = 'true';

        $config = new DatabaseConfiguration();

        $this->assertTrue($config->authDb->persistent);
    }
}
