<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if ($branchId) {
            $stmt = $db->prepare("
                SELECT c.*, GROUP_CONCAT(s.id ORDER BY s.id) AS section_ids,
                       GROUP_CONCAT(s.name ORDER BY s.id) AS section_names
                FROM class c
                LEFT JOIN sections_allocation sa ON sa.class_id = c.id
                LEFT JOIN section s ON s.id = sa.section_id
                WHERE c.branch_id = ?
                GROUP BY c.id
                ORDER BY c.id
            ");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("
                SELECT c.*, GROUP_CONCAT(s.id ORDER BY s.id) AS section_ids,
                       GROUP_CONCAT(s.name ORDER BY s.id) AS section_names
                FROM class c
                LEFT JOIN sections_allocation sa ON sa.class_id = c.id
                LEFT JOIN section s ON s.id = sa.section_id
                GROUP BY c.id
                ORDER BY c.id
            ");
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['sections'] = [];
            if ($row['section_ids']) {
                $ids = explode(',', $row['section_ids']);
                $names = explode(',', $row['section_names']);
                for ($i = 0; $i < count($ids); $i++) {
                    $row['sections'][] = ['id' => (int)$ids[$i], 'name' => $names[$i]];
                }
            }
            unset($row['section_ids'], $row['section_names']);
        }

        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM class WHERE id = ?");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();
        $response->getBody()->write(json_encode($data ?: ['error' => 'Not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $stmt = $db->prepare("INSERT INTO class (name, name_numeric, branch_id) VALUES (?, ?, ?)");
        $stmt->execute([
            $data['name'] ?? '',
            $data['name_numeric'] ?? '',
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
        foreach (['name', 'name_numeric', 'branch_id'] as $field) {
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
        $stmt = $db->prepare("UPDATE class SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM sections_allocation WHERE class_id = ?")->execute([$args['id']]);
        $stmt = $db->prepare("DELETE FROM class WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
