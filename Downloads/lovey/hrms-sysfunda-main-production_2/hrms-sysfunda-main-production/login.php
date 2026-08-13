<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/db.php';

// Prevent auth credentials from being passed via GET query string (GET-for-POST mitigation)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['email']) || isset($_GET['password']))) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login', true, 302);
    exit;
}

auth_attempt_remember_login();
if (!empty($_SESSION['user'])) {
  require_once __DIR__ . '/includes/config.php';
  header('Location: ' . BASE_URL . '/');
  exit;
}

$pdo = get_db_conn();
$defaultBranchId = branches_get_default_id($pdo);

// One-time bootstrap: ensure superadmin account exists and is active
try {
  $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  $needAdmin = false;
  if ($total === 0) {
    $needAdmin = true;
  } else {
    // Only check if superadmin exists — do NOT auto-reactivate deactivated accounts
    $chk = $pdo->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1');
    $chk->execute([':email' => SUPERADMIN_EMAIL]);
    $u = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      $needAdmin = true;
    }
  }
  if ($needAdmin) {
    $hash = password_hash(SUPERADMIN_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, status, is_system_admin, branch_id) VALUES (:email,:hash,:name,:role,'active',true,:branch)");
    $ins->execute([
      ':email' => SUPERADMIN_EMAIL,
      ':hash' => $hash,
      ':name' => 'System Admin',
      ':role' => 'admin',
      ':branch' => $defaultBranchId,
    ]);
  }
} catch (Throwable $e) {
  // Silently ignore bootstrap errors on login page
}

$error = '';
$emailInput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'This page took too long to load. Please try signing in again.';
  } else {
    $now = time();
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $emailInput = $email;
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Use first IP if forwarded contains multiple
    if (str_contains($clientIp, ',')) { $clientIp = trim(explode(',', $clientIp)[0]); }

    // --- DB-backed rate limiting (immune to session clearing) ---
    $rateLimitKey = $clientIp; // rate limit by IP
    $maxAttempts = 5;
    $lockoutSeconds = 120; // 2-minute lockout after 5 failures
    $windowSeconds = 900;  // 15-minute sliding window

    // Ensure table exists (idempotent)
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip_address VARCHAR(45) NOT NULL, email VARCHAR(255) NOT NULL DEFAULT '',
        attempts INTEGER NOT NULL DEFAULT 1,
        first_attempt_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
        blocked_until TIMESTAMP WITHOUT TIME ZONE, PRIMARY KEY (ip_address, email))");
    } catch (Throwable $e) { /* table likely already exists */ }

    // Clean stale entries (older than window)
    try {
      $pdo->prepare("DELETE FROM login_rate_limits WHERE first_attempt_at < NOW() - INTERVAL '" . (int)$windowSeconds . " seconds'")->execute();
    } catch (Throwable $e) { /* non-critical */ }

    // Check current rate limit status for this IP
    $blocked = false;
    try {
      $rlStmt = $pdo->prepare("SELECT attempts, blocked_until FROM login_rate_limits WHERE ip_address = :ip AND email = ''");
      $rlStmt->execute([':ip' => $rateLimitKey]);
      $rl = $rlStmt->fetch(PDO::FETCH_ASSOC);
      if ($rl && $rl['blocked_until'] && strtotime($rl['blocked_until']) > $now) {
        $remaining = strtotime($rl['blocked_until']) - $now;
        $error = 'Too many login attempts. Please try again in ' . $remaining . ' seconds.';
        $blocked = true;
      }
    } catch (Throwable $e) { /* if table doesn't exist yet, allow login */ }

    if (!$blocked) {
      if (auth_login($email, $password, false)) {
        // Clear rate limit on success
        try {
          $pdo->prepare("DELETE FROM login_rate_limits WHERE ip_address = :ip")->execute([':ip' => $rateLimitKey]);
        } catch (Throwable $e) { /* non-critical */ }
        require_once __DIR__ . '/includes/config.php';
        header('Location: ' . BASE_URL . '/');
        exit;
      }

      // Record failed attempt
      try {
        $pdo->prepare("INSERT INTO login_rate_limits (ip_address, email, attempts, first_attempt_at)
          VALUES (:ip, '', 1, NOW())
          ON CONFLICT (ip_address, email) DO UPDATE SET attempts = login_rate_limits.attempts + 1")
          ->execute([':ip' => $rateLimitKey]);

        // Check if lockout threshold reached
        $chkStmt = $pdo->prepare("SELECT attempts FROM login_rate_limits WHERE ip_address = :ip AND email = ''");
        $chkStmt->execute([':ip' => $rateLimitKey]);
        $currentAttempts = (int)($chkStmt->fetchColumn() ?: 0);
        if ($currentAttempts >= $maxAttempts) {
          $pdo->prepare("UPDATE login_rate_limits SET blocked_until = NOW() + INTERVAL '" . (int)$lockoutSeconds . " seconds' WHERE ip_address = :ip AND email = ''")
            ->execute([':ip' => $rateLimitKey]);
        }
      } catch (Throwable $e) { /* non-critical: fail open on DB errors */ }

      $error = 'Invalid email or password.';
      audit('login.failed', 'Failed login for ' . $email);
    }
  }
}

$infoMessage = flash_get('info');
$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script>
    // Same anti-flash pattern as the app shell — apply saved/OS theme before
    // first paint, so a user who set dark mode inside the app doesn't get a
    // blinding light-mode flash the moment they're signed out to this page.
    (function () {
      try {
        var saved = localStorage.getItem('theme');
        var wantsDark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (wantsDark) document.documentElement.classList.add('dark');
      } catch (e) {}
    })();
  </script>
  <title>Sign in &middot; WorkFlow HRMS</title>
  <link rel="icon" type="image/jpeg" href="assets/resources/logo.jpg" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&family=Nunito:wght@700;800&display=swap" rel="stylesheet" crossorigin="anonymous">
  <?php
    $tailwindCssVer = @filemtime(__DIR__ . '/assets/css/tailwind.css') ?: time();
    $appCssVer = @filemtime(__DIR__ . '/assets/css/app.css') ?: time();
  ?>
  <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= $tailwindCssVer ?>" />
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $appCssVer ?>" />
</head>
<body class="min-h-screen font-sans antialiased" style="background: var(--sidebar-bg);">
  <button type="button" id="btnThemeToggle" class="btn btn-outline btn-icon fixed top-4 right-4 z-10" title="Toggle dark mode" aria-label="Toggle dark mode">
    <svg id="themeIconLight" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
    <svg id="themeIconDark" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
  </button>

  <!-- The two panels stay full-height and borderless/seamless (body and
       panels share the exact same --sidebar-bg, so there's nothing to see
       at the edges), but width is capped and centered via min(vw, px) —
       the same pattern already used for height elsewhere on this page.
       Without a cap, a proportional 42%/58% split keeps growing on wider
       viewports (e.g. zooming out enlarges the effective viewport) while
       the actual text/form inside doesn't stretch to match, so the dead
       space between them balloons outward. Capping width stops that
       without reintroducing a visible "card" frame, since the color never
       changes across the boundary. -->
  <div class="flex justify-center min-h-screen">
    <div class="flex w-full" style="max-width: min(92vw, 1900px);">
    <!-- Brand panel — same token (--sidebar-bg) as the app's actual sidebar,
         so it's white in light mode / near-black in dark mode, matching
         rather than inventing a separate look. -->
    <div class="hidden lg:flex lg:w-[42%] flex-col p-12 relative" style="background: var(--sidebar-bg); border-right: 1px solid var(--sidebar-border);">
      <!-- Taken out of flow (absolute, offset by top/left: 3rem to match
           the panel's own p-12 padding — an absolutely positioned element's
           containing block is the padding BOX edge, not the content edge,
           so top:0/left:0 would sit 3rem too far up-left, misaligned with
           the heading below) so the heading below isn't sharing this
           panel's height with it. Previously the logo
           was an in-flow sibling, so the heading only centered within the
           LEFTOVER space below it (H - logoHeight), while the right panel
           centers within the FULL height (H). As the full-bleed panel grows
           taller (e.g. zooming out enlarges the effective viewport), those
           two different centering math produce different vertical
           positions and the two sides visibly drift apart. Making the logo
           absolute means the heading block below centers over the SAME
           full height H as the right panel, so they always land in sync
           regardless of viewport size. -->
      <div class="sidebar-brand" style="position: absolute; top: 3rem; left: 3rem; padding-left: 0; padding-right: 0;">
        <div class="flex items-center gap-3">
          <div class="brand-icon" role="img" aria-label="WorkFlow HRMS" style="width: 4.5rem; height: 4.5rem;"><?php readfile(__DIR__ . '/assets/resources/work-flow.svg'); ?></div>
          <div>
            <div class="brand-text" style="font-size: 1.5rem;">Work Flow</div>
            <div class="brand-sub" style="font-size: .75rem;">HR Management System</div>
          </div>
        </div>
      </div>

      <div class="flex flex-1 flex-col justify-center overflow-y-auto">
        <h1 class="text-4xl font-semibold leading-tight" style="color: var(--text-primary);">Manage your workforce in one place.</h1>
        <p class="mt-3 text-sm" style="color: var(--text-secondary);">Employees, payroll, attendance, leave, recruitment, and multi-branch operations — all in one secure workspace.</p>
        <ul class="mt-8 space-y-4">
          <li class="flex items-start gap-3">
            <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full" style="background: var(--accent);"></span>
            <span class="text-sm" style="color: var(--text-secondary);">Run payroll end-to-end — batches, approvals, statutory computations, and payslips.</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full" style="background: var(--accent);"></span>
            <span class="text-sm" style="color: var(--text-secondary);">Track attendance and DTR, file and approve leave requests, and monitor balances in real time.</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full" style="background: var(--accent);"></span>
            <span class="text-sm" style="color: var(--text-secondary);">Manage multiple branches, each with its own payroll batches and settings.</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full" style="background: var(--accent);"></span>
            <span class="text-sm" style="color: var(--text-secondary);">Run recruitment pipelines and performance reviews alongside role-based access and a full audit trail.</span>
          </li>
        </ul>
      </div>
    </div>

    <!-- Sign-in panel — pinned to the same --sidebar-bg token as the brand
         panel (not the page background) so the two halves are always the
         exact same color with no visible seam, in both themes. -->
    <div class="flex flex-1 items-center justify-center overflow-y-auto p-6 sm:p-12" style="background: var(--sidebar-bg);">
      <div class="w-full max-w-sm">
        <div class="mb-8 lg:hidden">
          <div class="sidebar-brand" style="padding: 0;">
            <div class="brand-icon" role="img" aria-label="WorkFlow HRMS"><?php readfile(__DIR__ . '/assets/resources/work-flow.svg'); ?></div>
            <div class="brand-text">Work Flow</div>
          </div>
        </div>

        <p class="hint-text uppercase tracking-widest text-[11px]">Welcome back</p>
        <h2 class="mt-1 text-2xl font-semibold" style="color: var(--text-primary);">Sign in</h2>
        <p class="hint-text mt-1">Use your work email and password to continue.</p>

        <?php if ($infoMessage): ?>
          <div class="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700 dark:border-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300">
            <?= htmlspecialchars($infoMessage) ?>
          </div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>
        <div id="clientError" class="mt-5 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300"></div>

        <form id="loginForm" method="post" class="mt-6 space-y-4" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>" />
          <div class="space-y-1.5">
            <label for="email" class="text-sm font-medium" style="color: var(--text-secondary);">Work email</label>
            <input id="email" name="email" type="email" value="<?= htmlspecialchars($emailInput) ?>" class="input-text w-full" placeholder="you@company.com" required autocomplete="email" />
          </div>
          <div class="space-y-1.5">
            <label for="password" class="text-sm font-medium" style="color: var(--text-secondary);">Password</label>
            <div class="relative">
              <input id="password" name="password" type="password" class="input-text w-full pr-12" placeholder="Enter your password" required autocomplete="current-password" />
              <button type="button" class="absolute inset-y-0 right-3 flex items-center justify-center" style="color: var(--text-muted);" data-toggle-password aria-label="Toggle password visibility" aria-pressed="false">
                <span class="sr-only">Toggle password visibility</span>
                <svg data-icon="show" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.436 0 .643C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <svg data-icon="hide" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c1.712 0 3.332-.356 4.786-1.004M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.5a10.523 10.523 0 01-4.293 5.223M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88" />
                </svg>
              </button>
            </div>
          </div>
          <div class="flex justify-end">
            <button type="button" data-open-forgot class="text-sm font-medium" style="color: var(--accent);">Forgot password?</button>
          </div>
          <button type="submit" class="btn btn-primary w-full justify-center">Sign in</button>
        </form>

        <p class="mt-8 text-center hint-text">&copy; <?= date('Y') ?> WorkFlow HRMS</p>
      </div>
    </div>
    </div>
  </div>

  <div id="forgotModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0" style="background: rgba(0,0,0,.5);" data-forgot-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="card w-full max-w-md p-6 text-center">
        <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Need a password reset?</h3>
        <p class="mt-3 text-sm" style="color: var(--text-secondary);">For security reasons, password resets are handled by your system administrator. Please reach out to them to restore your account access.</p>
        <button type="button" class="btn btn-primary mt-6" data-forgot-close>Got it</button>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // Same toggle logic as the app shell's #btnThemeToggle (this page doesn't
    // load app.js, so it's duplicated here rather than pulling in the whole bundle).
    const themeBtn = document.getElementById('btnThemeToggle');
    themeBtn?.addEventListener('click', () => {
      const isDark = document.documentElement.classList.toggle('dark');
      try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) {}
    });

    const passwordInput = document.getElementById('password');
    const toggleBtn = document.querySelector('[data-toggle-password]');
    if (toggleBtn && passwordInput) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isHidden ? 'text' : 'password');
        toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        toggleBtn.querySelectorAll('[data-icon]').forEach((icon) => {
          icon.classList.toggle('hidden', icon.dataset.icon !== (isHidden ? 'hide' : 'show'));
        });
      });
    }

    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const clientError = document.getElementById('clientError');
    const clearClientError = () => {
      if (!clientError) return;
      clientError.classList.add('hidden');
      clientError.textContent = '';
      emailInput?.classList.remove('input-error');
      passwordInput?.classList.remove('input-error');
    };
    emailInput?.addEventListener('input', clearClientError);
    passwordInput?.addEventListener('input', clearClientError);

    form?.addEventListener('submit', (event) => {
      clearClientError();
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        return;
      }
      const emailVal = emailInput.value.trim();
      const passwordVal = passwordInput.value.trim();
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailPattern.test(emailVal)) {
        event.preventDefault();
        clientError.textContent = 'Enter a valid work email address.';
        clientError.classList.remove('hidden');
        emailInput.classList.add('input-error');
        emailInput.focus();
        return;
      }
      if (passwordVal.length < 6) {
        event.preventDefault();
        clientError.textContent = 'Password must be at least 6 characters long.';
        clientError.classList.remove('hidden');
        passwordInput.classList.add('input-error');
        passwordInput.focus();
      }
    });

    const forgotModal = document.getElementById('forgotModal');
    const openForgot = document.querySelector('[data-open-forgot]');
    const closeForgot = () => {
      if (!forgotModal) return;
      forgotModal.classList.add('hidden');
      forgotModal.classList.remove('flex');
    };
    openForgot?.addEventListener('click', (event) => {
      event.preventDefault();
      if (!forgotModal) return;
      forgotModal.classList.remove('hidden');
      forgotModal.classList.add('flex');
    });
    forgotModal?.addEventListener('click', (event) => {
      if (event.target.closest('[data-forgot-close]')) {
        closeForgot();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeForgot();
      }
    });
  });
  </script>
</body>
</html>
