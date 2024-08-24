<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$db = (new Database())->getConnection();
$userId = (int)$_SESSION['user_id'];
$restaurantName = $_SESSION['restaurant_name'] ?? 'Restaurant';

// Get or create user credits
$creditStmt = $db->prepare("SELECT * FROM user_credits WHERE user_id = :uid LIMIT 1");
$creditStmt->execute([':uid' => $userId]);
$credits = $creditStmt->fetch(PDO::FETCH_ASSOC);

if (!$credits) {
    // sensible defaults (can pull from env if you’ve wired it)
    $ins = $db->prepare("INSERT INTO user_credits (user_id, credits_limit, credits_used, last_reset, period_days)
                         VALUES (:uid, 100, 0, NOW(), 30)");
    $ins->execute([':uid' => $userId]);

    $creditStmt->execute([':uid' => $userId]);
    $credits = $creditStmt->fetch(PDO::FETCH_ASSOC);
}

// Safety math
$limit = max(1, (int)($credits['credits_limit'] ?? 100)); // avoid /0
$used  = max(0, (int)($credits['credits_used'] ?? 0));
$remaining_credits = max(0, $limit - $used);
$usage_percentage  = min(100, max(0, ($used / $limit) * 100));
$last_reset = $credits['last_reset'] ?? date('Y-m-d');
$period_days = (int)($credits['period_days'] ?? 30);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Credits - Zicbot</title>
  <link rel="icon" href="/icon.png" type="image/x-icon">
    <link rel="stylesheet" href="./assets//css//style.css" />

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    /* Page-specific visual for credits donut */
    .credits-visual {
      background: var(--secondary-dark);
      border-radius: 12px;
      padding: 30px;
      text-align: center;
      margin-bottom: 30px;
    }
    .credits-circle {
      width: 200px; height: 200px; border-radius: 50%;
      background: conic-gradient(var(--accent-orange) 0deg <?php echo ($usage_percentage * 3.6); ?>deg,
                                 var(--border-dark) <?php echo ($usage_percentage * 3.6); ?>deg 360deg);
      display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; position: relative;
    }
    .credits-circle::after {
      content: ''; width: 160px; height: 160px; background: var(--secondary-dark); border-radius: 50%; position: absolute;
    }
    .credits-number { font-size: 3rem; font-weight: 700; color: var(--accent-orange); z-index: 1; position: relative; }

    .credits-info {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px; margin-top: 20px;
    }
    .info-item { text-align: center; padding: 20px; background: var(--primary-dark); border-radius: 8px; }
    .info-value { font-size: 2rem; font-weight: 700; color: var(--accent-orange); margin-bottom: 5px; }
    .info-label { color: var(--text-secondary); font-size: .9rem; }

    /* Hamburger (matches your dashboard pattern) */
    .hamburger {
      display: none; background: transparent; border: 0; color: var(--text, #fff);
      font-size: 1.25rem; padding: 8px; margin-right: 10px; cursor: pointer;
    }
    @media (max-width: 900px) {
      .hamburger { display: inline-flex; align-items: center; justify-content: center; }
    }
    .mobile-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.45);
      opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 90;
    }
    .mobile-overlay.active { opacity: 1; pointer-events: auto; }
    .sidebar.mobile-open { transform: translateX(0); } /* your CSS should have default off-canvas on mobile */
  </style>
</head>
<body>
  <!-- Overlay for mobile sidebar -->
  <div class="mobile-overlay" id="mobileOverlay"></div>

  <div class="dashboard">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <img src="./assets//images/zicbot_logo.svg" alt="Zicbot">
        </div>
        <div class="sidebar-subtitle"><?php echo htmlspecialchars($restaurantName); ?></div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="memberships.php" class="nav-link"><i class="fas fa-crown"></i> Memberships</a></li>
          <li class="nav-item"><a href="billing.php" class="nav-link "><i class="fas fa-credit-card"></i> Billing</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link active"><i class="fas fa-coins"></i> Credits</a></li>
          <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
          <li class="nav-item"><a href="#" class="nav-link" onclick="contactSupport()"><i class="fas fa-question-circle"></i> Help</a></li>
          <li class="nav-item" style="margin-top:20px;"><a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <!-- Hamburger for mobile -->
        <button class="hamburger" id="hamburgerBtn" onclick="toggleMobileMenu()">
          <i class="fas fa-bars"></i>
        </button>

        <h1 class="page-title">Message Credits</h1>
        <div class="header-actions">
          <button class="btn btn-primary" onclick="window.location.href='memberships.php'">
            <i class="fas fa-plus"></i> Buy More Credits
          </button>
        </div>
      </div>

      <div class="credits-visual">
        <div class="credits-circle">
          <div class="credits-number"><?php echo number_format($remaining_credits); ?></div>
        </div>
        <h2>Credits Remaining</h2>
        <p style="color: var(--text-secondary); margin-top: 10px;">
          <?php echo number_format($usage_percentage, 1); ?>% of your monthly credits used
        </p>
      </div>

      <div class="credits-info">
        <div class="info-item">
          <div class="info-value"><?php echo number_format($limit); ?></div>
          <div class="info-label">Total Credits</div>
        </div>
        <div class="info-item">
          <div class="info-value"><?php echo number_format($used); ?></div>
          <div class="info-label">Credits Used</div>
        </div>
        <div class="info-item">
          <div class="info-value"><?php echo number_format($remaining_credits); ?></div>
          <div class="info-label">Credits Left</div>
        </div>
        <div class="info-item">
          <div class="info-value">
            <?php echo htmlspecialchars(date('M j', strtotime($last_reset))); ?>
          </div>
          <div class="info-label">Last Reset (every <?php echo (int)$period_days; ?> days)</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 class="card-title">How Credits Work</h2>
        </div>
        <div style="color: var(--text-secondary); line-height: 1.8;">
          <p><strong>What are message credits?</strong></p>
          <p>Message credits are used when your system processes messages. Each message consumes 1 credit.</p>

          <p><strong>Credit Usage (example):</strong></p>
          <ul style="margin-left: 20px;">
            <li>1 Message = 1 Credit</li>
          </ul>

          <p><strong>Credit Renewal:</strong></p>
          <p>Your credits reset on a rolling period (default <?php echo (int)$period_days; ?> days) based on your membership plan. Unused credits do not carry over.</p>

          <?php if ($remaining_credits < 50): ?>
            <div class="alert alert-warning" style="margin-top: 20px;">
              <i class="fas fa-exclamation-triangle"></i>
              <strong>Low Credits Warning:</strong> You have less than 50 credits remaining.
              <a href="memberships.php" style="color: var(--accent-orange);">Upgrade your plan</a> to get more credits.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script>
    function contactSupport() {
      const subject = encodeURIComponent('Support Request from ' + window.location.hostname);
      const body = encodeURIComponent('Hello,\n\nI need help with...\n\nBest regards');
      window.location.href = `mailto:support@zicbot.com?subject=${subject}&body=${body}`;
    }

    function toggleMobileMenu() {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('mobileOverlay');

      sidebar.classList.toggle('mobile-open');
      overlay.classList.toggle('active');

      const open = sidebar.classList.contains('mobile-open');
      document.body.style.overflow = open ? 'hidden' : '';
      document.documentElement.style.overflow = open ? 'hidden' : '';
    }

    // Close menu when clicking overlay
    document.getElementById('mobileOverlay').addEventListener('click', toggleMobileMenu);

    // Close menu on nav click (mobile)
    document.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', function() {
        if (window.innerWidth <= 900) {
          const sidebar = document.getElementById('sidebar');
          const overlay = document.getElementById('mobileOverlay');
          sidebar.classList.remove('mobile-open');
          overlay.classList.remove('active');
          document.body.style.overflow = '';
          document.documentElement.style.overflow = '';
        }
      });
    });

    // Optional: ESC to close
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';
      }
    });
  </script>
</body>
</html>
