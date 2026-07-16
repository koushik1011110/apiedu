<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AccountController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT * FROM accounts";
        if ($branchId) {
            $sql .= " WHERE branch_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query($sql);
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();
        $response->getBody()->write(json_encode($data ?: ['error' => 'Not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $stmt = $db->prepare("INSERT INTO accounts (name, number, description, balance, branch_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['name'] ?? '',
            $data['number'] ?? '',
            $data['description'] ?? '',
            $data['balance'] ?? 0,
            $data['branch_id'] ?? null,
        ]);

        $id = $db->lastInsertId();
        $response->getBody()->write(json_encode(['id' => (int)$id]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $fields = [];
        $values = [];
        foreach (['name', 'number', 'description', 'balance'] as $field) {
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
        $stmt = $db->prepare("UPDATE accounts SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
