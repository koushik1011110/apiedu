<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PromotionController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT ph.*,
                       s.first_name, s.last_name, s.register_no,
                       pc.name AS pre_class_name,
                       ps.name AS pre_section_name,
                       psy.school_year AS pre_session_name,
                       prc.name AS pro_class_name,
                       prs.name AS pro_section_name,
                       prsy.school_year AS pro_session_name
                FROM promotion_history ph
                JOIN student s ON s.id = ph.student_id
                LEFT JOIN class pc ON pc.id = ph.pre_class
                LEFT JOIN section ps ON ps.id = ph.pre_section
                LEFT JOIN schoolyear psy ON psy.id = ph.pre_session
                LEFT JOIN class prc ON prc.id = ph.pro_class
                LEFT JOIN section prs ON prs.id = ph.pro_section
                LEFT JOIN schoolyear prsy ON prsy.id = ph.pro_session";

        $conditions = [];
        $values = [];

        if ($branchId) {
            $conditions[] = "s.id IN (SELECT student_id FROM enroll WHERE branch_id = ?)";
            $values[] = $branchId;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY ph.date DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        $rows = $stmt->fetchAll();

        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT ph.*,
                   s.first_name, s.last_name, s.register_no,
                   pc.name AS pre_class_name,
                   ps.name AS pre_section_name,
                   psy.school_year AS pre_session_name,
                   prc.name AS pro_class_name,
                   prs.name AS pro_section_name,
                   prsy.school_year AS pro_session_name
            FROM promotion_history ph
            JOIN student s ON s.id = ph.student_id
            LEFT JOIN class pc ON pc.id = ph.pre_class
            LEFT JOIN section ps ON ps.id = ph.pre_section
            LEFT JOIN schoolyear psy ON psy.id = ph.pre_session
            LEFT JOIN class prc ON prc.id = ph.pro_class
            LEFT JOIN section prs ON prs.id = ph.pro_section
            LEFT JOIN schoolyear prsy ON prsy.id = ph.pro_session
            WHERE ph.id = ?
        ");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();

        if (!$data) {
            $response->getBody()->write(json_encode(['error' => 'Not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $studentIds = $data['student_ids'] ?? [];
        if (!is_array($studentIds)) {
            $studentIds = [$studentIds];
        }

        if (empty($studentIds)) {
            $response->getBody()->write(json_encode(['error' => 'No students selected']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $proClass = $data['pro_class'] ?? null;
        $proSection = $data['pro_section'] ?? null;
        $proSession = $data['pro_session'] ?? null;

        if (!$proClass || !$proSection || !$proSession) {
            $response->getBody()->write(json_encode(['error' => 'pro_class, pro_section, and pro_session are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $prevDue = $data['prev_due'] ?? 0;
        $promoted = [];
        $errors = [];

        $db->beginTransaction();
        try {
            $checkEnroll = $db->prepare("SELECT class_id, section_id, session_id FROM enroll WHERE student_id = ?");
            $insertPromo = $db->prepare("
                INSERT INTO promotion_history (student_id, pre_class, pre_section, pre_session, pro_class, pro_section, pro_session, prev_due, is_leave, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            $updateEnroll = $db->prepare("UPDATE enroll SET class_id = ?, section_id = ?, session_id = ? WHERE student_id = ?");

            foreach ($studentIds as $studentId) {
                $checkEnroll->execute([$studentId]);
                $enroll = $checkEnroll->fetch();

                if (!$enroll) {
                    $errors[] = "Student $studentId has no enrollment record";
                    continue;
                }

                $insertPromo->execute([
                    $studentId,
                    $enroll['class_id'],
                    $enroll['section_id'],
                    $enroll['session_id'],
                    $proClass,
                    $proSection,
                    $proSession,
                    $prevDue,
                ]);

                $updateEnroll->execute([$proClass, $proSection, $proSession, $studentId]);
                $promoted[] = [
                    'id' => (int)$db->lastInsertId(),
                    'student_id' => (int)$studentId,
                ];
            }

            $db->commit();

            $result = ['promoted' => $promoted];
            if (!empty($errors)) {
                $result['errors'] = $errors;
            }

            $response->getBody()->write(json_encode($result));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM promotion_history WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
