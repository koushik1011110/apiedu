<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BranchController
{
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name, school_name, email, mobileno, city, state, address, status FROM branch ORDER BY id DESC");
        $data = $stmt->fetchAll();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, school_name, email, mobileno, city, state, address, status FROM branch WHERE id = ?");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();

        if (!$data) {
            $response->getBody()->write(json_encode(['error' => 'School not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $schoolName = $data['school_name'] ?? '';
        $shortName = $data['name'] ?? $schoolName;
        $email = $data['email'] ?? '';
        $mobileno = $data['mobileno'] ?? '';
        $city = $data['city'] ?? '';
        $state = $data['state'] ?? '';
        $address = $data['address'] ?? '';
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($schoolName) || empty($username) || empty($password)) {
            $response->getBody()->write(json_encode(['error' => 'School name, username, and password are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $check = $db->prepare("SELECT id FROM login_credential WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Username already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO branch (name, school_name, email, mobileno, currency, symbol, city, state, address, status)
                VALUES (?, ?, ?, ?, 'INR', '₹', ?, ?, ?, 1)
            ");
            $stmt->execute([$shortName, $schoolName, $email, $mobileno, $city, $state, $address]);
            $branchId = (int)$db->lastInsertId();

            $staffId = substr(bin2hex(random_bytes(4)), 0, 7);
            $stmt = $db->prepare("
                INSERT INTO staff (staff_id, name, email, mobileno, branch_id, joining_date)
                VALUES (?, ?, ?, ?, ?, CURDATE())
            ");
            $stmt->execute([$staffId, $schoolName, $email, $mobileno, $branchId]);
            $staffUserId = (int)$db->lastInsertId();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                INSERT INTO login_credential (user_id, username, password, role, active)
                VALUES (?, ?, ?, 2, 1)
            ");
            $stmt->execute([$staffUserId, $username, $hash]);

            $db->commit();

            $response->getBody()->write(json_encode([
                'id' => $branchId,
                'school_name' => $schoolName,
                'name' => $shortName,
                'email' => $email,
                'mobileno' => $mobileno,
                'city' => $city,
                'state' => $state,
                'address' => $address,
                'status' => 1,
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $allowed = ['name', 'school_name', 'email', 'mobileno', 'city', 'state', 'address', 'status'];
        $fields = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            $response->getBody()->write(json_encode(['error' => 'No fields to update']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $values[] = $args['id'];
        $stmt = $db->prepare("UPDATE branch SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);

        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function stats(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $branchId = $args['id'];

        $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM student s JOIN enroll e ON e.student_id = s.id WHERE e.branch_id = ?");
        $stmt->execute([$branchId]);
        $totalStudents = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $totalStaff = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM parent WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $totalParents = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM class WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $totalClasses = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM section WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $totalSections = (int)$stmt->fetchColumn();

        $response->getBody()->write(json_encode([
            'total_students' => $totalStudents,
            'total_staff'    => $totalStaff,
            'total_parents'  => $totalParents,
            'total_classes'  => $totalClasses,
            'total_sections' => $totalSections,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id FROM staff WHERE branch_id = ?");
        $stmt->execute([$args['id']]);
        $staffIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $db->beginTransaction();
        try {
            if (!empty($staffIds)) {
                $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
                $db->prepare("DELETE FROM login_credential WHERE user_id IN ($placeholders) AND role = 2")->execute($staffIds);
                $db->prepare("DELETE FROM staff WHERE id IN ($placeholders)")->execute($staffIds);
            }
            $db->prepare("DELETE FROM enroll WHERE branch_id = ?")->execute([$args['id']]);
            $stmt = $db->prepare("DELETE FROM branch WHERE id = ?");
            $stmt->execute([$args['id']]);

            $db->commit();
            $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
