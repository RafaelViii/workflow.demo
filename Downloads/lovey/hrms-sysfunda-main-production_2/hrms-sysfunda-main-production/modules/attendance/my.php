<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);

// Resolve employee linked to this user
$emp = null;
try {
    $st = $pdo->prepare('SELECT id, employee_code, first_name, last_name FROM employees WHERE user_id = :uid LIMIT 1');
    $st->execute([':uid' => $uid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $emp = null; }

$pageTitle = 'My Attendance';
require_once __DIR__ . '/../../includes/header.php';
if (!$emp) {
    echo '<div class="card p-4 max-w-xl">';
    show_human_error('Your account is not linked to an employee profile.');
    echo '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if (!function_exists('attendance_format_time12')) {
  function attendance_format_time12($value): string {
    if ($value === null) { return ''; }
    $raw = trim((string)$value);
    if ($raw === '') { return ''; }
    $raw = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $raw);
    $dt = DateTime::createFromFormat('H:i:s', $raw) ?: DateTime::createFromFormat('H:i', $raw);
    return $dt instanceof DateTime ? $dt->format('h:i A') : $raw;
  }
}
if (!function_exists('format_overtime_display')) {
  function format_overtime_display(int $minutes): string {
    if ($minutes <= 0) { return '—'; }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) { return "{$hours}hr {$mins}min"; }
    return $hours > 0 ? "{$hours}hr" : "{$mins}min";
  }
}

// Filters
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$params = [':eid' => (int)$emp['id']];
$where = 'a.employee_id = :eid';
if ($from) { $where .= ' AND a.date >= :from'; $params[':from'] = $from; }
if ($to)   { $where .= ' AND a.date <= :to';   $params[':to'] = $to; }

$rows = [];
try {
    $sql = "SELECT a.date, a.time_in, a.time_out, a.overtime_minutes, a.status
            FROM attendance a
            WHERE $where
            ORDER BY a.date DESC, a.id DESC
            LIMIT 200";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }
?>
<div class="space-y-5">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h1 class="text-xl font-semibold">My Attendance</h1>
      <p class="hint-text">Your daily time in/out and overtime, most recent first.</p>
    </div>
    <form class="flex flex-wrap items-center gap-2" method="get">
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input-text">
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input-text">
      <button class="btn btn-icon" title="Filter" aria-label="Filter">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M6 8h12M9 12h6M11 16h2"/></svg>
      </button>
    </form>
  </div>

  <?php render_page_guide('secGuideAttendanceMy', '
    <p>This page is a read-only view of your own attendance history — it does not let you clock in or out.</p>
    <ol class="list-decimal list-inside space-y-1">
      <li>Use the <strong>From</strong> and <strong>To</strong> date fields above the table to narrow the list to a specific period, then click the filter button.</li>
      <li>Each row shows one day: your recorded time in, time out, any overtime minutes, and a status badge.</li>
      <li>Status colors: <strong>green</strong> = present, <strong>amber</strong> = late, <strong>red</strong> = absent, <strong>blue</strong> = on-leave, <strong>purple</strong> = holiday.</li>
    </ol>
    <p>Spot an error in your recorded time in/out? File a correction from <strong>Documents &amp; Comms → Data Corrections</strong> instead of trying to edit it here.</p>
  ') ?>

  <div class="card card-body p-0">
    <div class="overflow-x-auto">
      <table class="table-basic w-full">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Overtime</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $status = strtolower((string)($r['status'] ?? ''));
            $statusBadgeClass = 'bg-slate-100 text-slate-700';
            if ($status === 'present') $statusBadgeClass = 'bg-emerald-100 text-emerald-700';
            elseif ($status === 'late') $statusBadgeClass = 'bg-amber-100 text-amber-700';
            elseif ($status === 'absent') $statusBadgeClass = 'bg-red-100 text-red-700';
            elseif ($status === 'on-leave') $statusBadgeClass = 'bg-blue-100 text-blue-700';
            elseif ($status === 'holiday') $statusBadgeClass = 'bg-purple-100 text-purple-700';
          ?>
          <tr class="hover:bg-slate-50">
            <td class="whitespace-nowrap"><?= htmlspecialchars(date('M d, Y', strtotime($r['date']))) ?></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars(attendance_format_time12($r['time_in'] ?? null)) ?: '<span class="text-slate-400">—</span>' ?></td>
            <td class="whitespace-nowrap"><?= htmlspecialchars(attendance_format_time12($r['time_out'] ?? null)) ?: '<span class="text-slate-400">—</span>' ?></td>
            <td class="text-indigo-600 font-medium"><?= format_overtime_display((int)($r['overtime_minutes'] ?? 0)) ?></td>
            <td>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $statusBadgeClass ?>"><?= htmlspecialchars(ucfirst($status ?: 'Unknown')) ?></span>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr>
            <td colspan="5">
              <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7l2 2 4-4"/></svg>
                <p class="empty-state-title">No attendance records yet</p>
                <p class="empty-state-desc"><?= ($from || $to) ? 'Try a different date range above.' : 'Your time in/out history will appear here.' ?></p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php';
