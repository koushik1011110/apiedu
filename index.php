<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->add(function (Request $request, RequestHandler $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['status' => 'ok', 'message' => 'School ERP API']));
    return $response->withHeader('Content-Type', 'application/json');
});

// Test / diagnostics
$app->get('/api/test', function (Request $request, Response $response) {
    $dbStatus = 'untested';
    $dbError = null;
    $tables = [];
    try {
        $db = App\Config\Database::getConnection();
        $dbStatus = 'connected';
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $dbStatus = 'failed';
        $dbError = $e->getMessage();
    }

    $data = [
        'status'    => 'ok',
        'app'       => 'School ERP API',
        'php'       => PHP_VERSION,
        'sapi'      => PHP_SAPI,
        'db'        => $dbStatus,
        'tables'    => count($tables),
        'db_error'  => $dbError,
        'env'       => file_exists(__DIR__ . '/../.env') ? 'loaded' : 'missing',
        'endpoints' => [
            'GET /api/test',
            'POST /api/login',
            'GET /api/dashboard/stats',
            'GET /api/branches',
            'GET /api/students',
            'GET /api/staff',
            'GET /api/parents',
        ],
    ];
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Global settings (default session, etc.)
$app->get('/api/settings', function (Request $request, Response $response) {
    $db = App\Config\Database::getConnection();
    $stmt = $db->query("SELECT * FROM global_settings LIMIT 1");
    $settings = $stmt->fetch();
    $response->getBody()->write(json_encode($settings ?: []));
    return $response->withHeader('Content-Type', 'application/json');
});

// Auth
$app->post('/api/login', 'App\Controllers\AuthController:login');
$app->post('/api/logout', 'App\Controllers\AuthController:logout');

// Branches (schools)
$app->get('/api/branches', 'App\Controllers\BranchController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/branches/{id}', 'App\Controllers\BranchController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/branches/{id}/stats', 'App\Controllers\BranchController:stats')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/branches', 'App\Controllers\BranchController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/branches/{id}', 'App\Controllers\BranchController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/branches/{id}', 'App\Controllers\BranchController:destroy')
    ->add('App\Middleware\AuthMiddleware');

// Dashboard
$app->get('/api/dashboard/stats', 'App\Controllers\DashboardController:stats')
    ->add('App\Middleware\AuthMiddleware');

// Students
$app->get('/api/students', 'App\Controllers\StudentController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/students/import', 'App\Controllers\StudentController:import')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/students/{id}', 'App\Controllers\StudentController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/students', 'App\Controllers\StudentController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/students/{id}', 'App\Controllers\StudentController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/students/{id}', 'App\Controllers\StudentController:destroy')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/students/{id}/toggle-login', 'App\Controllers\StudentController:toggleStatus')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/student-categories', 'App\Controllers\StudentController:categories')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/students/{id}/fee-details', 'App\Controllers\StudentController:feeDetails')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/students/{id}/transactions', 'App\Controllers\StudentController:transactions')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/students/{id}/collect-fee', 'App\Controllers\StudentController:collectFee')
    ->add('App\Middleware\AuthMiddleware');

// Teachers
$app->get('/api/teachers', 'App\Controllers\TeacherController:index');
$app->get('/api/teachers/{id}', 'App\Controllers\TeacherController:show');
$app->post('/api/teachers', 'App\Controllers\TeacherController:store');
$app->put('/api/teachers/{id}', 'App\Controllers\TeacherController:update');
$app->delete('/api/teachers/{id}', 'App\Controllers\TeacherController:destroy');

// Staff
$app->get('/api/staff', 'App\Controllers\StaffController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/departments/list', 'App\Controllers\StaffController:departments')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/designations/list', 'App\Controllers\StaffController:designations')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/{id}', 'App\Controllers\StaffController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/staff', 'App\Controllers\StaffController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/staff/{id}', 'App\Controllers\StaffController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/staff/{id}', 'App\Controllers\StaffController:destroy')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/staff/{id}/toggle-login', 'App\Controllers\StaffController:toggleLogin')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/staff/{id}/role', 'App\Controllers\StaffController:changeRole')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/{id}/salary', 'App\Controllers\StaffController:salaryHistory')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/staff/{id}/pay-salary', 'App\Controllers\StaffController:paySalary')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/{id}/leaves', 'App\Controllers\StaffController:leaveHistory')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/staff/{id}/apply-leave', 'App\Controllers\StaffController:applyLeave')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff/leave-categories/list', 'App\Controllers\StaffController:leaveCategories')
    ->add('App\Middleware\AuthMiddleware');

// Leaves
$app->get('/api/leaves', 'App\Controllers\LeaveController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/leaves/{id}/approve', 'App\Controllers\LeaveController:approve')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/leaves/{id}/reject', 'App\Controllers\LeaveController:reject')
    ->add('App\Middleware\AuthMiddleware');

// Classes
$app->get('/api/classes', 'App\Controllers\ClassController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/classes/{id}', 'App\Controllers\ClassController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/classes', 'App\Controllers\ClassController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/classes/{id}', 'App\Controllers\ClassController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/classes/{id}', 'App\Controllers\ClassController:destroy')
    ->add('App\Middleware\AuthMiddleware');

// Sections
$app->get('/api/sections', 'App\Controllers\SectionController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/sections', 'App\Controllers\SectionController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/sections/{id}', 'App\Controllers\SectionController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/sections/{id}', 'App\Controllers\SectionController:destroy')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/sections/assign', 'App\Controllers\SectionController:assign')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/sections/{section_id}/unassign/{class_id}', 'App\Controllers\SectionController:unassign')
    ->add('App\Middleware\AuthMiddleware');

// Parents
$app->get('/api/parents', 'App\Controllers\ParentController:index')
    ->add('App\Middleware\AuthMiddleware');

// Accounts
$app->get('/api/accounts', 'App\Controllers\AccountController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/accounts/{id}', 'App\Controllers\AccountController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/accounts', 'App\Controllers\AccountController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/accounts/{id}', 'App\Controllers\AccountController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/accounts/{id}', 'App\Controllers\AccountController:destroy')
    ->add('App\Middleware\AuthMiddleware');

// Voucher Heads
$app->get('/api/voucher-heads', 'App\Controllers\VoucherHeadController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/voucher-heads', 'App\Controllers\VoucherHeadController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/voucher-heads/{id}', 'App\Controllers\VoucherHeadController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/voucher-heads/{id}', 'App\Controllers\VoucherHeadController:destroy')
    ->add('App\Middleware\AuthMiddleware');

// Transactions
$app->get('/api/transactions', 'App\Controllers\TransactionController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/transactions', 'App\Controllers\TransactionController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/transactions/{id}', 'App\Controllers\TransactionController:destroy')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/transactions/ledger', 'App\Controllers\TransactionController:ledger')
    ->add('App\Middleware\AuthMiddleware');

// Attendance
$app->get('/api/attendance', 'App\Controllers\AttendanceController:index');
$app->post('/api/attendance', 'App\Controllers\AttendanceController:store');

// Student Attendance
$app->get('/api/student-attendance', 'App\Controllers\StudentAttendanceController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/student-attendance', 'App\Controllers\StudentAttendanceController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/student-attendance/students', 'App\Controllers\StudentAttendanceController:studentsForDate')
    ->add('App\Middleware\AuthMiddleware');

// Staff Attendance
$app->get('/api/staff-attendance', 'App\Controllers\StaffAttendanceController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/staff-attendance', 'App\Controllers\StaffAttendanceController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/staff-attendance/me', 'App\Controllers\StaffAttendanceController:myAttendance')
    ->add('App\Middleware\AuthMiddleware');



// Grades
$app->get('/api/grades', 'App\Controllers\GradeController:index');
$app->post('/api/grades', 'App\Controllers\GradeController:store');
$app->put('/api/grades/{id}', 'App\Controllers\GradeController:update');

// Fees
$app->get('/api/fees', 'App\Controllers\FeeController:index');
$app->post('/api/fees', 'App\Controllers\FeeController:store');
$app->put('/api/fees/{id}', 'App\Controllers\FeeController:update');

// Fee Management (types, groups, allocations)
$app->get('/api/fee-types', 'App\Controllers\FeeManagementController:feeTypes')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/fee-types', 'App\Controllers\FeeManagementController:storeFeeType')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/fee-types/{id}', 'App\Controllers\FeeManagementController:updateFeeType')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/fee-types/{id}', 'App\Controllers\FeeManagementController:deleteFeeType')
    ->add('App\Middleware\AuthMiddleware');

$app->get('/api/fee-groups', 'App\Controllers\FeeManagementController:feeGroups')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/fee-groups', 'App\Controllers\FeeManagementController:storeFeeGroup')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/fee-groups/{id}', 'App\Controllers\FeeManagementController:updateFeeGroup')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/fee-groups/{id}', 'App\Controllers\FeeManagementController:deleteFeeGroup')
    ->add('App\Middleware\AuthMiddleware');

$app->get('/api/fee-allocations', 'App\Controllers\FeeManagementController:feeAllocations')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/fee-allocations', 'App\Controllers\FeeManagementController:storeAllocation')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/fee-allocations/{id}', 'App\Controllers\FeeManagementController:deleteAllocation')
    ->add('App\Middleware\AuthMiddleware');

$app->get('/api/enrolled-students', 'App\Controllers\FeeManagementController:students')
    ->add('App\Middleware\AuthMiddleware');

// Timetable
$app->get('/api/timetable', 'App\Controllers\TimetableController:index');
$app->post('/api/timetable', 'App\Controllers\TimetableController:store');

// Academic Sessions (School Years)
$app->get('/api/schoolyears', 'App\Controllers\SchoolyearController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/schoolyears/{id}', 'App\Controllers\SchoolyearController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/schoolyears', 'App\Controllers\SchoolyearController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->put('/api/schoolyears/{id}', 'App\Controllers\SchoolyearController:update')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/schoolyears/{id}', 'App\Controllers\SchoolyearController:destroy')
    ->add('App\Middleware\AuthMiddleware');

// Promotions
$app->get('/api/promotions', 'App\Controllers\PromotionController:index')
    ->add('App\Middleware\AuthMiddleware');
$app->get('/api/promotions/{id}', 'App\Controllers\PromotionController:show')
    ->add('App\Middleware\AuthMiddleware');
$app->post('/api/promotions', 'App\Controllers\PromotionController:store')
    ->add('App\Middleware\AuthMiddleware');
$app->delete('/api/promotions/{id}', 'App\Controllers\PromotionController:destroy')
    ->add('App\Middleware\AuthMiddleware');

$app->run();
