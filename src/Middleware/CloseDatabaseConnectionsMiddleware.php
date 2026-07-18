<?php
/**
 * APIGO - Close database connections middleware
 * PHP Version 8.5
 * Slim Framework 4
 *
 * @see https://apigo.pro
 *
 * @author    Dragomir Vuckovic <d.vuckovic@apigo.pro>
 * @copyright 2012 - 2026 Dragomir Vuckovic
 * @license   https://opensource.org/license/MIT
 *
 * @version 1.0.0 - Dragomir Vuckovic (18.07.2026) - Close database connections middleware
 * @version 1.0.1 - Dragomir Vuckovic (19.07.2026) - Moved from app-specific
 *                   "Apigo\Global\Database" into "SlimDatabase\Middleware"
 *                   so this generic, publishable package doesn't carry an
 *                   application-specific namespace inside it.
 */

declare(strict_types=1);

namespace SlimDatabase\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SlimDatabase\AppDatabaseConnection;
use SlimDatabase\AuthDatabaseConnection;

/**
 * Explicitly closes both database connections at the end of every
 * request — success, exception, anything — via try/finally.
 *
 * Must be registered FIRST in your middleware stack (before auth, CORS,
 * etc.) so it's the OUTERMOST layer: Slim's middleware runs like nested
 * layers, and the first one added is the last to finish on the way out,
 * which is what guarantees this finally block runs only after the route
 * handler and every other middleware have fully completed.
 */
final class CloseDatabaseConnectionsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthDatabaseConnection $authDb,
        private readonly AppDatabaseConnection $appDb,
    ) {
    }

    /**
     * Processing PDO database connection method
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            // close() is a no-op if get() was never called during this
            // request, so it's always safe to call both unconditionally.
            $this->authDb->close();
            $this->appDb->close();
        }
    }
}
