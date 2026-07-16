<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ParentController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT p.*, b.school_name AS branch_name
                FROM parent p
                LEFT JOIN branch b ON b.id = p.branch_id";

        if ($branchId) {
            $sql .= " WHERE p.branch_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query($sql);
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
