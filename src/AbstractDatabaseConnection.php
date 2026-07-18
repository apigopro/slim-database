<?php
/**
 * APIGO - Abstract database connection
 * PHP Version 8.5
 * Slim Framework 4
 *
 * @see https://apigo.pro
 *
 * @author    Dragomir Vuckovic <d.vuckovic@apigo.pro>
 * @copyright 2012 - 2026 Dragomir Vuckovic
 * @license   https://opensource.org/license/MIT
 *
 * @version 1.0.0 - Dragomir Vuckovic (18.07.2026) - Abstract database connection
 */

declare(strict_types=1);

namespace SlimDatabase;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Lazily-connecting PDO wrapper supporting PostgreSQL, MySQL, and Oracle.
 *
 * Not meant to be used directly — extend it (see AuthDatabaseConnection /
 * AppDatabaseConnection) so each credential set gets its own distinct,
 * type-hintable class.
 */
abstract class AbstractDatabaseConnection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly DatabaseCredentials $credentials)
    {
    }

    /**
     * Get PDO database connection method
     * @return PDO
     */
    public function get(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * Closing PDO database connection method
     *
     * PDO has no close() method — the documented way to close a connection
     * is to destroy every reference to the PDO object. This drops the
     * reference this class holds; if getPdo()'s return value was also
     * stashed elsewhere, the connection stays open until that reference is
     * gone too.
     * @return void
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Checking if PDO database connection is alive method
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Database connection method
     * @return PDO
     * @throws RuntimeException
     */
    private function connect(): PDO
    {
        // $credentials->dns is a printf-style template, e.g.
        // "pgsql:host=%s;port=%d;dbname=%s" — host/port/name are
        // substituted in that fixed order. See DatabaseCredentials and
        // the README for the exact template per driver.
        $dsn = sprintf(
            $this->credentials->dns,
            $this->credentials->host,
            $this->credentials->port,
            $this->credentials->name
        );

        try {
            $pdo = new PDO($dsn, $this->credentials->user, $this->credentials->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => $this->credentials->persistent,
                // NOTE: despite the name, this attribute is not
                // Oracle-specific — it converts empty strings to NULL on
                // fetch for every driver it's applied to here, including
                // Postgres/MySQL. If your app relies on distinguishing ''
                // from NULL on those drivers, reconsider this default.
                // See README "Design notes" section.
                PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Database connection failed: ' . $exception->getMessage(),
                previous: $exception
            );
        }

        switch ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'pgsql':
                $this->configurePostgresqlSession($pdo);
                break;
            case 'mysql':
                $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, $this->credentials->autocommit);
                $this->configureMysqlSession($pdo);
                break;
            case 'oci':
                $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, $this->credentials->autocommit);
                $this->configureOracleSession($pdo);
                break;
        }

        return $pdo;
    }

    /**
     * PostgreSQL database session configuration method
     * @return void
     */
    private function configurePostgresqlSession(PDO $pdo): void
    {
        $pdo->exec('SET client_encoding TO ' . $pdo->quote($this->credentials->encoding));
        $pdo->exec('SET TIME ZONE ' . $pdo->quote($this->credentials->timezone));
    }

    /**
     * MySQL database session configuration method
     *
     * NOTE: named timezones (e.g. "UTC", "Europe/Sarajevo") require
     * MySQL's time zone tables to be populated first — see README
     * "MySQL named timezones" for the one-time `mysql_tzinfo_to_sql`
     * step. Without it, SET time_zone fails with
     * "Unknown or incorrect time zone" even though this code is correct.
     * @return void
     */
    private function configureMysqlSession(PDO $pdo): void
    {
        $encoding = strtolower($this->credentials->encoding);
        $collation = strtolower($this->credentials->collation);

        $pdo->exec('SET NAMES ' . $pdo->quote($encoding));
        $pdo->exec('SET collation_connection = ' . $pdo->quote($collation));
        $pdo->exec('SET time_zone = ' . $pdo->quote($this->credentials->timezone));
    }

    /**
     * Oracle database session configuration method
     *
     * Client character set for PDO_OCI is set via the DSN's ";charset="
     * parameter (baked into $credentials->dns before this ever runs, see
     * .env.example), not via a session command — so only timezone is set
     * here.
     * @return void
     */
    private function configureOracleSession(PDO $pdo): void
    {
        $pdo->exec('ALTER SESSION SET TIME_ZONE = ' . $pdo->quote($this->credentials->timezone));
    }
}
