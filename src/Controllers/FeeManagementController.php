<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FeeManagementController
{
    // ─── Fee Types ───────────────────────────────────────────────

    public function feeTypes(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::getConnection();
        if (!empty($params['branch_id'])) {
            $stmt = $db->prepare("SELECT * FROM fees_type WHERE branch_id = ? ORDER BY name");
            $stmt->execute([$params['branch_id']]);
        } else {
            $stmt = $db->query("SELECT * FROM fees_type ORDER BY branch_id, name");
        }
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function storeFeeType(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO fees_type (name, fee_code, description, branch_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['name'] ?? '',
            $data['fee_code'] ?? '',
            $data['description'] ?? '',
            $data['branch_id'] ?? null,
        ]);
        $response->getBody()->write(json_encode(['id' => $db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateFeeType(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE fees_type SET name = ?, fee_code = ?, description = ? WHERE id = ?");
        $stmt->execute([
            $data['name'] ?? '',
            $data['fee_code'] ?? '',
            $data['description'] ?? '',
            $args['id'],
        ]);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteFeeType(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM fees_type WHERE id = ?")->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─── Fee Groups ──────────────────────────────────────────────

    public function feeGroups(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::getConnection();
        $sql = "SELECT fg.*, sy.school_year AS session_name
                FROM fee_groups fg
                LEFT JOIN schoolyear sy ON sy.id = fg.session_id";
        $bindings = [];
        if (!empty($params['branch_id'])) {
            $sql .= " WHERE fg.branch_id = ?";
            $bindings[] = $params['branch_id'];
        }
        $sql .= " ORDER BY fg.name";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $groups = $stmt->fetchAll();

        foreach ($groups as &$group) {
            $dStmt = $db->prepare("
                SELECT fgd.*, ft.name AS fee_type_name
                FROM fee_groups_details fgd
                JOIN fees_type ft ON ft.id = fgd.fee_type_id
                WHERE fgd.fee_groups_id = ?
                ORDER BY ft.name
            ");
            $dStmt->execute([$group['id']]);
            $group['details'] = $dStmt->fetchAll();
        }

        $response->getBody()->write(json_encode($groups));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function storeFeeGroup(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO fee_groups (name, description, session_id, branch_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['name'] ?? '',
            $data['description'] ?? '',
            $data['session_id'] ?? null,
            $data['branch_id'] ?? null,
        ]);
        $id = $db->lastInsertId();

        if (!empty($data['details']) && is_array($data['details'])) {
            $dStmt = $db->prepare("INSERT INTO fee_groups_details (fee_groups_id, fee_type_id, amount, due_date) VALUES (?, ?, ?, ?)");
            foreach ($data['details'] as $d) {
                $dStmt->execute([
                    $id,
                    $d['fee_type_id'],
                    $d['amount'] ?? 0,
                    $d['due_date'] ?? null,
                ]);
            }
        }

        $response->getBody()->write(json_encode(['id' => $id]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateFeeGroup(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE fee_groups SET name = ?, description = ?, session_id = ? WHERE id = ?");
        $stmt->execute([
            $data['name'] ?? '',
            $data['description'] ?? '',
            $data['session_id'] ?? null,
            $args['id'],
        ]);

        if (isset($data['details']) && is_array($data['details'])) {
            $db->prepare("DELETE FROM fee_groups_details WHERE fee_groups_id = ?")->execute([$args['id']]);
            $dStmt = $db->prepare("INSERT INTO fee_groups_details (fee_groups_id, fee_type_id, amount, due_date) VALUES (?, ?, ?, ?)");
            foreach ($data['details'] as $d) {
                $dStmt->execute([
                    $args['id'],
                    $d['fee_type_id'],
                    $d['amount'] ?? 0,
                    $d['due_date'] ?? null,
                ]);
            }
        }

        $response->getBody()->write(json_encode(['updated' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteFeeGroup(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM fee_groups_details WHERE fee_groups_id = ?")->execute([$args['id']]);
        $db->prepare("DELETE FROM fee_groups WHERE id = ?")->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─── Fee Allocations ─────────────────────────────────────────

    public function feeAllocations(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::getConnection();
        $sql = "SELECT fa.*, fg.name AS group_name, sy.school_year AS session_name,
                       CONCAT(s.first_name, ' ', COALESCE(s.last_name, '')) AS student_name,
                       s.register_no, c.name AS class_name, sec.name AS section_name,
                       COALESCE(fph.payment_count, 0) AS payment_count
                FROM fee_allocation fa
                JOIN fee_groups fg ON fg.id = fa.group_id
                LEFT JOIN schoolyear sy ON sy.id = fa.session_id
                JOIN enroll e ON e.id = fa.student_id
                JOIN student s ON s.id = e.student_id
                JOIN class c ON c.id = e.class_id
                LEFT JOIN section sec ON sec.id = e.section_id
                LEFT JOIN (
                    SELECT allocation_id, COUNT(*) AS payment_count
                    FROM fee_payment_history
                    GROUP BY allocation_id
                ) fph ON fph.allocation_id = fa.id";
        $conditions = [];
        $bindings = [];
        if (!empty($params['branch_id'])) {
            $conditions[] = "fa.branch_id = ?";
            $bindings[] = $params['branch_id'];
        }
        if (!empty($params['group_id'])) {
            $conditions[] = "fa.group_id = ?";
            $bindings[] = $params['group_id'];
        }
        if (!empty($params['student_id'])) {
            $conditions[] = "e.student_id = ?";
            $bindings[] = $params['student_id'];
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY s.first_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function storeAllocation(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $studentId = $data['student_id'] ?? null;
        $groupId = $data['group_id'] ?? null;
        $branchId = $data['branch_id'] ?? null;
        $sessionId = $data['session_id'] ?? null;

        if (!$studentId || !$groupId || !$branchId || !$sessionId) {
            $response->getBody()->write(json_encode(['error' => 'student_id, group_id, branch_id, and session_id are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $dup = $db->prepare("SELECT id FROM fee_allocation WHERE student_id = ? AND group_id = ?");
        $dup->execute([$studentId, $groupId]);
        if ($dup->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'This fee group is already allocated to the selected student']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $db->prepare("INSERT INTO fee_allocation (student_id, group_id, branch_id, session_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$studentId, $groupId, $branchId, $sessionId]);
        $response->getBody()->write(json_encode(['id' => $db->lastInsertId()]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function deleteAllocation(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $payStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM fee_payment_history WHERE allocation_id = ?");
        $payStmt->execute([$args['id']]);
        $paymentCount = (int)$payStmt->fetch()['cnt'];

        $db->prepare("DELETE FROM fee_allocation WHERE id = ?")->execute([$args['id']]);
        $result = ['deleted' => true];
        if ($paymentCount > 0) {
            $result['payment_history_deleted'] = $paymentCount;
        }
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─── Students for allocation dropdown ────────────────────────

    public function students(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::getConnection();
        $sql = "SELECT e.id AS enroll_id, s.id AS student_id, s.first_name, s.last_name,
                       s.register_no, e.branch_id, e.class_id, e.section_id, e.roll,
                       c.name AS class_name, sec.name AS section_name
                FROM enroll e
                JOIN student s ON s.id = e.student_id
                JOIN class c ON c.id = e.class_id
                LEFT JOIN section sec ON sec.id = e.section_id";
        $bindings = [];
        $conditions = [];
        if (!empty($params['branch_id'])) {
            $conditions[] = "e.branch_id = ?";
            $bindings[] = $params['branch_id'];
        }
        if (!empty($params['session_id'])) {
            $conditions[] = "e.session_id = ?";
            $bindings[] = $params['session_id'];
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY s.first_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
