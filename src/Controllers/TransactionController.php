<?php

namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TransactionController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $branchId = $params['branch_id'] ?? null;
        $accountId = $params['account_id'] ?? null;
        $type = $params['type'] ?? null;
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        $limit = $params['limit'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT t.*, a.name AS account_name, vh.name AS voucher_head_name
                FROM transactions t
                LEFT JOIN accounts a ON CAST(t.account_id AS UNSIGNED) = a.id
                LEFT JOIN voucher_head vh ON vh.id = t.voucher_head_id
                WHERE 1=1";
        $binds = [];

        if ($branchId) {
            $sql .= " AND t.branch_id = ?";
            $binds[] = $branchId;
        }
        if ($accountId) {
            $sql .= " AND t.account_id = ?";
            $binds[] = $accountId;
        }
        if ($type) {
            if ($type === 'income') {
                $sql .= " AND t.type IN ('income', 'deposit')";
            } else {
                $sql .= " AND t.type = ?";
                $binds[] = $type;
            }
        }
        if ($dateFrom) {
            $sql .= " AND t.date >= ?";
            $binds[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND t.date <= ?";
            $binds[] = $dateTo;
        }

        $sql .= " ORDER BY t.date DESC, t.id DESC";
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll();
        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = Database::getConnection();

        $type = $data['type'] ?? 'income';
        $amount = (float)($data['amount'] ?? 0);
        $accountId = $data['account_id'] ?? '0';

        $stmt = $db->prepare("SELECT balance, branch_id FROM accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        $currentBalance = $account ? (float)$account['balance'] : 0;
        $branchId = $data['branch_id'] ?? ($account['branch_id'] ?? null);
        if (!$branchId) {
            $stmt = $db->query("SELECT id FROM branch LIMIT 1");
            $branchId = $stmt->fetchColumn();
        }

        if ($type === 'income') {
            $dr = 0;
            $cr = $amount;
            $newBalance = $currentBalance + $amount;
        } else {
            $dr = $amount;
            $cr = 0;
            $newBalance = $currentBalance - $amount;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $accountId]);

            $stmt = $db->prepare("INSERT INTO transactions (account_id, voucher_head_id, type, category, ref, amount, dr, cr, bal, date, pay_via, description, attachments, branch_id, system)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([
                (string)$accountId,
                (int)($data['voucher_head_id'] ?? 0),
                $type,
                $data['category'] ?? '',
                $data['ref'] ?? '',
                $amount,
                $dr,
                $cr,
                $newBalance,
                $data['date'] ?? date('Y-m-d'),
                $data['pay_via'] ?? 'Cash',
                $data['description'] ?? '',
                $data['attachments'] ?? '',
                $branchId,
            ]);

            $id = $db->lastInsertId();
            $db->commit();

            $response->getBody()->write(json_encode(['id' => (int)$id, 'balance' => $newBalance]));
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

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$args['id']]);
        $txn = $stmt->fetch();

        if (!$txn) {
            $response->getBody()->write(json_encode(['error' => 'Not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $amount = (float)$txn['amount'];
        $type = $txn['type'];
        $accountId = $txn['account_id'];
        $isIncome = $type === 'income' || $type === 'deposit';

        $stmt = $db->prepare("SELECT balance FROM accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        $currentBalance = $account ? (float)$account['balance'] : 0;

        if ($isIncome) {
            $newBalance = $currentBalance - $amount;
        } else {
            $newBalance = $currentBalance + $amount;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $accountId]);

            $stmt = $db->prepare("DELETE FROM transactions WHERE id = ?");
            $stmt->execute([$args['id']]);

            $db->commit();
            $response->getBody()->write(json_encode(['deleted' => 1]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function ledger(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $accountId = $params['account_id'] ?? null;
        $branchId = $params['branch_id'] ?? null;
        $db = Database::getConnection();

        $sql = "SELECT t.*, a.name AS account_name, vh.name AS voucher_head_name
                FROM transactions t
                LEFT JOIN accounts a ON CAST(t.account_id AS UNSIGNED) = a.id
                LEFT JOIN voucher_head vh ON vh.id = t.voucher_head_id
                WHERE 1=1";
        $binds = [];

        if ($accountId) {
            $sql .= " AND t.account_id = ?";
            $binds[] = $accountId;
        }
        if ($branchId) {
            $sql .= " AND t.branch_id = ?";
            $binds[] = $branchId;
        }

        $sql .= " ORDER BY t.date ASC, t.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll();

        $income = 0;
        $expense = 0;
        foreach ($rows as $r) {
            $t = $r['type'];
            if ($t === 'income' || $t === 'deposit') $income += (float)$r['amount'];
            else $expense += (float)$r['amount'];
        }

        $response->getBody()->write(json_encode([
            'transactions' => $rows,
            'total_income' => $income,
            'total_expense' => $expense,
            'balance' => $income - $expense,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
