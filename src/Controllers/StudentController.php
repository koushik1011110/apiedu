<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StudentController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $classId = $params['class_id'] ?? null;
        $sessionId = $params['session_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT s.*, e.class_id, e.section_id, e.roll, e.session_id, e.branch_id AS enroll_branch_id,
                       c.name AS class_name, sec.name AS section_name, sy.school_year
                FROM student s
                LEFT JOIN enroll e ON e.student_id = s.id
                LEFT JOIN class c ON c.id = e.class_id
                LEFT JOIN section sec ON sec.id = e.section_id
                LEFT JOIN schoolyear sy ON sy.id = e.session_id";

        $conditions = [];
        $values = [];

        if ($branchId) {
            $conditions[] = "e.branch_id = ?";
            $values[] = $branchId;
        }
        if ($classId) {
            $conditions[] = "e.class_id = ?";
            $values[] = $classId;
        }
        if ($sessionId) {
            $conditions[] = "e.session_id = ?";
            $values[] = $sessionId;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
        } else {
            $stmt = $db->query($sql);
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT s.*, e.class_id, e.section_id, e.roll, e.session_id, e.branch_id AS enroll_branch_id,
                   c.name AS class_name, sec.name AS section_name, sy.school_year,
                   sc.name AS category_name,
                   p.name AS parent_name, p.father_name, p.mother_name, p.relation AS parent_relation,
                   p.mobileno AS parent_mobileno, p.email AS parent_email, p.address AS parent_address,
                   p.occupation AS parent_occupation,
                   lc.active AS login_active, lc.username AS login_username
            FROM student s
            LEFT JOIN enroll e ON e.student_id = s.id
            LEFT JOIN class c ON c.id = e.class_id
            LEFT JOIN section sec ON sec.id = e.section_id
            LEFT JOIN schoolyear sy ON sy.id = e.session_id
            LEFT JOIN student_category sc ON sc.id = s.category_id
            LEFT JOIN parent p ON p.id = s.parent_id
            LEFT JOIN login_credential lc ON lc.user_id = s.id AND lc.role = 7
            WHERE s.id = ?
        ");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();
        $response->getBody()->write(json_encode($data ?: ['error' => 'Not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, active FROM login_credential WHERE user_id = ? AND role = 7");
        $stmt->execute([$args['id']]);
        $cred = $stmt->fetch();

        if (!$cred) {
            $response->getBody()->write(json_encode(['error' => 'Student login not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $newActive = $cred['active'] ? 0 : 1;
        $db->prepare("UPDATE login_credential SET active = ? WHERE id = ?")->execute([$newActive, $cred['id']]);
        $response->getBody()->write(json_encode(['active' => $newActive]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function import(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $students = $data['students'] ?? [];
        $branchId = $data['branch_id'] ?? null;
        $sessionId = $data['session_id'] ?? null;

        if (empty($students)) {
            $response->getBody()->write(json_encode(['error' => 'No students provided']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();

        // Pre-load lookups
        $classes = [];
        $classStmt = $db->prepare("SELECT id, name FROM class WHERE branch_id = ?");
        $classStmt->execute([$branchId]);
        foreach ($classStmt->fetchAll() as $r) {
            $classes[strtolower(trim($r['name']))] = $r['id'];
        }

        $sections = [];
        $secStmt = $db->prepare("SELECT id, name FROM section WHERE branch_id = ?");
        $secStmt->execute([$branchId]);
        foreach ($secStmt->fetchAll() as $r) {
            $sections[strtolower(trim($r['name']))] = $r['id'];
        }

        $categories = [];
        $catStmt = $db->prepare("SELECT id, name FROM student_category WHERE branch_id = ?");
        $catStmt->execute([$branchId]);
        foreach ($catStmt->fetchAll() as $r) {
            $categories[strtolower(trim($r['name']))] = $r['id'];
        }

        $sessions = [];
        if ($sessionId) {
            $sessions['_default'] = (int)$sessionId;
        } else {
            $sesStmt = $db->query("SELECT id, school_year FROM schoolyear ORDER BY id DESC LIMIT 1");
            $s = $sesStmt->fetch();
            if ($s) $sessions['_default'] = (int)$s['id'];
        }

        $insertStmt = $db->prepare("
            INSERT INTO student (register_no, admission_date, first_name, last_name, gender,
                birthday, religion, caste, blood_group, mother_tongue,
                current_address, permanent_address, city, state, mobileno, email, category_id, parent_id, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $enrollStmt = $db->prepare("
            INSERT INTO enroll (student_id, class_id, section_id, roll, session_id, branch_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $imported = 0;
        $errors = [];
        $db->beginTransaction();

        try {
            foreach ($students as $i => $s) {
                $row = $i + 1;
                $rowErrors = [];

                $firstName = trim($s['first_name'] ?? '');
                if ($firstName === '') {
                    $errors[] = "Row $row: first_name is required";
                    continue;
                }

                $classId = null;
                $className = trim($s['class_name'] ?? '');
                if ($className !== '') {
                    $key = strtolower($className);
                    $classId = $classes[$key] ?? null;
                    if (!$classId) $rowErrors[] = "class '{$className}' not found";
                }

                $sectionId = null;
                $sectionName = trim($s['section_name'] ?? '');
                if ($sectionName !== '') {
                    $key = strtolower($sectionName);
                    $sectionId = $sections[$key] ?? null;
                    if (!$sectionId) $rowErrors[] = "section '{$sectionName}' not found";
                }

                $roll = !empty($s['roll']) ? (int)$s['roll'] : 0;

                if ($roll > 0 && $classId && $sessionId) {
                    $dup = $db->prepare("SELECT id FROM enroll WHERE roll = ? AND class_id = ? AND session_id = ? AND branch_id = ?");
                    $dup->execute([$roll, $classId, $sessionId, $branchId]);
                    if ($dup->fetch()) {
                        $rowErrors[] = "roll $roll already exists in this class/session";
                    }
                }

                $catId = 0;
                $catName = trim($s['category_name'] ?? '');
                if ($catName !== '') {
                    $key = strtolower($catName);
                    $catId = $categories[$key] ?? 0;
                    if (!$catId) $rowErrors[] = "category '{$catName}' not found";
                }

                $sesId = $sessionId ? (int)$sessionId : ($sessions['_default'] ?? null);

                if (!empty($rowErrors)) {
                    $errors[] = "Row $row: " . implode('; ', $rowErrors);
                    continue;
                }

                $insertStmt->execute([
                    $s['register_no'] ?? '',
                    $s['admission_date'] ?? date('Y-m-d'),
                    $firstName,
                    $s['last_name'] ?? '',
                    $s['gender'] ?? '',
                    $s['birthday'] ?? '',
                    $s['religion'] ?? '',
                    $s['caste'] ?? '',
                    $s['blood_group'] ?? '',
                    $s['mother_tongue'] ?? '',
                    $s['current_address'] ?? '',
                    $s['permanent_address'] ?? '',
                    $s['city'] ?? '',
                    $s['state'] ?? '',
                    $s['mobileno'] ?? '',
                    $s['email'] ?? '',
                    $catId,
                    null,
                    1,
                ]);

                $studentId = (int)$db->lastInsertId();

                if ($classId && $sectionId && $sesId) {
                    $enrollStmt->execute([$studentId, $classId, $sectionId, $roll, $sesId, $branchId]);
                }

                $imported++;
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $result = ['imported' => $imported, 'total' => count($students)];
        if (!empty($errors)) $result['errors'] = $errors;
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                INSERT INTO student (register_no, admission_date, first_name, last_name, gender,
                    birthday, religion, caste, blood_group, mother_tongue,
                    current_address, permanent_address, city, state, mobileno, email, category_id, parent_id, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['register_no'] ?? '',
                $data['admission_date'] ?? date('Y-m-d'),
                $data['first_name'] ?? '',
                $data['last_name'] ?? '',
                $data['gender'] ?? '',
                $data['birthday'] ?? '',
                $data['religion'] ?? '',
                $data['caste'] ?? '',
                $data['blood_group'] ?? '',
                $data['mother_tongue'] ?? '',
                $data['current_address'] ?? '',
                $data['permanent_address'] ?? '',
                $data['city'] ?? '',
                $data['state'] ?? '',
                $data['mobileno'] ?? '',
                $data['email'] ?? '',
                $data['category_id'] ?? 0,
                $data['parent_id'] ?? null,
                $data['active'] ?? 1,
            ]);

            $studentId = (int)$db->lastInsertId();

            if (!empty($data['class_id']) && !empty($data['session_id']) && !empty($data['section_id']) && !empty($data['branch_id'])) {
                if (!empty($data['roll'])) {
                    $dupStmt = $db->prepare("SELECT id FROM enroll WHERE roll = ? AND class_id = ? AND session_id = ? AND branch_id = ?");
                    $dupStmt->execute([$data['roll'], $data['class_id'], $data['session_id'], $data['branch_id']]);
                    if ($dupStmt->fetch()) {
                        $db->rollBack();
                        $response->getBody()->write(json_encode(['error' => 'Roll number already exists for this class and session']));
                        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
                    }
                }
                $stmt = $db->prepare("
                    INSERT INTO enroll (student_id, class_id, section_id, roll, session_id, branch_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $studentId,
                    $data['class_id'],
                    $data['section_id'],
                    $data['roll'] ?? 0,
                    $data['session_id'],
                    $data['branch_id'],
                ]);
            }

            if (!empty($data['username']) && !empty($data['password'])) {
                $hash = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO login_credential (user_id, username, password, role, active)
                    VALUES (?, ?, ?, 7, 1)
                ");
                $stmt->execute([$studentId, $data['username'], $hash]);
            }

            $db->commit();
            $response->getBody()->write(json_encode(['id' => $studentId]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $allowed = ['register_no', 'admission_date', 'first_name', 'last_name', 'gender',
            'birthday', 'religion', 'caste', 'blood_group', 'mother_tongue',
            'current_address', 'permanent_address', 'city', 'state', 'mobileno',
            'email', 'category_id', 'parent_id', 'active'];

        $fields = [];
        $values = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (!empty($fields)) {
            $values[] = $args['id'];
            $db->prepare("UPDATE student SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        }

        $enrollFields = ['class_id', 'section_id', 'roll', 'session_id', 'branch_id'];
        $hasEnrollData = false;
        $eSets = [];
        $eVals = [];
        foreach ($enrollFields as $f) {
            if (array_key_exists($f, $data)) {
                $hasEnrollData = true;
                $eSets[] = "$f = ?";
                $eVals[] = $data[$f];
            }
        }

        if ($hasEnrollData) {
            if (array_key_exists('roll', $data) && !empty($data['roll'])) {
                $classId = $data['class_id'] ?? null;
                $sessionId = $data['session_id'] ?? null;
                $branchId = $data['branch_id'] ?? null;
                if ($classId && $sessionId && $branchId) {
                    $dupStmt = $db->prepare("SELECT e.id FROM enroll e WHERE e.roll = ? AND e.class_id = ? AND e.session_id = ? AND e.branch_id = ? AND e.student_id != ?");
                    $dupStmt->execute([$data['roll'], $classId, $sessionId, $branchId, $args['id']]);
                    if ($dupStmt->fetch()) {
                        $response->getBody()->write(json_encode(['error' => 'Roll number already exists for this class and session']));
                        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
                    }
                }
            }
            $eStmt = $db->prepare("SELECT id FROM enroll WHERE student_id = ?");
            $eStmt->execute([$args['id']]);
            $existing = $eStmt->fetch();
            if ($existing) {
                $eVals[] = $existing['id'];
                $db->prepare("UPDATE enroll SET " . implode(', ', $eSets) . " WHERE id = ?")->execute($eVals);
            } else {
                $finalVals = [$args['id']];
                $cols = ['student_id'];
                foreach ($enrollFields as $f) {
                    if (array_key_exists($f, $data)) {
                        $cols[] = $f;
                        $finalVals[] = $data[$f];
                    }
                }
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $db->prepare("INSERT INTO enroll (" . implode(', ', $cols) . ") VALUES ($placeholders)")->execute($finalVals);
            }
        }

        $response->getBody()->write(json_encode(['updated' => 1]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function feeDetails(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT fa.id AS allocation_id, fa.group_id, fa.session_id, fa.prev_due,
                   fg.name AS group_name, fg.branch_id
            FROM fee_allocation fa
            JOIN fee_groups fg ON fg.id = fa.group_id
            WHERE fa.student_id = ?
        ");
        $stmt->execute([$args['id']]);
        $allocations = $stmt->fetchAll();

        foreach ($allocations as &$alloc) {
            $detailStmt = $db->prepare("
                SELECT fgd.id, fgd.fee_type_id, fgd.amount, fgd.due_date, ft.name AS fee_type_name, ft.fee_code
                FROM fee_groups_details fgd
                JOIN fees_type ft ON ft.id = fgd.fee_type_id
                WHERE fgd.fee_groups_id = ?
            ");
            $detailStmt->execute([$alloc['group_id']]);
            $items = $detailStmt->fetchAll();

            foreach ($items as &$item) {
                $payStmt = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS paid, COALESCE(SUM(discount), 0) AS discount
                    FROM fee_payment_history
                    WHERE allocation_id = ? AND type_id = ?
                ");
                $payStmt->execute([$alloc['allocation_id'], $item['fee_type_id']]);
                $payment = $payStmt->fetch();
                $item['paid'] = $payment['paid'];
                $item['discount'] = $payment['discount'];
                $item['pending'] = max(0, $item['amount'] - $payment['paid'] - $payment['discount']);
            }

            $alloc['items'] = $items;
        }

        $response->getBody()->write(json_encode($allocations));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function transactions(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT fph.*, ft.name AS fee_type_name, fg.name AS group_name,
                   fa.student_id
            FROM fee_payment_history fph
            JOIN fee_allocation fa ON fa.id = fph.allocation_id
            JOIN fees_type ft ON ft.id = fph.type_id
            JOIN fee_groups fg ON fg.id = fa.group_id
            WHERE fa.student_id = ?
            ORDER BY fph.date DESC
        ");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function collectFee(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $payments = $data['payments'] ?? [];
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $insertStmt = $db->prepare("
                INSERT INTO fee_payment_history (allocation_id, type_id, amount, discount, fine, pay_via, remarks, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertTxnStmt = $db->prepare("
                INSERT INTO transactions (account_id, voucher_head_id, type, category, ref, amount, dr, cr, bal, date, pay_via, description, attachments, branch_id, system)
                VALUES (?, ?, 'income', 'fee_collection', ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 1)
            ");

            $updateBalStmt = $db->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE id = ? AND branch_id = ?");

            foreach ($payments as $payment) {
                $insertStmt->execute([
                    $payment['allocation_id'],
                    $payment['fee_type_id'],
                    $payment['amount'] ?? 0,
                    $payment['discount'] ?? 0,
                    $payment['fine'] ?? 0,
                    $payment['pay_via'] ?? 'cash',
                    $payment['remarks'] ?? '',
                    $payment['date'] ?? date('Y-m-d'),
                ]);

                $allocStmt = $db->prepare("SELECT branch_id, student_id FROM fee_allocation WHERE id = ?");
                $allocStmt->execute([$payment['allocation_id']]);
                $alloc = $allocStmt->fetch();
                $branchId = $alloc ? $alloc['branch_id'] : null;

                if ($branchId) {
                    // Find the first account for this branch
                    $acctStmt = $db->prepare("SELECT id, balance FROM accounts WHERE branch_id = ? ORDER BY id LIMIT 1");
                    $acctStmt->execute([$branchId]);
                    $account = $acctStmt->fetch();

                    // Find the 'Fees Collection' voucher head for this branch (fallback to any income head, then to id=1)
                    $vhStmt = $db->prepare("SELECT id FROM voucher_head WHERE type = 'income' AND branch_id = ? ORDER BY id LIMIT 1");
                    $vhStmt->execute([$branchId]);
                    $vh = $vhStmt->fetch();
                    $voucherHeadId = $vh ? $vh['id'] : 1;

                    if ($account) {
                        $netAmount = ($payment['amount'] ?? 0) + ($payment['fine'] ?? 0) - ($payment['discount'] ?? 0);
                        $newBal = $account['balance'] + $netAmount;

                        $insertTxnStmt->execute([
                            (string)$account['id'],
                            $voucherHeadId,
                            $payment['allocation_id'],
                            $netAmount,
                            $netAmount,
                            $newBal,
                            $payment['date'] ?? date('Y-m-d'),
                            $payment['pay_via'] ?? 'cash',
                            'Fee collection - allocation #' . $payment['allocation_id'],
                            '',
                            $branchId,
                        ]);

                        $updateBalStmt->execute([$netAmount, $account['id'], $branchId]);
                    }
                }
            }

            $db->commit();
            $response->getBody()->write(json_encode(['success' => true, 'count' => count($payments)]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function categories(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();
        if ($branchId) {
            $stmt = $db->prepare("SELECT id, name FROM student_category WHERE branch_id = ? ORDER BY name");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("SELECT id, name FROM student_category ORDER BY branch_id, name");
        }
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM student_documents WHERE student_id = ?")->execute([$args['id']]);
        $db->prepare("DELETE FROM enroll WHERE student_id = ?")->execute([$args['id']]);
        $db->prepare("DELETE FROM login_credential WHERE user_id = ? AND role = 7")->execute([$args['id']]);
        $stmt = $db->prepare("DELETE FROM student WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
