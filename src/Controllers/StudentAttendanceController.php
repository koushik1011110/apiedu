<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StudentAttendanceController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $date = $params['date'] ?? date('Y-m-d');
        $classId = $params['class_id'] ?? null;
        $sectionId = $params['section_id'] ?? null;
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT sa.*, s.first_name, s.last_name, e.roll, e.class_id, e.section_id
                FROM student_attendance sa
                JOIN enroll e ON e.id = sa.enroll_id
                JOIN student s ON s.id = e.student_id
                WHERE sa.date = ?";
        $values = [$date];

        if ($classId) {
            $sql .= " AND e.class_id = ?";
            $values[] = $classId;
        }
        if ($sectionId) {
            $sql .= " AND e.section_id = ?";
            $values[] = $sectionId;
        }
        if ($branchId) {
            $sql .= " AND e.branch_id = ?";
            $values[] = $branchId;
        }

        $sql .= " ORDER BY e.roll";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $records = $data['records'] ?? [];
        $date = $data['date'] ?? date('Y-m-d');
        $branchId = $data['branch_id'] ?? null;

        if (empty($records)) {
            $response->getBody()->write(json_encode(['error' => 'No records provided']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $upsertStmt = $db->prepare("
                INSERT INTO student_attendance (enroll_id, date, status, remark, branch_id)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark)
            ");

            foreach ($records as $r) {
                $upsertStmt->execute([
                    $r['enroll_id'],
                    $date,
                    $r['status'] ?? 'P',
                    $r['remark'] ?? '',
                    $branchId,
                ]);
            }

            $db->commit();
            $response->getBody()->write(json_encode(['success' => true, 'count' => count($records)]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function studentsForDate(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $date = $params['date'] ?? date('Y-m-d');
        $classId = $params['class_id'] ?? null;
        $sectionId = $params['section_id'] ?? null;
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if (!$classId || !$branchId) {
            $response->getBody()->write(json_encode(['error' => 'class_id and branch_id required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $sql = "SELECT e.id AS enroll_id, s.id AS student_id, s.first_name, s.last_name, e.roll,
                       sa.status, sa.remark, sa.id AS attendance_id
                FROM enroll e
                JOIN student s ON s.id = e.student_id
                LEFT JOIN student_attendance sa ON sa.enroll_id = e.id AND sa.date = ?
                WHERE e.class_id = ?";
        $values = [$date, $classId];

        if ($sectionId) {
            $sql .= " AND e.section_id = ?";
            $values[] = $sectionId;
        }
        if ($branchId) {
            $sql .= " AND e.branch_id = ?";
            $values[] = $branchId;
        }

        $sql .= " ORDER BY e.roll";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
