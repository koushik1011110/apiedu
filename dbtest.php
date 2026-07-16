<?php
header('Content-Type: application/json');

$result = [
    'php_version' => PHP_VERSION,
    'status' => 'checking',
    'checks' => [],
];

// Check required extensions
$extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'];
foreach ($extensions as $ext) {
    $result['checks'][$ext] = extension_loaded($ext) ? 'ok' : 'missing';
}

// Check .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $result['checks']['env_file'] = 'found';
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $found = [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key] = explode('=', $line, 2);
        $found[] = trim($key);
    }
    $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    $result['checks']['env_vars'] = [];
    foreach ($required as $r) {
        $result['checks']['env_vars'][$r] = in_array($r, $found) ? 'set' : 'missing';
    }
} else {
    $result['checks']['env_file'] = 'missing - copy .env.example to .env';
}

// Test DB connection
try {
    $host = 'localhost';
    $port = '3306';
    $name = 'ramom_db';
    $user = 'imdadul';
    $pass = 'root';

    if (file_exists($envFile)) {
        $dotenv = parse_ini_file($envFile);
        $host = $dotenv['DB_HOST'] ?? $host;
        $port = $dotenv['DB_PORT'] ?? $port;
        $name = $dotenv['DB_NAME'] ?? $name;
        $user = $dotenv['DB_USER'] ?? $user;
        $pass = $dotenv['DB_PASS'] ?? $pass;
    }

    if (empty($name) || empty($user)) {
        $result['db'] = 'skipped';
        $result['db_error'] = 'Database credentials not configured in .env';
    } else {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $result['db'] = 'connected';
        $result['db_host'] = $host;
        $result['db_name'] = $name;
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = '" . addslashes($name) . "'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['db_tables'] = (int)$row['cnt'];

        // Test login_credential table
        $stmt2 = $pdo->query("SELECT id, username, role FROM login_credential LIMIT 5");
        $users = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $result['db_users'] = $users;
    }
} catch (PDOException $e) {
    $result['db'] = 'failed';
    $result['db_error'] = $e->getMessage();
} catch (Exception $e) {
    $result['db'] = 'failed';
    $result['db_error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
