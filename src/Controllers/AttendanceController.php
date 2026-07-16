<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AttendanceController
{
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM attendance");
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $db->prepare("INSERT INTO attendance ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($data));
        $response->getBody()->write(json_encode(['id' => $db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
