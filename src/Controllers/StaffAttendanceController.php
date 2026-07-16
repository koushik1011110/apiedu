<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaffAttendanceController
{
    public function myAttendance(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $staffId = $user['sub'];
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT * FROM staff_attendance
            WHERE staff_id = ?
            ORDER BY date DESC
        ");
        $stmt->execute([$staffId]);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $staffId = $params['staff_id'] ?? null;
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT sa.*, st.name AS staff_name
                FROM staff_attendance sa
                JOIN staff st ON st.id = sa.staff_id
                WHERE 1=1";
        $values = [];

        if ($staffId) {
            $sql .= " AND sa.staff_id = ?";
            $values[] = $staffId;
        }
        if ($branchId) {
            $sql .= " AND sa.branch_id = ?";
            $values[] = $branchId;
        }

        $sql .= " ORDER BY sa.date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO staff_attendance (staff_id, status, remark, date, branch_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark)
        ");
        $stmt->execute([
            $data['staff_id'],
            $data['status'] ?? 'P',
            $data['remark'] ?? '',
            $data['date'] ?? date('Y-m-d'),
            $data['branch_id'] ?? null,
        ]);

        $response->getBody()->write(json_encode(['id' => $db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
