<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FeeController
{
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM fees");
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $db->prepare("INSERT INTO fees ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($data));
        $response->getBody()->write(json_encode(['id' => $db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $stmt = $db->prepare("UPDATE fees SET {$sets} WHERE id = ?");
        $stmt->execute([...array_values($data), $args['id']]);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
