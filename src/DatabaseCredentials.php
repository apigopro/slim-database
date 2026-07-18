<?php
/**
 * APIGO - Database credentials
 * PHP Version 8.5
 * Slim Framework 4
 *
 * @see https://apigo.pro
 *
 * @author    Dragomir Vuckovic <d.vuckovic@apigo.pro>
 * @copyright 2012 - 2026 Dragomir Vuckovic
 * @license   https://opensource.org/license/MIT
 * 
 * @version 1.0.0 - Dragomir Vuckovic (18.07.2026) - Database credentials
 */

// Use strict types
declare(strict_types=1);

// Namespace
namespace SlimDatabase;

// Database credentials
final class DatabaseCredentials
{
    public function __construct (
        // Database credentials
        public readonly string $dns,
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        public readonly string $password,
        public readonly string $timezone,
        public readonly string $encoding,
        public readonly bool $persistent,
        public readonly string $collation,
        public readonly bool $autocommit
    ) { }
}
?>