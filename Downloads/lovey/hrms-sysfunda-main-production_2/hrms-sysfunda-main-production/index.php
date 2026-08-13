<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/permissions.php';

$user = current_user();
$role = strtolower((string)($user['role'] ?? ''));
$isEmployeeRole = $role === 'employee';
$pageTitle = $isEmployeeRole ? 'Home' : 'Dashboard';

require_once __DIR__ . '/includes/header.php';

$pdo = get_db_conn();

// Get user's position and permissions for customization
$userPositionId = get_user_position_id($user['id']);
$userPosition = null;
if ($userPositionId) {
    try {
        $stmt = $pdo->prepare('SELECT name FROM positions WHERE id = :pid LIMIT 1');
        $stmt->execute([':pid' => $userPositionId]);
        $userPosition = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $userPosition = null;
    }
}

// Check key permissions for dashboard customization
$canManageEmployees = user_has_access($user['id'], 'hr_core', 'employees', 'manage');
$canManagePayroll = user_has_access($user['id'], 'payroll', 'payroll_cycles', 'manage');
$canViewPayroll = user_has_access($user['id'], 'payroll', 'payroll_cycles', 'read');
$canApproveLeaves = user_has_access($user['id'], 'hr_core', 'leave_approval', 'write');
$canViewAttendance = user_has_access($user['id'], 'hr_core', 'attendance', 'read');
$canManageAttendance = user_has_access($user['id'], 'hr_core', 'attendance', 'write');
$canViewReports = user_has_access($user['id'], 'reporting', 'hr_reports', 'read');
$canManageRecruitment = user_has_access($user['id'], 'hr_core', 'recruitment', 'write');
$canManageSystem = user_has_access($user['id'], 'system', 'system_settings', 'manage');

if ($isEmployeeRole) {
  $uid = (int)($user['id'] ?? 0);
  $employee = null;
  $departmentId = null;
  try {
    $stmt = $pdo->prepare('SELECT id, employee_code, first_name, last_name, department_id FROM employees WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $departmentId = $employee ? (int)($employee['department_id'] ?? 0) : null;
  } catch (Throwable $e) {
    $employee = null;
    $departmentId = null;
  }

  if (!$employee) {
    echo '<div class="card p-4 max-w-xl">';
    show_human_error('Your account is not linked to an employee profile.');
    echo '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
  }

  $employeeId = (int)$employee['id'];
  $departmentName = null;
  if ($departmentId) {
    try {
      $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = :dept LIMIT 1');
      $stmt->execute([':dept' => $departmentId]);
      $departmentName = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
      $departmentName = null;
    }
  }

  $entitlementLayers = leave_collect_entitlement_layers($pdo, $employeeId);
  $leaveEntitlements = $entitlementLayers['effective'];
  $knownLeaveTypes = leave_get_known_types($pdo);
  foreach ($knownLeaveTypes as $leaveTypeCode) {
    if (!array_key_exists($leaveTypeCode, $leaveEntitlements)) {
      $leaveEntitlements[$leaveTypeCode] = 0;
    }
  }
  $orderedEntitlements = [];
  foreach ($knownLeaveTypes as $leaveTypeCode) {
    $orderedEntitlements[$leaveTypeCode] = (float)($leaveEntitlements[$leaveTypeCode] ?? 0);
  }
  $leaveEntitlements = $orderedEntitlements;
  $leaveBalances = leave_calculate_balances($pdo, $employeeId, $leaveEntitlements);
  $totalAvailableLeave = 0.0;
  foreach ($leaveBalances as $balance) {
    $totalAvailableLeave += max(0, (float)$balance);
  }
  $pendingLeavesCount = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = :eid AND status = 'pending'");
    $stmt->execute([':eid' => $employeeId]);
    $pendingLeavesCount = (int)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $pendingLeavesCount = 0;
  }
  $pendingLeaves = [];
  try {
    $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid AND status = 'pending' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':eid' => $employeeId]);
    $pendingLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $pendingLeaves = [];
  }
  $recentLeaves = [];
  try {
    $stmt = $pdo->prepare("SELECT id, leave_type, start_date, end_date, total_days, status, created_at FROM leave_requests WHERE employee_id = :eid ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':eid' => $employeeId]);
    $recentLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $recentLeaves = [];
  }
  $latestPayslip = null;
  try {
    $stmt = $pdo->prepare("SELECT ps.id, ps.net_pay, ps.released_at, ps.period_start, ps.period_end, ps.status
                             FROM payslips ps
                            WHERE ps.employee_id = :eid
                              AND (ps.released_at IS NOT NULL OR ps.status = 'released')
                            ORDER BY COALESCE(ps.released_at, ps.updated_at, ps.created_at) DESC, ps.id DESC
                            LIMIT 1");
    $stmt->execute([':eid' => $employeeId]);
    $latestPayslip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $latestPayslip = null;
  }
  $recentMemos = [];
  try {
    $stmt = $pdo->prepare("SELECT d.id, d.title, d.file_path, d.created_at
                            FROM documents d
                            LEFT JOIN document_assignments da ON da.document_id = d.id
                            LEFT JOIN employees e ON e.id = da.employee_id
                            WHERE d.doc_type = 'memo'
                              AND (
                                da.employee_id = :eid
                                OR da.department_id = :dept
                                OR (da.employee_id IS NULL AND da.department_id IS NULL)
                              )
                            GROUP BY d.id
                            ORDER BY d.id DESC, d.created_at DESC
                            LIMIT 5");
    $stmt->execute([
      ':eid' => $employeeId,
      ':dept' => $departmentId,
    ]);
    $recentMemos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $recentMemos = [];
  }
  $todayLabel = date('l, F j, Y');
  $fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
  ?>
  <div class="space-y-6">
    <div class="page-intro flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="hint-text uppercase tracking-widest text-[11px]">Today • <?= htmlspecialchars($todayLabel) ?></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100 md:text-3xl">Welcome back, <?= htmlspecialchars($fullName ?: 'there') ?>.</h1>
        <p class="hint-text mt-1">Your time-off, payroll, and company updates — all in one place.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/leave/create">+ File Leave</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/modules/payroll/my_payslips">View Payslips</a>
      </div>
    </div>

    <section class="grid gap-4 grid-cols-1 lg:grid-cols-4">
      <div class="card card-body transition hover:-translate-y-0.5">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide hint-text">Available Leave</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900 dark:text-slate-100"><?= number_format($totalAvailableLeave, 1) ?> <span class="text-base font-normal hint-text">days</span></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-indigo-500 dark:bg-indigo-500/15 dark:text-indigo-300"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></span>
        </div>
        <p class="mt-3 hint-text">Across all leave types.</p>
      </div>
      <div class="card card-body transition hover:-translate-y-0.5">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide hint-text">Pending Requests</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900 dark:text-slate-100"><?= (int)$pendingLeavesCount ?></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-500 dark:bg-amber-500/15 dark:text-amber-300"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
        </div>
        <a class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300" href="<?= BASE_URL ?>/modules/leave/create#pending">Review requests →</a>
      </div>
      <div class="card card-body transition hover:-translate-y-0.5">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide hint-text">Latest Payslip</p>
            <?php if ($latestPayslip): ?>
              <p class="mt-3 text-3xl font-semibold text-slate-900 dark:text-slate-100"><?= number_format((float)($latestPayslip['net_pay'] ?? 0), 2) ?></p>
            <?php else: ?>
              <p class="mt-3 text-lg font-semibold hint-text">Not released</p>
            <?php endif; ?>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-500 dark:bg-emerald-500/15 dark:text-emerald-300"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></span>
        </div>
        <?php if ($latestPayslip): ?>
          <p class="mt-3 hint-text">
            <?= htmlspecialchars(($latestPayslip['period_start'] ?? '') . ' → ' . ($latestPayslip['period_end'] ?? '')) ?>
            <?php if (!empty($latestPayslip['released_at'])): ?>
              <?php $latestReleaseLabel = format_datetime_display($latestPayslip['released_at'], false, ''); ?>
              <?php if ($latestReleaseLabel !== ''): ?>
                • Released <?= htmlspecialchars($latestReleaseLabel) ?>
              <?php endif; ?>
            <?php endif; ?>
          </p>
          <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <a class="btn btn-primary" style="padding:.375rem .75rem;" href="<?= BASE_URL ?>/modules/payroll/view?id=<?= (int)$latestPayslip['id'] ?>">Open</a>
            <a class="btn btn-outline" style="padding:.375rem .75rem;" href="<?= BASE_URL ?>/modules/payroll/pdf_payslip?id=<?= (int)$latestPayslip['id'] ?>" target="_blank" rel="noopener" data-no-loader>PDF</a>
          </div>
        <?php else: ?>
          <a class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300" href="<?= BASE_URL ?>/modules/payroll/my_payslips">Check history →</a>
        <?php endif; ?>
      </div>
      <div class="card card-body transition hover:-translate-y-0.5">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide hint-text">Your Profile</p>
            <p class="mt-3 text-lg font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($departmentName ?? 'Unassigned') ?></p>
          </div>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-sky-50 text-sky-500 dark:bg-sky-500/15 dark:text-sky-300"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg></span>
        </div>
        <p class="mt-3 hint-text">Employee #<?= htmlspecialchars($employee['employee_code'] ?? '—') ?> · Contact your department lead for help.</p>
      </div>
    </section>

    <section class="grid gap-4 grid-cols-1 xl:grid-cols-3">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm xl:col-span-2">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <h2 class="text-base font-semibold text-slate-800">Leave Balances</h2>
          <a class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">File new leave →</a>
        </div>
        <div class="mt-4 space-y-3">
          <?php foreach ($leaveEntitlements as $type => $entitled):
            $remaining = (float)($leaveBalances[$type] ?? 0);
            $total = (float)$entitled;
            $used = max(0.0, $total - $remaining);
            $percentUsed = $total > 0 ? min(100, round(($used / $total) * 100)) : 0;
          ?>
            <div class="rounded-lg border border-slate-100 p-3">
              <div class="flex items-start justify-between">
                <div>
                  <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars(leave_label_for_type($type)) ?></p>
                  <p class="text-xs text-slate-500">Entitled <?= number_format($total, 1) ?> day(s) • Remaining <?= number_format($remaining, 1) ?></p>
                </div>
                <span class="text-xs font-semibold text-indigo-600"><?= $percentUsed ?>% used</span>
              </div>
              <div class="mt-3 h-2 rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500" style="width: <?= $total > 0 ? $percentUsed : 0 ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm" id="pending">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Pending Leave Requests</h2>
            <p class="text-xs text-slate-500">Track approvals in progress.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">Manage</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($pendingLeaves): ?>
            <?php foreach ($pendingLeaves as $row): ?>
              <article class="rounded-lg border border-amber-100 bg-amber-50/60 p-3">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-amber-900"><?= htmlspecialchars(leave_label_for_type($row['leave_type'])) ?></span>
                  <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-600">Pending</span>
                </div>
                <p class="mt-1 text-xs text-amber-800"><?= htmlspecialchars($row['start_date']) ?> → <?= htmlspecialchars($row['end_date']) ?> • <?= number_format((float)($row['total_days'] ?? 0), 2) ?> day(s)</p>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/view?id=<?= (int)$row['id'] ?>">View details →</a>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No pending leave requests right now.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="grid gap-4 grid-cols-1 lg:grid-cols-2">
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Recent Leave Activity</h2>
            <p class="text-xs text-slate-500">Your last five filings in chronological order.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/leave/create">Full history</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($recentLeaves): ?>
            <?php foreach ($recentLeaves as $row): ?>
              <article class="rounded-lg border border-slate-100 p-3">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-slate-900"><?= htmlspecialchars(leave_label_for_type($row['leave_type'])) ?></span>
                  <?php
                    $status = strtolower((string)($row['status'] ?? ''));
                    $statusColor = $status === 'approved' ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : ($status === 'rejected' ? 'text-red-600 bg-red-50 border-red-100' : 'text-slate-600 bg-slate-50 border-slate-100');
                  ?>
                  <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide <?= $statusColor ?>"><?= htmlspecialchars(ucfirst($status ?: 'Pending')) ?></span>
                </div>
                <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($row['start_date']) ?> → <?= htmlspecialchars($row['end_date']) ?> • <?= number_format((float)($row['total_days'] ?? 0), 2) ?> day(s)</p>
                <p class="mt-1 text-xs text-slate-400">Filed <?= htmlspecialchars(date('M d, Y', strtotime($row['created_at'] ?? 'now'))) ?></p>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No leave requests on record.</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <h2 class="text-base font-semibold text-slate-800">Latest Memos</h2>
            <p class="text-xs text-slate-500">Stay up-to-date with company communications.</p>
          </div>
          <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/documents/memo.php">View all</a>
        </div>
        <div class="mt-4 space-y-3 text-sm">
          <?php if ($recentMemos): ?>
            <?php foreach ($recentMemos as $memo): ?>
              <article class="group rounded-lg border border-slate-100 p-3 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md">
                <h3 class="font-medium text-slate-900 group-hover:text-indigo-600"><?= htmlspecialchars($memo['title']) ?></h3>
                <p class="mt-1 text-xs text-slate-500">Published <?= htmlspecialchars(date('M d, Y', strtotime($memo['created_at'] ?? 'now'))) ?></p>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/<?= ltrim($memo['file_path'], '/') ?>" target="_blank" rel="noopener">Open memo →</a>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No memos available right now.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
  <?php
} else {
  if (!function_exists('scalar')) {
    function scalar(string $sql, array $params = []) {
      $pdo = get_db_conn();
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $val = $st->fetchColumn();
      return (int)($val ?? 0);
    }
  }

  $totalEmployees = $canManageEmployees || $canViewReports ? scalar('SELECT COUNT(*) FROM employees') : 0;
  $activeLeaves = $canApproveLeaves ? scalar("SELECT COUNT(*) FROM leave_requests WHERE status='pending'") : 0;
  $today = date('Y-m-d');
  $presentToday = $canViewAttendance ? scalar("SELECT COUNT(*) FROM attendance WHERE date = :d AND status = 'present'", [':d' => $today]) : 0;
  $payrollReleased = $canViewPayroll ? scalar('SELECT COUNT(*) FROM payroll WHERE released_at::date = CURRENT_DATE') : 0;
  $adminToday = date('l, F j, Y');

  // Pending Requests: aggregate ALL request types (leave, overtime, manual override)
  $pendingOT = 0;
  try { $pendingOT = scalar("SELECT COUNT(*) FROM overtime_requests WHERE status='pending'"); } catch (Throwable $e) { $pendingOT = 0; }
  $totalPendingRequests = $activeLeaves + $pendingOT;

  // Inventory stats
  $canViewInventory = user_has_access($user['id'], 'inventory', 'inventory_items', 'read');
  $invAvailable = 0; $invLowStock = 0; $invOutOfStock = 0; $invExpiringSoon = 0;
  if ($canViewInventory) {
    try {
      $invAvailable = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand > reorder_level");
      $invLowStock = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand > 0 AND qty_on_hand <= reorder_level");
      $invOutOfStock = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND qty_on_hand = 0");
      $invExpiringSoon = scalar("SELECT COUNT(*) FROM inv_items WHERE is_active = TRUE AND expiry_date IS NOT NULL AND expiry_date <= (CURRENT_DATE + INTERVAL '30 days') AND expiry_date >= CURRENT_DATE");
    } catch (Throwable $e) { /* tables may not exist yet */ }
  }
  
  // Determine dashboard title based on position and role
  $dashboardTitle = 'Dashboard';
  $dashboardSubtitle = 'Your workspace overview';
  if ($userPosition) {
      $dashboardTitle = htmlspecialchars($userPosition) . ' Dashboard';
      $dashboardSubtitle = 'Tools and insights for your role';
  } elseif ($role === 'admin') {
      $dashboardTitle = 'People Operations Pulse';
      $dashboardSubtitle = 'Monitor workforce trends, approvals, and payroll releases at a glance';
  }
  ?>
  <div class="space-y-6">
    <div class="rounded-xl p-6 text-white shadow-lg" style="background: var(--brand-black);">
      <div class="page-intro flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-4">
        <div>
          <p class="text-[11px] uppercase tracking-widest text-white/60"><?= htmlspecialchars($userPosition ?: 'Admin Overview') ?> • <?= htmlspecialchars($adminToday) ?></p>
          <h1 class="mt-1 text-2xl font-semibold md:text-3xl"><?= $dashboardTitle ?></h1>
          <p class="mt-1 text-sm text-white/70"><?= $dashboardSubtitle ?></p>
        </div>
      </div>

    <?php
    // Build dynamic grid of cards based on permissions
    $metricCards = [];
    
    if ($canManageEmployees || $canViewReports) {
        $metricCards[] = [
            'title' => 'Total Employees',
            'value' => $totalEmployees,
            'description' => 'Active headcount across all departments.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
            'color' => 'indigo',
            'href' => BASE_URL . '/modules/employees/index'
        ];
    }
    
    if ($canApproveLeaves) {
        $metricCards[] = [
            'title' => 'Pending Requests',
            'value' => $totalPendingRequests,
            'description' => 'Leave (' . $activeLeaves . '), Overtime (' . $pendingOT . ') awaiting approval.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
            'color' => 'amber',
            'href' => BASE_URL . '/modules/leave/admin?status=pending'
        ];
    }
    
    if ($canViewAttendance) {
        $metricCards[] = [
            'title' => 'Present Today',
            'value' => $presentToday,
            'description' => 'Registered attendance for ' . date('M d, Y') . '.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'color' => 'emerald',
            'href' => BASE_URL . '/modules/attendance/index?from=' . urlencode(date('Y-m-d')) . '&to=' . urlencode(date('Y-m-d'))
        ];
    }
    
    if ($canViewPayroll) {
        $metricCards[] = [
            'title' => 'Payroll Released Today',
            'value' => $payrollReleased,
            'description' => 'Net payslips handed off in the last 24 hours.',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
            'color' => 'sky',
            'href' => BASE_URL . '/modules/payroll/index?released=today'
        ];
    }
    
    $gridCols = count($metricCards) === 1 ? 'lg:grid-cols-1 max-w-md' : (count($metricCards) === 2 ? 'lg:grid-cols-2' : (count($metricCards) === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4'));
    ?>
    
    <?php if ($metricCards): ?>
    <section class="grid gap-3 <?= $gridCols ?>">
      <?php foreach ($metricCards as $card): ?>
      <a class="group block rounded-lg bg-white/10 p-4 transition hover:bg-white/15" href="<?= htmlspecialchars($card['href']) ?>" title="<?= htmlspecialchars($card['title']) ?>">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wide text-white/60"><?= htmlspecialchars($card['title']) ?></span>
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-<?= $card['color'] ?>-500/15 text-<?= $card['color'] ?>-300"><?= $card['icon'] ?></span>
        </div>
        <p class="mt-3 text-2xl font-semibold"><?= $card['value'] ?></p>
        <p class="mt-1 text-xs text-white/60"><?= htmlspecialchars($card['description']) ?></p>
      </a>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
    </div>

    <?php
    $defaultCardOrder = ['secActionCenter', 'secInventoryStatus', 'secChartsTrends', 'secNotifications'];
    $savedCardOrder = null;
    $savedQuickActionKeys = null;
    try {
        $stmt = $pdo->prepare('SELECT layout, quick_actions FROM user_dashboard_layout WHERE user_id = :uid');
        $stmt->execute([':uid' => $user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['layout']) {
            $decoded = json_decode($row['layout'], true);
            if (is_array($decoded)) { $savedCardOrder = $decoded; }
        }
        if ($row && $row['quick_actions']) {
            $decodedQa = json_decode($row['quick_actions'], true);
            if (is_array($decodedQa)) { $savedQuickActionKeys = $decodedQa; }
        }
    } catch (Throwable $e) { $savedCardOrder = null; $savedQuickActionKeys = null; }
    $dashboardCards = [];
    ?>

    <?php if ($canViewInventory): ob_start(); ?>
    <section class="rounded-xl border border-slate-100 bg-white shadow-sm">
      <button type="button" class="section-collapsible-header p-6" data-section-toggle="secInventoryStatus" aria-expanded="false">
        <div>
          <h2 class="text-base font-semibold text-slate-800 text-left">Inventory Status</h2>
          <p class="hint-text">Stock on hand, low/out-of-stock counts, and items expiring soon.</p>
        </div>
        <div class="section-collapsible-right">
          <span class="section-collapsible-summary"><?= $invLowStock + $invOutOfStock ?> item(s) need attention</span>
          <svg class="section-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
        </div>
      </button>
      <div id="secInventoryStatus" class="section-collapsible-body is-collapsed px-5 pb-5">
      <div class="flex justify-end mb-3">
        <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/inventory/inventory">View Inventory</a>
      </div>
      <div class="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-slate-900"><?= $invAvailable ?></p>
              <p class="text-xs text-slate-500">Available Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-amber-600"><?= $invLowStock ?></p>
              <p class="text-xs text-slate-500">Low Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-red-100 text-red-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-red-600"><?= $invOutOfStock ?></p>
              <p class="text-xs text-slate-500">Out of Stock</p>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-100 bg-white p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-purple-100 text-purple-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </span>
            <div>
              <p class="text-2xl font-bold text-purple-600"><?= $invExpiringSoon ?></p>
              <p class="text-xs text-slate-500">Expiring Soon</p>
            </div>
          </div>
        </div>
      </div>
      </div>
    </section>
    <?php $dashboardCards['secInventoryStatus'] = ob_get_clean(); endif; ?>

    <?php
    $hasAnyChart = ($canViewAttendance || $canManageAttendance) || ($canManageEmployees || $canViewReports) || ($canApproveLeaves || $canViewReports);
    ?>
    <?php if ($hasAnyChart): ob_start(); ?>
    <section class="rounded-xl border border-slate-100 bg-white shadow-sm">
      <button type="button" class="section-collapsible-header p-6" data-section-toggle="secChartsTrends" aria-expanded="false">
        <div>
          <h2 class="text-base font-semibold text-slate-800 text-left">Charts &amp; Trends</h2>
          <p class="hint-text">Attendance, headcount, payroll, and leave trends over time.</p>
        </div>
        <svg class="section-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
      </button>
      <div id="secChartsTrends" class="section-collapsible-body is-collapsed px-5 pb-5 space-y-4">
    <?php if ($canViewAttendance || $canManageAttendance || $canManageEmployees || $canViewReports || $canApproveLeaves): ?>
    <section class="grid gap-4 grid-cols-1 lg:grid-cols-2 xl:grid-cols-3">

      <?php if ($canViewAttendance || $canManageAttendance): ?>
      <div class="card card-body">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-slate-800">Attendance (Last 7 days)</h2>
            <p class="hint-text">Presence mix across the past week.</p>
          </div>
          <a class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500 whitespace-nowrap" href="<?= BASE_URL ?>/modules/attendance/index">Open hub</a>
        </div>
        <?php
          $rows = [];
          try {
            $rows = $pdo->query("SELECT date::date AS d,
              COUNT(*) FILTER (WHERE status='present') AS present,
              COUNT(*) FILTER (WHERE status='late') AS late,
              COUNT(*) FILTER (WHERE status='absent') AS absent
              FROM attendance WHERE date >= (CURRENT_DATE - INTERVAL '6 days') GROUP BY d ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $labels = [];$present=[];$late=[];$absent=[];
          $period = new DatePeriod(new DateTime('-6 days'), new DateInterval('P1D'), (new DateTime('tomorrow')));
          $byD = [];
          foreach ($rows as $r) { $byD[$r['d']] = $r; }
          foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $labels[] = $dt->format('D, M d');
            $present[] = (int)($byD[$d]['present'] ?? 0);
            $late[] = (int)($byD[$d]['late'] ?? 0);
            $absent[] = (int)($byD[$d]['absent'] ?? 0);
          }
        ?>
        <div class="mt-3" style="position:relative; width:100%; height:160px;">
          <canvas id="chartAttendance"
            data-chart="line"
            data-labels='<?= json_encode($labels) ?>'
            data-datasets='<?= json_encode([
              ["label"=>"Present","data"=>$present,"borderColor"=>"#16a34a","backgroundColor"=>"rgba(22,163,74,0.12)","tension"=>0.3,"fill"=>true],
              ["label"=>"Late","data"=>$late,"borderColor"=>"#f59e0b","backgroundColor"=>"rgba(245,158,11,0.12)","tension"=>0.3,"fill"=>true],
              ["label"=>"Absent","data"=>$absent,"borderColor"=>"#ef4444","backgroundColor"=>"rgba(239,68,68,0.12)","tension"=>0.3,"fill"=>true],
            ]) ?>'
          ></canvas>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($canManageEmployees || $canViewReports): ?>
      <div class="card card-body">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-slate-800">Headcount Trend</h2>
            <p class="hint-text">Active employees, 12 months.</p>
          </div>
          <a class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500 whitespace-nowrap" href="<?= BASE_URL ?>/modules/employees/index">Roster</a>
        </div>
        <?php
          $hcLabels = [];$hcData=[];
          try {
            $stHc = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'active' AND hire_date <= :month_end");
            for ($i=11;$i>=0;$i--) {
              $dt = (new DateTime("first day of -$i month"));
              $monthEnd = (clone $dt)->modify('last day of this month')->format('Y-m-d');
              $hcLabels[] = $dt->format('M Y');
              $stHc->execute([':month_end' => $monthEnd]);
              $hcData[] = (int)($stHc->fetchColumn() ?: 0);
            }
          } catch (Throwable $e) {
            for ($i=11;$i>=0;$i--) {
              $dt = (new DateTime("first day of -$i month"));
              $hcLabels[] = $dt->format('M Y');
              $hcData[] = 0;
            }
          }
        ?>
        <div class="mt-3" style="position:relative; width:100%; height:160px;">
          <canvas id="chartHeadcount"
            data-chart="bar"
            data-labels='<?= json_encode($hcLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Active Employees","data"=>$hcData,"backgroundColor"=>"rgba(59,130,246,0.45)"]]) ?>'
          ></canvas>
        </div>
      </div>

      <div class="card card-body">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-slate-800">Payroll Totals</h2>
            <p class="hint-text">Net pay released, 12 months.</p>
          </div>
          <a class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500 whitespace-nowrap" href="<?= BASE_URL ?>/modules/payroll/index">Payroll</a>
        </div>
        <?php
          $plLabels = [];$plData=[];
          for ($i=11;$i>=0;$i--) {
            $dt = (new DateTime("first day of -$i month"));
            $start = $dt->format('Y-m-01');
            $nextMonth = (clone $dt)->modify('first day of next month')->format('Y-m-01');
            $plLabels[] = $dt->format('M Y');
            try {
              $st = $pdo->prepare("SELECT COALESCE(SUM(net_pay),0) FROM payroll WHERE released_at >= :start::date AND released_at < :next::date");
              $st->execute([':start'=>$start, ':next'=>$nextMonth]);
              $plData[] = (float)($st->fetchColumn() ?: 0);
            } catch (Throwable $e) { $plData[] = 0.0; }
          }
        ?>
        <div class="mt-3" style="position:relative; width:100%; height:160px;">
          <canvas id="chartPayrollTotals"
            data-chart="line"
            data-labels='<?= json_encode($plLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Net Pay Released","data"=>$plData,"borderColor"=>"#10b981","backgroundColor"=>"rgba(16,185,129,0.12)","tension"=>0.3]]) ?>'
          ></canvas>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($canApproveLeaves || $canViewReports): ?>
      <div class="card card-body">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-slate-800">Leave Mix</h2>
            <p class="hint-text">Status breakdown, last 90 days.</p>
          </div>
          <a class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500 whitespace-nowrap" href="<?= BASE_URL ?>/modules/leave/admin?status=pending">Queue</a>
        </div>
        <?php
          try {
            $rows = $pdo->query("SELECT status, COUNT(*) c FROM leave_requests WHERE created_at >= (CURRENT_DATE - INTERVAL '90 days') GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $lvLabels = array_column($rows, 'status');
          $lvCounts = array_map('intval', array_column($rows, 'c'));
        ?>
        <div class="mt-3" style="position:relative; width:100%; height:160px;">
          <canvas id="chartLeavesStatus"
            data-chart="doughnut"
            data-labels='<?= json_encode($lvLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Requests","data"=>$lvCounts,"backgroundColor"=>["#60a5fa","#34d399","#f87171","#a3a3a3"]]]) ?>'
          ></canvas>
        </div>
      </div>

      <div class="card card-body">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-slate-800">Leave Types</h2>
            <p class="hint-text">High-demand categories, last 90 days.</p>
          </div>
          <a class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500 whitespace-nowrap" href="<?= BASE_URL ?>/modules/leave/admin">Reports</a>
        </div>
        <?php
          try {
            $rows = $pdo->query("SELECT leave_type, COUNT(*) c FROM leave_requests WHERE created_at >= (CURRENT_DATE - INTERVAL '90 days') GROUP BY leave_type")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $rows = []; }
          $ltLabels = array_column($rows, 'leave_type');
          $ltCounts = array_map('intval', array_column($rows, 'c'));
        ?>
        <div class="mt-3" style="position:relative; width:100%; height:160px;">
          <canvas id="chartLeavesType"
            data-chart="pie"
            data-labels='<?= json_encode($ltLabels) ?>'
            data-datasets='<?= json_encode([["label"=>"Requests","data"=>$ltCounts,"backgroundColor"=>["#93c5fd","#86efac","#fca5a5","#fde68a","#c4b5fd"]]]) ?>'
          ></canvas>
        </div>
      </div>
      <?php endif; ?>

    </section>
    <?php endif; ?>
      </div>
    </section>
    <?php $dashboardCards['secChartsTrends'] = ob_get_clean(); endif; ?>

    <?php ob_start(); ?>
    <!-- ===================== ACTION CENTER (below all charts) ===================== -->
    <section class="rounded-xl border border-slate-100 bg-white shadow-sm">
      <button type="button" class="section-collapsible-header p-6" data-section-toggle="secActionCenter" aria-expanded="false">
        <div>
          <h2 class="text-base font-semibold text-slate-800 text-left">Quick Actions</h2>
          <p class="hint-text">Shortcuts to your most-used workflows and pending items.</p>
        </div>
        <svg class="section-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
      </button>
      <div id="secActionCenter" class="section-collapsible-body is-collapsed px-5 pb-5">
      <?php
      $qaIconPeople = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
      $qaIconCalendar = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>';
      $qaIconCheck = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      $qaIconWallet = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>';
      $qaIconBolt = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
      $qaIconClock = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      $qaIconDoc = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
      $qaIconUser = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
      $qaIconBell = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
      $qaIconMegaphone = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 000-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>';
      $qaIconBriefcase = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7h-3V6a3 3 0 00-3-3h-4a3 3 0 00-3 3v1H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2zM8 6a1 1 0 011-1h4a1 1 0 011 1v1H8V6z"/></svg>';
      $qaIconBox = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>';
      $qaIconGear = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';

      // Tailwind's build scans this file's raw TEXT for complete class names —
      // it doesn't execute PHP. Interpolating a color variable into a class
      // name would never appear as a literal string anywhere, so those
      // utilities would silently be missing from the compiled CSS. Every
      // combination actually needed is spelled out literally here instead,
      // keyed by color name.
      $qaColorClasses = [
          'indigo'  => ['icon' => 'bg-indigo-100 text-indigo-600',   'hover' => 'hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700',   'badge' => 'text-indigo-500'],
          'amber'   => ['icon' => 'bg-amber-100 text-amber-600',     'hover' => 'hover:border-amber-200 hover:bg-amber-50 hover:text-amber-700',     'badge' => 'text-amber-500'],
          'emerald' => ['icon' => 'bg-emerald-100 text-emerald-600', 'hover' => 'hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700', 'badge' => 'text-emerald-600'],
          'sky'     => ['icon' => 'bg-sky-100 text-sky-600',         'hover' => 'hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700',           'badge' => 'text-sky-600'],
          'teal'    => ['icon' => 'bg-teal-100 text-teal-600',       'hover' => 'hover:border-teal-200 hover:bg-teal-50 hover:text-teal-700',         'badge' => 'text-teal-500'],
          'purple'  => ['icon' => 'bg-purple-100 text-purple-600',   'hover' => 'hover:border-purple-200 hover:bg-purple-50 hover:text-purple-700',   'badge' => 'text-purple-500'],
          'rose'    => ['icon' => 'bg-rose-100 text-rose-600',       'hover' => 'hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700',         'badge' => 'text-rose-500'],
          'yellow'  => ['icon' => 'bg-yellow-100 text-yellow-600',   'hover' => 'hover:border-yellow-200 hover:bg-yellow-50 hover:text-yellow-700',   'badge' => 'text-yellow-500'],
          'blue'    => ['icon' => 'bg-blue-100 text-blue-600',       'hover' => 'hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700',         'badge' => 'text-blue-500'],
          'green'   => ['icon' => 'bg-green-100 text-green-600',     'hover' => 'hover:border-green-200 hover:bg-green-50 hover:text-green-700',       'badge' => 'text-green-500'],
          'red'     => ['icon' => 'bg-red-100 text-red-600',         'hover' => 'hover:border-red-200 hover:bg-red-50 hover:text-red-700',           'badge' => 'text-red-500'],
      ];

      $quickActionCatalog = [
          'employees' => [
              'label' => 'Employees', 'subtitle' => $totalEmployees . ' active employees',
              'href' => BASE_URL . '/modules/employees/index', 'color' => 'indigo', 'icon' => $qaIconPeople,
              'badge' => '→', 'available' => $canManageEmployees,
          ],
          'pending_requests' => [
              'label' => 'Pending Requests', 'subtitle' => $activeLeaves . ' leave, ' . $pendingOT . ' overtime',
              'href' => BASE_URL . '/modules/leave/admin?status=pending', 'color' => 'amber', 'icon' => $qaIconCalendar,
              'badge' => $totalPendingRequests, 'available' => $canApproveLeaves,
          ],
          'present_today' => [
              'label' => 'Present Today', 'subtitle' => 'Live attendance count',
              'href' => BASE_URL . '/modules/attendance/index?from=' . urlencode(date('Y-m-d')) . '&to=' . urlencode(date('Y-m-d')),
              'color' => 'emerald', 'icon' => $qaIconCheck, 'badge' => $presentToday, 'available' => $canViewAttendance,
          ],
          'payroll_releases' => [
              'label' => 'Payroll Releases', 'subtitle' => 'Released today',
              'href' => BASE_URL . '/modules/payroll/index', 'color' => 'sky', 'icon' => $qaIconWallet,
              'badge' => $payrollReleased, 'available' => $canViewPayroll,
          ],
          'run_payroll' => [
              'label' => 'Run Payroll Cycle', 'subtitle' => 'Start new payroll run',
              'href' => BASE_URL . '/modules/payroll/index', 'color' => 'indigo', 'icon' => $qaIconBolt,
              'badge' => '→', 'available' => $canManagePayroll,
          ],
          'record_attendance' => [
              'label' => 'Record Attendance', 'subtitle' => 'Import or add entries',
              'href' => BASE_URL . '/modules/attendance/index', 'color' => 'teal', 'icon' => $qaIconClock,
              'badge' => '→', 'available' => $canManageAttendance,
          ],
          'file_leave' => [
              'label' => 'File Leave Request', 'subtitle' => 'Submit a new leave form',
              'href' => BASE_URL . '/modules/leave/create', 'color' => 'purple', 'icon' => $qaIconCalendar,
              'badge' => '→', 'available' => true,
          ],
          'my_payslips' => [
              'label' => 'My Payslips', 'subtitle' => 'View your pay history',
              'href' => BASE_URL . '/modules/payroll/my_payslips', 'color' => 'sky', 'icon' => $qaIconDoc,
              'badge' => '→', 'available' => true,
          ],
          'my_profile' => [
              'label' => 'My Profile', 'subtitle' => 'Update your account details',
              'href' => BASE_URL . '/modules/account/index', 'color' => 'rose', 'icon' => $qaIconUser,
              'badge' => '→', 'available' => true,
          ],
          'notifications' => [
              'label' => 'Notification Center', 'subtitle' => 'View all notifications',
              'href' => BASE_URL . '/modules/notifications/index', 'color' => 'yellow', 'icon' => $qaIconBell,
              'badge' => '→', 'available' => true,
          ],
          'memos' => [
              'label' => 'Company Memos', 'subtitle' => 'Read latest announcements',
              'href' => BASE_URL . '/modules/documents/memo.php', 'color' => 'blue', 'icon' => $qaIconMegaphone,
              'badge' => '→', 'available' => true,
          ],
          'recruitment' => [
              'label' => 'Recruitment Pipeline', 'subtitle' => 'Track open positions',
              'href' => BASE_URL . '/modules/recruitment/index', 'color' => 'green', 'icon' => $qaIconBriefcase,
              'badge' => '→', 'available' => $canManageRecruitment,
          ],
          'inventory' => [
              'label' => 'Inventory & Stock', 'subtitle' => 'Manage stock & supplies',
              'href' => BASE_URL . '/modules/inventory/inventory', 'color' => 'red', 'icon' => $qaIconBox,
              'badge' => '→', 'available' => $canViewInventory,
          ],
          'admin_tools' => [
              'label' => 'System Admin', 'subtitle' => 'Configure system options',
              'href' => BASE_URL . '/modules/admin/index', 'color' => 'indigo', 'icon' => $qaIconGear,
              'badge' => '→', 'available' => $canManageSystem,
          ],
      ];

      $defaultQuickActionKeys = ['employees', 'pending_requests', 'present_today', 'payroll_releases', 'run_payroll', 'record_attendance'];

      $selectedQuickActionKeys = [];
      $sourceKeys = $savedQuickActionKeys !== null ? $savedQuickActionKeys : $defaultQuickActionKeys;
      foreach ($sourceKeys as $qaKey) {
          if (isset($quickActionCatalog[$qaKey]) && $quickActionCatalog[$qaKey]['available'] && !in_array($qaKey, $selectedQuickActionKeys, true)) {
              $selectedQuickActionKeys[] = $qaKey;
          }
      }

      $availableUnselectedKeys = [];
      foreach ($quickActionCatalog as $qaKey => $qaItem) {
          if ($qaItem['available'] && !in_array($qaKey, $selectedQuickActionKeys, true)) {
              $availableUnselectedKeys[] = $qaKey;
          }
      }
      ?>
      <div id="quickActionsGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
        <?php foreach ($selectedQuickActionKeys as $qaKey): $qa = $quickActionCatalog[$qaKey]; $qc = $qaColorClasses[$qa['color']]; ?>
        <div class="dashboard-qa-tile flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 transition <?= $qc['hover'] ?>" data-qa-key="<?= htmlspecialchars($qaKey) ?>">
          <a href="<?= htmlspecialchars($qa['href']) ?>" class="dashboard-qa-tile-link" aria-label="<?= htmlspecialchars($qa['label']) ?>"></a>
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?= $qc['icon'] ?> flex-shrink-0"><?= $qa['icon'] ?></span>
          <div class="min-w-0">
            <span class="block font-medium truncate"><?= htmlspecialchars($qa['label']) ?></span>
            <span class="text-xs text-slate-500 truncate"><?= htmlspecialchars((string)$qa['subtitle']) ?></span>
          </div>
          <span class="ml-auto <?= $qc['badge'] ?> font-bold flex-shrink-0"><?= htmlspecialchars((string)$qa['badge']) ?></span>
          <button type="button" class="dashboard-qa-remove" data-remove-action="<?= htmlspecialchars($qaKey) ?>" title="Remove from Quick Actions" aria-label="Remove from Quick Actions">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
          </button>
        </div>
        <?php endforeach; ?>

        <button type="button" id="btnAddQuickAction" class="flex min-h-[4.5rem] flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-slate-200 px-4 py-3 text-sm font-medium text-slate-400 transition hover:border-indigo-300 hover:text-indigo-500 hover:bg-indigo-50/50" aria-expanded="false" aria-controls="quickActionPicker">
          <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/></svg>
          <span>Add Shortcut</span>
        </button>
      </div>
      <div id="quickActionPicker" class="dashboard-qa-picker-inline hidden">
        <?php if ($availableUnselectedKeys): ?>
          <?php foreach ($availableUnselectedKeys as $qaKey): $qa = $quickActionCatalog[$qaKey]; $qc = $qaColorClasses[$qa['color']]; ?>
          <button type="button" class="dashboard-qa-picker-item" data-add-action="<?= htmlspecialchars($qaKey) ?>">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md <?= $qc['icon'] ?> flex-shrink-0"><?= $qa['icon'] ?></span>
            <span class="truncate"><?= htmlspecialchars($qa['label']) ?></span>
          </button>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="dashboard-qa-picker-empty">All available shortcuts are already added.</p>
        <?php endif; ?>
      </div>
      </div>
    </section>
    <?php $dashboardCards['secActionCenter'] = ob_get_clean(); ?>

    <?php ob_start(); ?>
    <!-- ===================== RECENT NOTIFICATIONS (bottom) ===================== -->
    <section class="rounded-xl border border-slate-100 bg-white shadow-sm">
      <button type="button" class="section-collapsible-header p-6" data-section-toggle="secNotifications" aria-expanded="false">
        <div>
          <h2 class="text-base font-semibold text-slate-800 text-left">Recent Notifications</h2>
          <p class="hint-text">Latest announcements for your team.</p>
        </div>
        <svg class="section-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
      </button>
      <div id="secNotifications" class="section-collapsible-body is-collapsed px-5 pb-5">
      <div class="flex justify-end mb-2">
        <a class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:text-indigo-500" href="<?= BASE_URL ?>/modules/notifications/index">Notification center</a>
      </div>
      <?php
        $uid = $_SESSION['user']['id'];
        try {
          $stmt = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id IS NULL OR user_id = :uid ORDER BY id DESC LIMIT 6');
          $stmt->execute([':uid'=>$uid]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
      ?>
      <ol class="mt-4 space-y-3 text-sm text-slate-700">
        <?php foreach ($rows as $n): ?>
          <li class="flex items-start gap-3 rounded-lg border border-slate-100 p-3">
            <span class="mt-0.5 inline-flex h-2.5 w-2.5 flex-shrink-0 rounded-full bg-indigo-500"></span>
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= htmlspecialchars(date('M d, Y • h:i A', strtotime($n['created_at'] ?? 'now'))) ?></p>
              <p class="mt-1 text-sm text-slate-800"><?= htmlspecialchars($n['message']) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <li class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">No notifications yet.</li>
        <?php endif; ?>
      </ol>
      </div>
    </section>
    <?php $dashboardCards['secNotifications'] = ob_get_clean(); ?>

    <?php
    $renderOrder = [];
    if ($savedCardOrder) {
        foreach ($savedCardOrder as $cardId) {
            if (isset($dashboardCards[$cardId]) && !in_array($cardId, $renderOrder, true)) {
                $renderOrder[] = $cardId;
            }
        }
    }
    foreach ($defaultCardOrder as $cardId) {
        if (isset($dashboardCards[$cardId]) && !in_array($cardId, $renderOrder, true)) {
            $renderOrder[] = $cardId;
        }
    }
    ?>
    <div id="dashboardCardList" class="space-y-6" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
      <?php foreach ($renderOrder as $cardId): ?>
      <div class="dashboard-card-draggable" data-card-id="<?= htmlspecialchars($cardId) ?>">
        <button type="button" class="dashboard-card-handle" title="Drag to reorder" aria-label="Drag to reorder">
          <span class="dashboard-card-handle-grip" aria-hidden="true">
            <span></span><span></span>
            <span></span><span></span>
            <span></span><span></span>
          </span>
        </button>
        <?= $dashboardCards[$cardId] ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
}

require_once __DIR__ . '/includes/footer.php';
