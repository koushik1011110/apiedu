<?php

namespace App\Config;
//check
use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'mysql-db');
            $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
            $name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'ramom');
            $user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'kkwebmart');
            $pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new PDOException("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
