<?php

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // --- Updated Credentials Here ---
            $host = 'mysql-db';
            $port = '3306';
            $name = 'ramom';
            $user = 'kkwebmart';
            $pass = 'GmiJeAqNchkODZtHCvj09b2YtIEp';
            // --------------------------------

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                throw new PDOException("Database connection failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }
}
// ==========================================
// DB CONNECTION TEST CODE
// ==========================================
try {
    $db = Database::getConnection();
    echo "✅ Success! Database sahi se kaam kar raha hai aur connect ho gaya.";
} catch (PDOException $e) {
    echo "❌ Error! Database connect nahi hua.<br><br>";
    echo "<strong>Reason:</strong> " . $e->getMessage();
}