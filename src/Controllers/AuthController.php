<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private function generateToken(array $payload): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'change-this-secret-key';
        $payload['iat'] = time();
        $payload['exp'] = time() + (int)($_ENV['JWT_EXPIRY'] ?? 86400);
        $data = base64_encode(json_encode($payload));
        $sig = hash_hmac('sha256', $data, $secret);
        return "{$data}.{$sig}";
    }

    private function roleIds(string $roleType): array
    {
        return match ($roleType) {
            'admin'   => [1, 2],
            'staff'   => [3, 4, 5, 8],
            'student' => [7],
            default   => [1, 2, 3, 4, 5, 6, 7, 8],
        };
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $roleType = $data['role_type'] ?? 'school';

        if (empty($username) || empty($password)) {
            $response->getBody()->write(json_encode(['error' => 'Username and password required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $roles = $this->roleIds($roleType);
        $placeholders = implode(',', array_fill(0, count($roles), '?'));

        $stmt = $db->prepare(
            "SELECT lc.*, r.name AS role_name
             FROM login_credential lc
             JOIN roles r ON r.id = lc.role
             WHERE lc.username = ? AND lc.active = 1 AND lc.role IN ({$placeholders})
             LIMIT 1"
        );
        $stmt->execute([$username, ...$roles]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid username or password']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = $this->generateToken([
            'sub'       => $user['user_id'],
            'role'      => $user['role'],
            'role_name' => $user['role_name'],
            'username'  => $user['username'],
            'role_type' => $roleType,
        ]);

        $db->prepare(
            "UPDATE login_credential SET last_login = NOW() WHERE id = ?"
        )->execute([$user['id']]);

        $profile = null;
        if (in_array($user['role'], [1, 2, 3, 4, 5, 8])) {
            $pStmt = $db->prepare("SELECT id, name, email, mobileno, photo, branch_id FROM staff WHERE id = ? LIMIT 1");
            $pStmt->execute([$user['user_id']]);
            $profile = $pStmt->fetch();
        } elseif ($user['role'] == 7) {
            $pStmt = $db->prepare("SELECT id, first_name, last_name, email, mobileno, photo FROM student WHERE id = ? LIMIT 1");
            $pStmt->execute([$user['user_id']]);
            $profile = $pStmt->fetch();
        } elseif ($user['role'] == 6) {
            $pStmt = $db->prepare("SELECT id, name, email, mobileno, photo FROM parent WHERE id = ? LIMIT 1");
            $pStmt->execute([$user['user_id']]);
            $profile = $pStmt->fetch();
        }

        $response->getBody()->write(json_encode([
            'token'      => $token,
            'user_id'    => $user['user_id'],
            'username'   => $user['username'],
            'role'       => $user['role'],
            'role_name'  => $user['role_name'],
            'role_type'  => $roleType,
            'profile'    => $profile,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logout(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['message' => 'Logged out']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
