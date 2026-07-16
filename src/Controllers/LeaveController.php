<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LeaveController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $status = $params['status'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT la.*, lc.name AS category_name,
                       s.name AS staff_name, s.staff_id, s.mobileno
                FROM leave_application la
                LEFT JOIN leave_category lc ON lc.id = la.category_id
                LEFT JOIN staff s ON s.id = la.user_id
                WHERE 1=1";
        $binds = [];

        if ($branchId) {
            $sql .= " AND la.branch_id = ?";
            $binds[] = $branchId;
        }

        if ($status !== null) {
            $sql .= " AND la.status = ?";
            $binds[] = (int)$status;
        }

        $sql .= " ORDER BY la.apply_date DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($binds);

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $data = $request->getParsedBody();
        $approvedBy = $data['approved_by'] ?? 0;

        $stmt = $db->prepare("UPDATE leave_application SET status = 2, approved_by = ? WHERE id = ?");
        $stmt->execute([$approvedBy, $args['id']]);

        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $data = $request->getParsedBody();
        $approvedBy = $data['approved_by'] ?? 0;
        $comments = $data['comments'] ?? '';

        $stmt = $db->prepare("UPDATE leave_application SET status = 3, approved_by = ?, comments = ? WHERE id = ?");
        $stmt->execute([$approvedBy, $comments, $args['id']]);

        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
