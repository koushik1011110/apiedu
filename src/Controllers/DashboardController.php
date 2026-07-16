<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function stats(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? 'all';
        $db = Database::getConnection();

        $totalSchools = $db->query("SELECT COUNT(*) FROM branch WHERE status = 1")->fetchColumn();

        if ($branchId === 'all') {
            $totalStudents = $db->query("SELECT COUNT(DISTINCT s.id) FROM student s JOIN enroll e ON e.student_id = s.id")->fetchColumn();
            $totalStaff    = $db->query("SELECT COUNT(*) FROM staff")->fetchColumn();
            $totalParents  = $db->query("SELECT COUNT(*) FROM parent")->fetchColumn();
        } else {
            $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM student s JOIN enroll e ON e.student_id = s.id WHERE e.branch_id = ?");
            $stmt->execute([$branchId]);
            $totalStudents = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE branch_id = ?");
            $stmt->execute([$branchId]);
            $totalStaff = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM parent WHERE branch_id = ?");
            $stmt->execute([$branchId]);
            $totalParents = $stmt->fetchColumn();
        }

        $response->getBody()->write(json_encode([
            'total_schools'  => (int) $totalSchools,
            'total_students' => (int) $totalStudents,
            'total_staff'    => (int) $totalStaff,
            'total_parents'  => (int) $totalParents,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
