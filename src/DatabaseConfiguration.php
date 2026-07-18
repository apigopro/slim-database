<?php
/**
 * APIGO - Database configuration
 * PHP Version 8.5
 * Slim Framework 4
 *
 * @see https://apigo.pro
 *
 * @author    Dragomir Vuckovic <d.vuckovic@apigo.pro>
 * @copyright 2012 - 2026 Dragomir Vuckovic
 * @license   https://opensource.org/license/MIT
 * 
 * @version 1.0.0 - Dragomir Vuckovic (16.07.2026) - Database configuration constants
 * @version 1.0.1 - Dragomir Vuckovic (18.07.2026) - Created constructor, required and optional methods
 */

// Use strict types
declare(strict_types=1);

// Namespace
namespace SlimDatabase;

// System imports
use RuntimeException;

// Custom imports
use SlimDatabase\DatabaseCredentials;

// Application configuration
final class DatabaseConfiguration {
    // Declare
    public readonly DatabaseCredentials $authDb;
    public readonly DatabaseCredentials $appDb;
   
    // Constructor
    public function __construct() {
        // Database enviroment variables
        $dbDNS = $this->required('DB_DNS');
        $dbHost = $this->required('DB_HOST');
        $dbPort = (int) $this->optional('DB_PORT', '5432');
        $dbName = $this->required('DB_NAME');
        $dbTimezone = $this->optional('DB_TIMEZONE', 'UTC');
        $dbEncoding = $this->optional('DB_ENCODING', 'UTF8');
        $dbPersistent = (bool) filter_var($this->optional('DB_PERSISTENT', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dbCollation = $this->optional('DB_COLLATION', 'UTF8_GENERAL_CI');
        $dbAutoCommit =  (bool) filter_var($this->optional('DB_AUTOCOMMIT', 'false'), FILTER_VALIDATE_BOOLEAN);

        // Authentication database connection
        $this->authDb = new DatabaseCredentials(
            dns: $dbDNS,
            host: $dbHost,
            port: $dbPort,
            name: $dbName,
            user: $this->required('DB_AUTH_USER'),
            password: $this->required('DB_AUTH_PASSWORD'),
            timezone: $dbTimezone,
            encoding: $dbEncoding,
            persistent: $dbPersistent,
            collation: $dbCollation,
            autocommit: $dbAutoCommit
            
        );

        // Application database connection
        $this->appDb = new DatabaseCredentials(
            dns: $dbDNS,
            host: $dbHost,
            port: $dbPort,
            name: $dbName,
            user: $this->required('DB_APP_USER'),
            password: $this->required('DB_APP_PASSWORD'),
            timezone: $dbTimezone,
            encoding: $dbEncoding,
            persistent: $dbPersistent,
            collation: $dbCollation,
            autocommit: $dbAutoCommit
        );
    }
 
    /**
     * Required constants method
     * @return string
     * @throws RuntimeException
     */
    private function required(string $key): string  {
        $value = $_ENV[$key] ?? getenv($key); 
        if ($value === false || $value === '') throw new RuntimeException("Missing required environment variable: {$key}");

        return (string) $value;
    }
 
    /**
     * Optional constants method
     * @return string
     */
    private function optional(string $key, string $default): string {
        $value = $_ENV[$key] ?? getenv($key); 
        return $value === false || $value === '' ? $default : (string) $value;
    }

    /**
     * Parsing array method
     * @return array
     */
    private function parseArray(string $value): array    {
        return array_values(array_filter(array_map(
            trim(...),
            explode(',', $value)
        )));
    }

    /**
     * Required array constants method
     * @return array
     */
    private function arrayRequired(string $key): array {
        return $this->parseArray($this->required($key));
    }

    /**
     * Optional array constants method
     * @return array
     */
    private function arrayOptional(string $key, string $default): array {
        return $this->parseArray($this->optional($key, $default));
    }
}
?>
