<?php
// billing.php
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/env.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
  header("Location: index.php");
  exit();
}

loadEnv(__DIR__ . '/.env');
$db = (new Database())->getConnection();

$userId         = (int)$_SESSION['user_id'];
$restaurantName = $_SESSION['restaurant_name'] ?? 'Restaurant';

/* ---- user + plan context (NO Stripe API calls here) ---- */
$uStmt = $db->prepare("
  SELECT u.id, u.name, u.email, u.stripe_customer_id, u.stripe_subscription_id,
         u.membership_plan, mp.name AS plan_name
  FROM users u
  LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
  WHERE u.id = :id
  LIMIT 1
");
$uStmt->execute([':id' => $userId]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stripeCustomerId     = $user['stripe_customer_id'] ?? null;
$stripeSubscriptionId = $user['stripe_subscription_id'] ?? null;
$planName             = $user['plan_name'] ?? 'Free';

/* ---- payments from DB (no Stripe calls) ---- */
$payStmt = $db->prepare("
  SELECT id, stripe_session_id, stripe_subscription_id, stripe_customer_id,
         invoice_id, payment_intent_id, plan_id, plan_price_id,
         amount, currency, status, created_at
  FROM payments
  WHERE user_id = :uid
  ORDER BY id DESC
  LIMIT 500
");
$payStmt->execute([':uid' => $userId]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---- portal banners ---- */
$portalState   = $_GET['portal'] ?? '';
$bannerSuccess = ($portalState === 'returned');
$bannerError   = ($portalState === 'error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Billing & Payments - Zicbot</title>
  <link rel="stylesheet" href="./assets/css/style.css"/>
  <link rel="icon" href="/icon.png" type="image/x-icon"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    .hamburger { display:none;background:transparent;border:0;color:var(--text,#fff);font-size:1.25rem;padding:8px;margin-right:10px;cursor:pointer; }
    @media (max-width:900px){ .hamburger{display:inline-flex;align-items:center;justify-content:center;} }
    .mobile-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.45); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:90; }
    .mobile-overlay.active{ opacity:1; pointer-events:auto; }
    .sidebar.mobile-open{ transform:translateX(0); }

    .banner-wrap { margin-bottom:14px; position:relative; }
    .celebrate, .oops { position: relative; border-radius:14px; padding:14px 16px; border:1px solid rgba(255,255,255,.06); overflow:hidden; }
    .celebrate { background: linear-gradient(135deg, rgba(255,192,120,.11), rgba(255,120,160,.11)); color:#fde68a; }
    .oops { background: linear-gradient(135deg, rgba(255,120,120,.13), rgba(255,90,90,.09)); color:#fecaca; }
    .celebrate h4, .oops h4 { margin:0 0 6px; color:#fff; }
    .celebrate .mini, .oops .mini { color:#e2e8f0; opacity:.8; }
    .close-x { position:absolute; top:10px; right:12px; color:#fff; opacity:.8; cursor:pointer; }

    /* Single management banner card */
    .manage-banner {
      border-radius:18px;
      padding:22px 20px;
      background: radial-gradient(1200px 500px at 10% -10%, rgba(107,33,255,.20), transparent 60%),
                  radial-gradient(1200px 500px at 110% 110%, rgba(16,185,129,.18), transparent 60%),
                  linear-gradient(135deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
      border:1px solid rgba(255,255,255,.08);
      box-shadow: 0 20px 50px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.03);
      display:flex; flex-direction:column; gap:10px;
    }
    .manage-title { margin:0; font-size:1.35rem; font-weight:800; letter-spacing:.2px; color:#fff; }
    .manage-sub  { margin:0; color:#cbd5e1; max-width:840px; }
    .manage-points{ display:flex; flex-wrap:wrap; gap:10px 18px; margin-top:4px; }
    .manage-chip {
      background: rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.12);
      color:#e2e8f0;
      padding:8px 12px; border-radius:999px; font-size:.92rem; display:inline-flex; align-items:center; gap:8px;
    }
    .btn-portal,.btn-soft{
      border:none; cursor:pointer; font-weight:700; line-height:1;
      display:inline-flex; align-items:center; gap:10px; padding:13px 18px; border-radius:12px;
      transition: transform .06s ease, box-shadow .2s ease, opacity .2s ease;
    }
    .btn-portal{ background:#6b21ff; color:#fff; box-shadow:0 4px 14px rgba(107,33,255,.35), inset 0 0 0 2px rgba(255,255,255,.05); }
    .btn-portal:hover{ box-shadow:0 6px 18px rgba(107,33,255,.45), inset 0 0 0 2px rgba(255,255,255,.08); }
    .btn-portal:active{ transform: translateY(1px) scale(0.99); }
    .btn-portal:disabled{ opacity:.7; cursor:not-allowed; }
    .btn-soft{ background:rgba(255,255,255,.06); color:#e2e8f0; border:1px solid rgba(255,255,255,.12); }
    .btn-soft:hover{ background:rgba(255,255,255,.08); }

    .subtle { color: var(--text-secondary,#94a3b8); font-size:.95rem; }

    /* Payments table */
    table.payments { width:100%; border-collapse: collapse; }
    table.payments th, table.payments td { border-bottom: 1px solid var(--border-dark,#1f2937); padding:10px 8px; text-align:left; font-size:.95rem; }
    table.payments th { color:#cbd5e1; font-weight:600; white-space:nowrap; }
    table.payments td { white-space:nowrap; }
    .status-paid { color:#86efac; }
    .status-failed { color:#fca5a5; }
    .status-pending { color:#fde68a; }
    .copy-btn { border:none;background:rgba(255,255,255,.06); color:#e2e8f0; border:1px solid rgba(255,255,255,.12);
                padding:6px 10px; border-radius:8px; cursor:pointer; }
    .copy-btn:hover { background:rgba(255,255,255,.09); }
    .pager { display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px; }
  </style>
</head>
<body>
  <div class="mobile-overlay" id="mobileOverlay"></div>

  <div class="dashboard">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <img src="./assets/images/zicbot_logo.svg" alt="Zicbot">
        </div>
        <div class="sidebar-subtitle"><?php echo htmlspecialchars($restaurantName); ?></div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="memberships.php" class="nav-link"><i class="fas fa-crown"></i> Memberships</a></li>
          <li class="nav-item"><a href="billing.php" class="nav-link active"><i class="fas fa-credit-card"></i> Billing</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link"><i class="fas fa-coins"></i> Credits</a></li>
          <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
          <li class="nav-item"><a href="#" class="nav-link" onclick="contactSupport()"><i class="fas fa-question-circle"></i> Help</a></li>
          <li class="nav-item" style="margin-top:20px;"><a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <button class="hamburger" onclick="toggleMobileMenu()"><i class="fas fa-bars"></i></button>
        <h1 class="page-title">Billing & Payments</h1>
        <div class="header-actions">
          <?php if ($user['membership_plan']): ?>
            <div class="alert alert-success">Current Plan: <strong><?php echo htmlspecialchars($planName); ?></strong></div>
          <?php else: ?>
            <div class="alert alert-warning">You’re on the <strong>Free Plan</strong></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="banner-wrap">
        <?php if ($bannerSuccess): ?>
          <div class="celebrate">
            <span class="close-x" onclick="this.parentElement.remove()">×</span>
            <h4>All set!</h4>
            <div class="mini">You’ve returned from the billing portal. Your changes will reflect here shortly. 💖</div>
          </div>
        <?php elseif ($bannerError): ?>
          <div class="oops">
            <span class="close-x" onclick="this.parentElement.remove()">×</span>
            <h4>Hmm—something felt off.</h4>
            <div class="mini">We couldn’t open the billing portal. Please try again or contact support.</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ===== Single Billing Management Banner (no Stripe reads on server) ===== -->
      <section class="manage-banner">
        <h2 class="manage-title"><i class="fas fa-shield-halved"></i> Manage your billing in Stripe</h2>
        <p class="manage-sub">
          Update your card, switch plans, download invoices, and cancel or resume your subscription—securely in the Stripe Billing Portal.
        </p>

        <div class="manage-points">
          <span class="manage-chip"><i class="fas fa-credit-card"></i> Update card details</span>
          <span class="manage-chip"><i class="fas fa-repeat"></i> Switch plans & quantities</span>
          <span class="manage-chip"><i class="fas fa-file-invoice"></i> Download invoices</span>
          <span class="manage-chip"><i class="fas fa-calendar-check"></i> Manage renewals</span>
        </div>

        <div style="margin-top:12px;">
          <?php if ($stripeCustomerId): ?>
            <button class="btn-portal" onclick="openPortal(this)"><i class="fas fa-gear"></i> Open Billing Portal</button>
            <span class="subtle" style="margin-left:10px;">You’ll be redirected to Stripe.</span>
          <?php else: ?>
            <a href="memberships.php" class="btn-portal"><i class="fas fa-crown"></i> Choose a plan to set up billing</a>
          <?php endif; ?>
        </div>

        <?php if ($stripeSubscriptionId): ?>
          <!-- <p class="subtle" style="margin-top:8px;">
            Subscription: <strong><?php echo htmlspecialchars($stripeSubscriptionId); ?></strong>
          </p> -->
        <?php endif; ?>
      </section>
      <!-- ===================================================================== -->

      <!-- ===== Payments History (from DB only) ===== -->
      <div class="card" style="margin-top:24px;">
        <div class="card-header">
          <h2 class="card-title">Payment History</h2>
          
        </div>
        <div style="overflow-x:auto;">
          <table class="payments" id="paymentsTable">
            <thead>
            <tr>
              <th>Invoice ID</th>
              <th>Amount</th>
              <th>Currency</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
            </thead>
            <tbody id="paymentsTbody">
              <tr><td colspan="6" style="text-align:center; opacity:.7;">Loading…</td></tr>
            </tbody>
          </table>
          <div id="paymentsPager" class="pager">
            <button class="btn-soft" id="pgPrev" disabled>‹ Prev</button>
            <span id="pgInfo" class="subtle"></span>
            <button class="btn-soft" id="pgNext" disabled>Next ›</button>
          </div>
        </div>
      </div>
      <!-- ========================================== -->
    </main>
  </div>

  <script>
    function contactSupport(){
      const subject = encodeURIComponent('Support Request from ' + window.location.hostname);
      const body = encodeURIComponent('Hello,\n\nI need help with...\n\nBest regards');
      window.location.href = `mailto:support@zicbot.com?subject=${subject}&body=${body}`;
    }

    function toggleMobileMenu(){
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('mobileOverlay');
      sidebar.classList.toggle('mobile-open');
      overlay.classList.toggle('active');
      const open = sidebar.classList.contains('mobile-open');
      document.body.style.overflow = open ? 'hidden' : '';
      document.documentElement.style.overflow = open ? 'hidden' : '';
    }
    document.getElementById('mobileOverlay').addEventListener('click', toggleMobileMenu);

    async function openPortal(btn){
      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening…';
      try{
        const res = await fetch('api/create_billing_portal.php', { method:'POST' });
        const data = await res.json();
        if (data.success && data.url) {
          window.location.href = data.url;
        } else {
          alert('Could not open billing portal: ' + (data.message || 'Unknown error'));
          btn.disabled = false; btn.innerHTML = original;
        }
      } catch (e) {
        alert('Network error: ' + e.message);
        btn.disabled = false; btn.innerHTML = original;
      }
    }

    // ===== Payments (from DB JSON rendered by PHP) =====
    const paymentsData = <?php echo json_encode($payments, JSON_UNESCAPED_SLASHES); ?>;

    // Optional: filter to only rows that have an invoice_id (comment out to show all)
    const filtered = (paymentsData || []).filter(p => (p && p.invoice_id && String(p.invoice_id).trim() !== ''));

    const PER_PAGE = 10;
    let page = 1;

    function fmtAmount(cents) {
      if (cents === null || cents === undefined || cents === '') return '—';
      const n = Number(cents) / 100;
      return n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function statusClass(s) {
      s = (s || '').toLowerCase();
      if (s === 'paid' || s === 'succeeded') return 'status-paid';
      if (s === 'pending' || s === 'processing') return 'status-pending';
      return 'status-failed';
    }
    function escapeHtml(s){
      return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function copyText(text){
      navigator.clipboard.writeText(text).then(()=> {
        // no toast lib used; keep it simple
      }).catch(()=>{});
    }

    function renderPage(p=1) {
      const tbody = document.getElementById('paymentsTbody');
      const total = filtered.length;
      const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
      page = Math.max(1, Math.min(p, totalPages));
      const start = (page - 1) * PER_PAGE;
      const rows = filtered.slice(start, start + PER_PAGE);

      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; opacity:.7;">No payments found.</td></tr>';
      } else {
        tbody.innerHTML = rows.map(r => {
          const amount  = fmtAmount(r.amount);
          const cur     = (r.currency || 'usd').toUpperCase();
          const cls     = statusClass(r.status);
          const created = r.created_at ? escapeHtml(r.created_at) : '';
          const invoice = r.invoice_id || '—';
          return `<tr>
            <td>${escapeHtml(invoice)}</td>
            <td>${amount}</td>
            <td>${escapeHtml(cur)}</td>
            <td class="${cls}">${escapeHtml(r.status || '—')}</td>
            <td>${created}</td>
            <td>${invoice !== '—' ? `<button class="copy-btn" onclick="copyText('${escapeHtml(String(invoice))}')"><i class="fas fa-copy"></i> Copy</button>` : ''}</td>
          </tr>`;
        }).join('');
      }

      const prev = document.getElementById('pgPrev');
      const next = document.getElementById('pgNext');
      const info = document.getElementById('pgInfo');
      prev.disabled = (page <= 1);
      next.disabled = (page >= totalPages);
      info.textContent = `${page} / ${totalPages} page${totalPages>1?'s':''}`;
    }

    document.getElementById('pgPrev').addEventListener('click', () => renderPage(page-1));
    document.getElementById('pgNext').addEventListener('click', () => renderPage(page+1));
    renderPage(1);
  </script>
</body>
</html>
