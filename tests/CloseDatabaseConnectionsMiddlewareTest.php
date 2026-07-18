<?php

declare(strict_types=1);

namespace SlimDatabase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use SlimDatabase\AppDatabaseConnection;
use SlimDatabase\AuthDatabaseConnection;
use SlimDatabase\DatabaseCredentials;
use SlimDatabase\Middleware\CloseDatabaseConnectionsMiddleware;

/**
 * Requires a real, reachable PostgreSQL server — see
 * AbstractDatabaseConnectionTest for the env vars this respects. Skips
 * automatically if none is configured.
 */
final class CloseDatabaseConnectionsMiddlewareTest extends TestCase
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
            timezone: 'UTC',
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

    public function testConnectionsAreClosedAfterSuccessfulRequest(): void
    {
        $authDb = new AuthDatabaseConnection($this->credentials());
        $appDb = new AppDatabaseConnection($this->credentials());

        $handler = new class ($authDb, $appDb) implements RequestHandlerInterface {
            public function __construct(private $authDb, private $appDb)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->authDb->get()->query('SELECT 1');
                $this->appDb->get()->query('SELECT 1');

                return (new ResponseFactory())->createResponse(200);
            }
        };

        $middleware = new CloseDatabaseConnectionsMiddleware($authDb, $appDb);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/data');

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($authDb->isConnected());
        $this->assertFalse($appDb->isConnected());
    }

    public function testConnectionsAreClosedEvenWhenHandlerThrows(): void
    {
        $authDb = new AuthDatabaseConnection($this->credentials());
        $appDb = new AppDatabaseConnection($this->credentials());

        $handler = new class ($authDb, $appDb) implements RequestHandlerInterface {
            public function __construct(private $authDb, private $appDb)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->authDb->get()->query('SELECT 1');
                $this->appDb->get()->query('SELECT 1');

                throw new RuntimeException('Simulated failure mid-request');
            }
        };

        $middleware = new CloseDatabaseConnectionsMiddleware($authDb, $appDb);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/data');

        $this->expectException(RuntimeException::class);

        try {
            $middleware->process($request, $handler);
        } finally {
            $this->assertFalse($authDb->isConnected());
            $this->assertFalse($appDb->isConnected());
        }
    }
}
