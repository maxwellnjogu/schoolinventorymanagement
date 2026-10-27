<?php
/**
 * Database Connection Manager (PDO)
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;
    private static ?string $connectionError = null;

    /**
     * Get singleton PDO connection instance
     * 
     * @return PDO|null
     */
    public static function getConnection(): ?PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::$connectionError = null;
            } catch (PDOException $e) {
                // Log the real error securely on the server
                error_log('Database Connection Error: ' . $e->getMessage());
                // Never expose database credentials or details to end-user
                self::$connectionError = 'Database connection could not be established. Please verify MySQL service is running and credentials are correct.';
                self::$instance = null;
            }
        }

        return self::$instance;
    }

    /**
     * Check if database connection is active
     */
    public static function isConnected(): bool {
        return self::getConnection() !== null;
    }

    /**
     * Get human-readable connection error message
     */
    public static function getError(): ?string {
        if (self::$connectionError === null && self::$instance === null) {
            self::getConnection();
        }
        return self::$connectionError;
    }
}
