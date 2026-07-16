<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SectionController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if ($branchId) {
            $stmt = $db->prepare("SELECT * FROM section WHERE branch_id = ? ORDER BY id");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("SELECT * FROM section ORDER BY id");
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $stmt = $db->prepare("INSERT INTO section (name, capacity, branch_id) VALUES (?, ?, ?)");
        $stmt->execute([
            $data['name'] ?? '',
            $data['capacity'] ?? null,
            $data['branch_id'] ?? null,
        ]);

        $response->getBody()->write(json_encode(['id' => (int)$db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $fields = [];
        $values = [];
        foreach (['name', 'capacity', 'branch_id'] as $field) {
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
        $stmt = $db->prepare("UPDATE section SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM sections_allocation WHERE section_id = ?")->execute([$args['id']]);
        $stmt = $db->prepare("DELETE FROM section WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function assign(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $stmt = $db->prepare("INSERT IGNORE INTO sections_allocation (class_id, section_id) VALUES (?, ?)");
        $stmt->execute([$data['class_id'], $data['section_id']]);

        $response->getBody()->write(json_encode(['assigned' => $stmt->rowCount() > 0]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function unassign(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM sections_allocation WHERE class_id = ? AND section_id = ?");
        $stmt->execute([$args['class_id'], $args['section_id']]);
        $response->getBody()->write(json_encode(['unassigned' => $stmt->rowCount() > 0]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
