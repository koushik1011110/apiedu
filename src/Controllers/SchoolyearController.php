<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SchoolyearController
{
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM schoolyear ORDER BY school_year DESC");
        $rows = $stmt->fetchAll();
        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM schoolyear WHERE id = ?");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();
        $response->getBody()->write(json_encode($data ?: ['error' => 'Not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $schoolYear = $data['school_year'] ?? '';
        $stmt = $db->prepare("SELECT id FROM schoolyear WHERE school_year = ?");
        $stmt->execute([$schoolYear]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Session "' . $schoolYear . '" already exists']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $db->prepare("INSERT INTO schoolyear (school_year, created_by) VALUES (?, ?)");
        $stmt->execute([
            $schoolYear,
            $data['created_by'] ?? 1,
        ]);

        $id = $db->lastInsertId();
        $response->getBody()->write(json_encode(['id' => (int)$id, 'school_year' => $data['school_year'] ?? '']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $fields = [];
        $values = [];
        foreach (['school_year'] as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'school_year') {
                    $stmt = $db->prepare("SELECT id FROM schoolyear WHERE school_year = ? AND id != ?");
                    $stmt->execute([$data[$field], $args['id']]);
                    if ($stmt->fetch()) {
                        $response->getBody()->write(json_encode(['error' => 'Session "' . $data[$field] . '" already exists']));
                        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
                    }
                }
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        if (empty($fields)) {
            $response->getBody()->write(json_encode(['error' => 'No fields to update']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $values[] = $args['id'];
        $stmt = $db->prepare("UPDATE schoolyear SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM schoolyear WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
