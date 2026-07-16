<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaffController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT s.*, sd.name AS department_name, dsg.name AS designation_name,
                       lc.active AS login_active, lc.role AS login_role, lc.username
                FROM staff s
                LEFT JOIN staff_department sd ON sd.id = s.department
                LEFT JOIN staff_designation dsg ON dsg.id = s.designation
                LEFT JOIN login_credential lc ON lc.user_id = s.id AND lc.role IN (3, 4, 5, 8)";

        if ($branchId) {
            $sql .= " WHERE s.branch_id = ?";
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
        $stmt = $db->prepare("
            SELECT s.*, sd.name AS department_name, dsg.name AS designation_name,
                   lc.active AS login_active, lc.role AS login_role, lc.username
            FROM staff s
            LEFT JOIN staff_department sd ON sd.id = s.department
            LEFT JOIN staff_designation dsg ON dsg.id = s.designation
            LEFT JOIN login_credential lc ON lc.user_id = s.id AND lc.role IN (3, 4, 5, 8)
            WHERE s.id = ?
        ");
        $stmt->execute([$args['id']]);
        $data = $stmt->fetch();
        $response->getBody()->write(json_encode($data ?: ['error' => 'Not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                INSERT INTO staff (staff_id, name, department, qualification, designation,
                    joining_date, birthday, sex, religion, blood_group,
                    present_address, permanent_address, mobileno, email, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['staff_id'] ?? uniqid(),
                $data['name'] ?? '',
                $data['department'] ?? 0,
                $data['qualification'] ?? '',
                $data['designation'] ?? 0,
                $data['joining_date'] ?? '',
                $data['birthday'] ?? '',
                $data['sex'] ?? '',
                $data['religion'] ?? '',
                $data['blood_group'] ?? '',
                $data['present_address'] ?? '',
                $data['permanent_address'] ?? '',
                $data['mobileno'] ?? '',
                $data['email'] ?? '',
                $data['branch_id'] ?? null,
            ]);

            $staffId = (int)$db->lastInsertId();

            if (!empty($data['username']) && !empty($data['password'])) {
                $hash = password_hash($data['password'], PASSWORD_DEFAULT);
                $role = $data['role'] ?? 3;
                $stmt = $db->prepare("
                    INSERT INTO login_credential (user_id, username, password, role, active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->execute([$staffId, $data['username'], $hash, $role]);
            }

            $db->commit();
            $response->getBody()->write(json_encode(['id' => $staffId]));
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

        $allowed = ['staff_id', 'name', 'department', 'qualification', 'experience_details',
            'total_experience', 'designation', 'joining_date', 'birthday', 'sex', 'religion',
            'blood_group', 'present_address', 'permanent_address', 'mobileno', 'email',
            'salary_template_id', 'branch_id'];

        $fields = [];
        $values = [];
        foreach ($allowed as $field) {
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
        $stmt = $db->prepare("UPDATE staff SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);

        $hasCredFields = !empty($data['username']) || !empty($data['password']) || !empty($data['role']);
        if ($hasCredFields) {
            $lcStmt = $db->prepare("SELECT id FROM login_credential WHERE user_id = ? AND role IN (3, 4, 5, 8)");
            $lcStmt->execute([$args['id']]);
            $existing = $lcStmt->fetch();

            if ($existing) {
                $lcFields = [];
                $lcValues = [];
                if (!empty($data['username'])) {
                    $lcFields[] = 'username = ?';
                    $lcValues[] = $data['username'];
                }
                if (!empty($data['password'])) {
                    $lcFields[] = 'password = ?';
                    $lcValues[] = password_hash($data['password'], PASSWORD_DEFAULT);
                }
                if (!empty($data['role'])) {
                    $lcFields[] = 'role = ?';
                    $lcValues[] = $data['role'];
                }
                if (!empty($lcFields)) {
                    $lcValues[] = $existing['id'];
                    $db->prepare("UPDATE login_credential SET " . implode(', ', $lcFields) . " WHERE id = ?")->execute($lcValues);
                }
            } else {
                $username = $data['username'] ?? ('staff_' . $args['id']);
                $password = !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : password_hash('changeme', PASSWORD_DEFAULT);
                $role = $data['role'] ?? 3;
                $db->prepare("INSERT INTO login_credential (user_id, username, password, role, active) VALUES (?, ?, ?, ?, 1)")
                    ->execute([$args['id'], $username, $password, $role]);
            }
        }

        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM login_credential WHERE user_id = ? AND role IN (3, 4, 5, 8)")->execute([$args['id']]);
        $stmt = $db->prepare("DELETE FROM staff WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['deleted' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function toggleLogin(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $lcStmt = $db->prepare("SELECT id, active FROM login_credential WHERE user_id = ? AND role IN (3, 4, 5, 8)");
        $lcStmt->execute([$args['id']]);
        $cred = $lcStmt->fetch();

        if (!$cred) {
            $stmt = $db->prepare("INSERT INTO login_credential (user_id, username, password, role, active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$args['id'], 'staff_' . $args['id'], password_hash('changeme', PASSWORD_DEFAULT), 3]);
            $response->getBody()->write(json_encode(['active' => 1]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $newActive = $cred['active'] ? 0 : 1;
        $stmt = $db->prepare("UPDATE login_credential SET active = ? WHERE id = ?");
        $stmt->execute([$newActive, $cred['id']]);

        $response->getBody()->write(json_encode(['active' => $newActive]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function changeRole(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $role = $data['role'] ?? null;
        if (!$role || !in_array((int)$role, [3, 4, 5, 8])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid role']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE login_credential SET role = ? WHERE user_id = ? AND role IN (3, 4, 5, 8)");
        $stmt->execute([(int)$role, $args['id']]);
        $response->getBody()->write(json_encode(['updated' => $stmt->rowCount()]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function salaryHistory(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*, lc.username AS paid_by_name
            FROM payslip p
            LEFT JOIN login_credential lc ON lc.id = p.paid_by
            WHERE p.staff_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function paySalary(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $staffId = $args['id'];
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT salary_template_id FROM staff WHERE id = ?");
            $stmt->execute([$staffId]);
            $staff = $stmt->fetch();

            $templateId = $staff['salary_template_id'] ?? 0;
            $basicSalary = $data['basic_salary'] ?? 0;
            $totalAllowance = $data['total_allowance'] ?? 0;
            $totalDeduction = $data['total_deduction'] ?? 0;
            $netSalary = $data['net_salary'] ?? ($basicSalary + $totalAllowance - $totalDeduction);
            $month = $data['month'] ?? date('F');
            $year = $data['year'] ?? date('Y');
            $payVia = $data['pay_via'] ?? 1;
            $remarks = $data['remarks'] ?? '';
            $paidBy = $data['paid_by'] ?? null;
            $branchId = $data['branch_id'] ?? null;
            $billNo = 'SLR-' . time();

            $stmt = $db->prepare("
                INSERT INTO payslip (staff_id, month, year, basic_salary, total_allowance,
                    total_deduction, net_salary, bill_no, remarks, pay_via, paid_by, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $staffId, $month, $year, $basicSalary, $totalAllowance,
                $totalDeduction, $netSalary, $billNo, $remarks, $payVia, $paidBy, $branchId
            ]);

            $payslipId = (int)$db->lastInsertId();

            if (!empty($data['stipends'])) {
                $sStmt = $db->prepare("
                    INSERT INTO payment_salary_stipend (payslip_id, name, amount, type)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($data['stipends'] as $stipend) {
                    $sStmt->execute([
                        $payslipId,
                        $stipend['name'] ?? '',
                        $stipend['amount'] ?? 0,
                        $stipend['type'] ?? 'allowance',
                    ]);
                }
            }

            $db->commit();
            $response->getBody()->write(json_encode(['id' => $payslipId, 'bill_no' => $billNo]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function leaveHistory(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT la.*, lc.name AS category_name
            FROM leave_application la
            LEFT JOIN leave_category lc ON lc.id = la.category_id
            WHERE la.user_id = ? AND la.role_id IN (3, 4, 5, 8)
            ORDER BY la.apply_date DESC
        ");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function applyLeave(Request $request, Response $response, array $args): Response
    {
        $db = Database::getConnection();

        try {
            $data = $request->getParsedBody();

            $start = new \DateTime($data['start_date'] ?? date('Y-m-d'));
            $end = new \DateTime($data['end_date'] ?? date('Y-m-d'));
            $days = $start->diff($end)->days + 1;

            $stmt = $db->prepare("SELECT id FROM staff WHERE id = ?");
            $stmt->execute([$args['id']]);
            if (!$stmt->fetch()) {
                $response->getBody()->write(json_encode(['error' => 'Staff not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $role = $data['role'] ?? 3;

            $stmt = $db->prepare("
                INSERT INTO leave_application (user_id, role_id, category_id, reason, start_date, end_date,
                    leave_days, apply_date, approved_by, orig_file_name, enc_file_name, comments, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 0, '', '', ?, ?)
            ");
            $stmt->execute([
                $args['id'],
                $role,
                $data['category_id'],
                $data['reason'] ?? '',
                $data['start_date'],
                $data['end_date'],
                $days,
                $data['comments'] ?? '',
                $data['branch_id'] ?? null,
            ]);

            $response->getBody()->write(json_encode(['id' => (int)$db->lastInsertId()]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function leaveCategories(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if ($branchId) {
            $stmt = $db->prepare("SELECT * FROM leave_category WHERE branch_id = ? OR branch_id IS NULL ORDER BY name");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("SELECT * FROM leave_category ORDER BY name");
        }

        $categories = $stmt->fetchAll();

        if (empty($categories)) {
            $categories = [
                ['id' => 1, 'name' => 'Sick Leave', 'days' => 12, 'role_id' => 0, 'branch_id' => $branchId ? (int)$branchId : null],
                ['id' => 2, 'name' => 'Casual Leave', 'days' => 10, 'role_id' => 0, 'branch_id' => $branchId ? (int)$branchId : null],
                ['id' => 3, 'name' => 'Annual Leave', 'days' => 20, 'role_id' => 0, 'branch_id' => $branchId ? (int)$branchId : null],
                ['id' => 4, 'name' => 'Emergency Leave', 'days' => 5, 'role_id' => 0, 'branch_id' => $branchId ? (int)$branchId : null],
                ['id' => 5, 'name' => 'Maternity Leave', 'days' => 90, 'role_id' => 0, 'branch_id' => $branchId ? (int)$branchId : null],
            ];
        }

        $response->getBody()->write(json_encode($categories));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function departments(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if ($branchId) {
            $stmt = $db->prepare("SELECT * FROM staff_department WHERE branch_id = ? ORDER BY name");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("SELECT * FROM staff_department ORDER BY name");
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function designations(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        if ($branchId) {
            $stmt = $db->prepare("SELECT * FROM staff_designation WHERE branch_id = ? ORDER BY name");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $db->query("SELECT * FROM staff_designation ORDER BY name");
        }

        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
