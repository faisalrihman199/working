<?php
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

// Active plans
$planQuery = "SELECT * FROM membership_plans WHERE is_active = 1 ORDER BY price ASC";
$planStmt  = $db->prepare($planQuery);
$planStmt->execute();
$plans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

// Current user plan
$userId = (int)$_SESSION['user_id'];
$userQuery = "
    SELECT u.membership_plan AS plan_id, mp.name AS plan_name
    FROM users u
    LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
    WHERE u.id = :user_id
";
$userStmt = $db->prepare($userQuery);
$userStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$userStmt->execute();
$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['plan_id' => null, 'plan_name' => null];

// Credits window
$resetInfo = null;
try {
  $cStmt = $db->prepare("
    SELECT last_reset, period_days, DATE_ADD(last_reset, INTERVAL period_days DAY) AS resets_on
    FROM user_credits WHERE user_id = :uid LIMIT 1
  ");
  $cStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
  $cStmt->execute();
  $credits = $cStmt->fetch(PDO::FETCH_ASSOC);
  if ($credits) {
    $resetInfo = [
      'last_reset' => $credits['last_reset'],
      'resets_on'  => $credits['resets_on']
    ];
  }
} catch (PDOException $e) { /* ignore */ }

// Map plan name -> Stripe price id from .env (keep original)
function priceIdForPlanName(string $name): string {
  $map = [
    'basic'        => $_ENV['ZICBOT_BASIC_30']        ?? '',
    'growth' => $_ENV['ZICBOT_GROWTH_60'] ?? '',
    'brand'   => $_ENV['ZICBOT_BRAND_100']  ?? '',
  ];
  $key = strtolower(trim($name));
  return $map[$key] ?? '';
}

// Does the user already have a Stripe subscription?
$us = $db->prepare("SELECT stripe_subscription_id FROM users WHERE id = :id LIMIT 1");
$us->execute([':id'=>$userId]);
$u2 = $us->fetch(PDO::FETCH_ASSOC) ?: [];
$stripeSubId = $u2['stripe_subscription_id'] ?? null;

// banners
$checkoutState  = isset($_GET['checkout']) ? $_GET['checkout'] : '';
$isSuccess      = ($checkoutState === 'success');
$isCanceled     = ($checkoutState === 'canceled' || $checkoutState === 'cancel');

$restaurantName = $_SESSION['restaurant_name'] ?? 'Restaurant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Memberships - Zicbot</title>
  <link rel="stylesheet" href="./assets//css//style.css" />
  <link rel="icon" href="/icon.png" type="image/x-icon">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    .hamburger { display:none; background:transparent; border:0; color:var(--text,#fff); font-size:1.25rem; padding:8px; margin-right:10px; cursor:pointer; }
    @media (max-width:900px){ .hamburger{display:inline-flex;align-items:center;justify-content:center;} }
    .mobile-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.45); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:90; }
    .mobile-overlay.active{ opacity:1; pointer-events:auto; }
    .sidebar.mobile-open{ transform:translateX(0); }

    .modal-overlay { position: fixed; inset: 0; background: rgba(10,10,12,.65); backdrop-filter: blur(3px); display:none; align-items:center; justify-content:center; z-index: 1001; }
    .modal-overlay.show { display:flex; }
    .confirm-card {
      width: min(560px, 92vw);
      background: radial-gradient(1200px 600px at -20% -20%, rgba(255,170,120,.10), transparent 40%),
                  radial-gradient(1200px 600px at 120% 120%, rgba(255,110,170,.10), transparent 40%),
                  var(--secondary-dark, #0f172a);
      border: 1px solid rgba(255,255,255,.06);
      border-radius: 18px;
      box-shadow: 0 20px 50px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05);
      padding: 22px 22px 18px;
      color: var(--text-light, #f8fafc);
      position: relative; overflow: hidden;
    }
    .confirm-header { display:flex; align-items:center; gap:12px; margin-bottom: 10px; }
    .confirm-heart { width:38px;height:38px; border-radius:999px; display:grid;place-items:center;
      background: linear-gradient(135deg,#ff7eb3,#ff758c);
      box-shadow: 0 0 18px rgba(255,120,160,.35); color:#fff;
    }
    .confirm-title { font-weight:700; font-size:1.1rem; letter-spacing:.3px; }
    .confirm-body { margin: 8px 0 14px; color: var(--text-secondary,#94a3b8); line-height:1.75; }
    .confirm-body .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px;
      background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.09); color:#e2e8f0; font-size:.92rem; margin-right:8px; }
    .confirm-amount { font-size:1.9rem; font-weight:800; margin-top:6px; background: linear-gradient(90deg,#ffd29e,#ff9ec4);
      -webkit-background-clip:text; background-clip:text; color:transparent; }
    .confirm-actions { display:flex; gap:10px; margin-top:14px; }
    .btn-soft { border:1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.04); color:#e2e8f0; padding:10px 14px; border-radius:12px; cursor:pointer; }
    .btn-soft:hover { background: rgba(255,255,255,.06); }
    .btn-love { background: linear-gradient(135deg,#ef4444,#f97316); color:#fff; padding:10px 16px; border-radius:12px; border:none; cursor:pointer;
      box-shadow: 0 14px 28px rgba(255,120,160,.25), inset 0 1px 0 rgba(255,255,255,.18); }
    .btn-love:disabled { opacity:.7; cursor:not-allowed; }

    .banner-wrap { margin-bottom:14px; position:relative; }
    .celebrate, .oops { position: relative; border-radius: 14px; padding: 14px 16px; border: 1px solid rgba(255,255,255,.06); overflow: hidden; }
    .celebrate { background: linear-gradient(135deg, rgba(255,192,120,.11), rgba(255,120,160,.11)); color: #fde68a; }
    .oops { background: linear-gradient(135deg, rgba(255,120,120,.13), rgba(255,90,90,.09)); color: #fecaca; }
    .celebrate h4, .oops h4 { margin: 0 0 6px; color: #fff; }
    .celebrate .mini, .oops .mini { color: #e2e8f0; opacity:.8; }
    .banner-actions { margin-top:10px; display:flex; gap:10px; flex-wrap: wrap; }
    .btn-ghost { background: transparent; border:1px solid rgba(255,255,255,.15); color:#fff; padding:8px 12px; border-radius:10px; cursor:pointer; }
    .close-x { position:absolute; top:10px; right:12px; color:#fff; opacity:.8; cursor:pointer; }

    .confetti { position:absolute; inset: 0; pointer-events:none;
      background:
        radial-gradient(circle at 10% 20%, rgba(255,219,150,.3) 0 2px, transparent 3px),
        radial-gradient(circle at 80% 30%, rgba(255,142,186,.3) 0 2px, transparent 3px),
        radial-gradient(circle at 50% 80%, rgba(129,140,248,.30) 0 2px, transparent 3px);
      animation: floaty 5s ease-in-out infinite alternate; opacity:.7;
    }
    @keyframes floaty { from { transform: translateY(0px); } to { transform: translateY(-10px); } }
  </style>
</head>
<body>
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
          <li class="nav-item"><a href="memberships.php" class="nav-link active"><i class="fas fa-crown"></i> Memberships</a></li>
          <li class="nav-item"><a href="billing.php" class="nav-link "><i class="fas fa-credit-card"></i> Billing</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link"><i class="fas fa-coins"></i> Credits</a></li>
          <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
          <li class="nav-item"><a href="#" class="nav-link" onclick="contactSupport()"><i class="fas fa-question-circle"></i> Help</a></li>
          <li class="nav-item" style="margin-top:20px;"><a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <button class="hamburger" id="hamburgerBtn" onclick="toggleMobileMenu()">
          <i class="fas fa-bars"></i>
        </button>

        <h1 class="page-title">Membership Plans</h1>
        <div class="header-actions">
          <?php if (!empty($user['plan_id'])): ?>
            <div class="alert alert-success">
              Current Plan: <strong><?php echo htmlspecialchars($user['plan_name']); ?></strong>
              <?php if (!empty($resetInfo['resets_on'])): ?>
                &nbsp;|&nbsp; Credits reset on: <strong><?php echo date('M j, Y', strtotime($resetInfo['resets_on'])); ?></strong>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="alert alert-warning">
              You are currently on the <strong>Free Plan</strong>. Upgrade to unlock more features!
              <?php if (!empty($resetInfo['resets_on'])): ?>
                &nbsp;|&nbsp; Credits reset on: <strong><?php echo date('M j, Y', strtotime($resetInfo['resets_on'])); ?></strong>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="banner-wrap" id="bannerWrap">
        <?php if ($isSuccess): ?>
          <div class="celebrate">
            <div class="confetti"></div>
            <span class="close-x" onclick="this.parentElement.remove()">×</span>
            <h4>✨ Payment received — woohoo!</h4>
            <div class="mini">Your plan will activate shortly. Thanks for choosing Zicbot — we love building with you. 💖</div>
            <div class="banner-actions">
              <a href="dashboard.php" class="btn-ghost"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
              <a href="memberships.php" class="btn-ghost"><i class="fas fa-rotate"></i> Refresh this page</a>
            </div>
          </div>
        <?php elseif ($isCanceled): ?>
          <div class="oops">
            <span class="close-x" onclick="this.parentElement.remove()">×</span>
            <h4>We didn’t complete the checkout 💌</h4>
            <div class="mini">No charge was made. You can try again anytime, and we’ll be right here cheering you on.</div>
            <div class="banner-actions">
              <a href="memberships.php" class="btn-ghost"><i class="fas fa-heart"></i> Choose a plan</a>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="plans-grid">
        <?php foreach ($plans as $index => $plan): ?>
          <?php $priceId = priceIdForPlanName($plan['name']); ?>
          <div class="plan-card <?php echo $index === 1 ? 'popular' : ''; ?>">
            <h3 class="plan-name"><?php echo htmlspecialchars($plan['name']); ?></h3>
            <div class="plan-price">$<?php echo number_format((float)$plan['price'], 0); ?></div>
            <div class="plan-period">per month</div>
            <ul class="plan-features">
              <li><?php echo number_format((int)$plan['credits_limit']); ?> message credits</li>
              <?php foreach (array_filter(array_map('trim', explode(',', (string)$plan['features']))) as $feature): ?>
                <li><?php echo htmlspecialchars($feature); ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if ((int)$user['plan_id'] === (int)$plan['id']): ?>
              <button class="plan-active" disabled >
                <i class="fas fa-check"></i> Current Plan
              </button>
            <?php else: ?>
              <button
                class="btn-plan choose-plan"
                data-price-id="<?php echo htmlspecialchars($priceId); ?>"
                data-plan-name="<?php echo htmlspecialchars($plan['name']); ?>"
                data-plan-price="<?php echo (float)$plan['price']; ?>">
                <i class="fas fa-credit-card"></i> Choose <?php echo htmlspecialchars($plan['name']); ?>
              </button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($stripeSubId)): ?>
        <div class="card" style="margin-top:24px;">
          <div class="card-header">
            <h2 class="card-title"><i class="fas fa-circle-xmark"></i> Manage Subscription</h2>
          </div>
          <div style="padding:12px;">
            <p style="opacity:.9;margin-bottom:10px;">
              Canceling will stop future renewals. You’ll keep access until the end of your current billing period.
            </p>
            <button id="btnCancelSub" class="btn-love" style="background:linear-gradient(135deg,#ef4444,#f97316)">
              <i class="fas fa-ban"></i> Cancel Subscription
            </button>
          </div>
        </div>
      <?php endif; ?>


       <div class="card" style="margin-top: 40px;">
        <div class="card-header">
          <h2 class="card-title">Plan Features Comparison</h2>
        </div>
        <div style="overflow-x: auto;">
          <table>
            <thead>
              <tr>
                <th>Feature</th>
                <th>Basic</th>
                <th>Professional</th>
                <th>Enterprise</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Monthly Credits</td>
                <td>15000</td>
                <td>30,000</td>
                <td>60,000</td>
              </tr>
              <tr>
                <td>Order Management</td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
              </tr>
              <tr>
                <td>Real-time Notifications</td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
              </tr>
              <tr>
                <td>AI Training</td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
              </tr>
              <tr>
                <td>Payment gateway integration</td>
                <td><i class="fas fa-times" style="color: var(--danger-red);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
              </tr>
              <tr>
                <td>Restaurant Branding</td>
                <td><i class="fas fa-times" style="color: var(--danger-red);"></i></td>
                <td><i class="fas fa-times" style="color: var(--danger-red);"></i></td>
                <td><i class="fas fa-check" style="color: var(--success-green);"></i></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script>window.ZICBOT = { hasSub: <?php echo $stripeSubId ? 'true' : 'false'; ?> };</script>

  <!-- Romantic confirm modal (existing for choosing plan) -->
  <div class="modal-overlay" id="confirmOverlay" aria-hidden="true">
    <div class="confirm-card">
      <div class="confirm-header">
        <div class="confirm-heart"><i class="fas fa-heart"></i></div>
        <div class="confirm-title" id="confirmTitle">Confirm your plan</div>
      </div>
      <div class="confirm-body">
        <div id="confirmCopy">Ready to fall in love with <strong>Zicbot</strong> all over again? This subscription renews every month until you cancel.</div>
        <div style="margin-top:10px;">
          <span class="pill"><i class="fas fa-shield-heart"></i> Secure Stripe Checkout</span>
          <span class="pill"><i class="fas fa-rotate"></i> Renews monthly</span>
          <span class="pill"><i class="fas fa-bolt"></i> Instant activation</span>
        </div>
        <div class="confirm-amount" id="confirmAmount">$0</div>
      </div>
      <div class="confirm-actions">
        <button class="btn-soft" id="cancelConfirm"><i class="fas fa-xmark"></i> Not now</button>
        <button class="btn-love" id="proceedConfirm"><i class="fas fa-heart-circle-check"></i> Continue</button>
      </div>
    </div>
  </div>

  <!-- Pretty cancel confirm modal (NEW) -->
  <div class="modal-overlay" id="cancelOverlay" aria-hidden="true">
    <div class="confirm-card">
      <div class="confirm-header">
        <div class="confirm-heart" style="background: linear-gradient(135deg,#ef4444,#f97316)">
          <i class="fas fa-circle-xmark"></i>
        </div>
        <div class="confirm-title">Cancel subscription?</div>
      </div>

      <div class="confirm-body">
        <div>
          If you cancel now, <strong>you won’t be charged again</strong>.
          You’ll keep access until the end of your current billing period.
        </div>

        <div style="margin-top:10px;">
          <span class="pill"><i class="fas fa-shield-heart"></i> Safe to undo anytime</span>
          <span class="pill"><i class="fas fa-rotate"></i> Ends after current cycle</span>
          <span class="pill"><i class="fas fa-file-invoice"></i> Invoices stay available</span>
        </div>
      </div>

      <div class="confirm-actions">
        <button class="btn-soft" id="btnCancelKeep">
          <i class="fas fa-arrow-left"></i> Keep my subscription
        </button>
        <button class="btn-love" id="btnCancelGo" style="background:linear-gradient(135deg,#ef4444,#f97316)">
          <i class="fas fa-ban"></i> Yes, cancel at period end
        </button>
      </div>
    </div>
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
    document.querySelectorAll('.nav-link').forEach(link=>{
      link.addEventListener('click', ()=>{
        if (window.innerWidth <= 900){
          document.getElementById('sidebar').classList.remove('mobile-open');
          document.getElementById('mobileOverlay').classList.remove('active');
          document.body.style.overflow = '';
          document.documentElement.style.overflow = '';
        }
      });
    });

    // ESC closes menus & modals
    document.addEventListener('keydown', (e)=>{
      if(e.key==='Escape'){
        document.getElementById('sidebar').classList.remove('mobile-open');
        document.getElementById('mobileOverlay').classList.remove('active');
        closeConfirm();
        closeCancelModal();
      }
    });

    // ---- Choose plan pretty confirm (kept) ----
    const overlayEl   = document.getElementById('confirmOverlay');
    const titleEl     = document.getElementById('confirmTitle');
    const copyEl      = document.getElementById('confirmCopy');
    const amountEl    = document.getElementById('confirmAmount');
    const cancelBtn   = document.getElementById('cancelConfirm');
    const proceedBtn  = document.getElementById('proceedConfirm');

    let pending = null; // {priceId, planName, planPrice}

    function openConfirm({priceId, planName, planPrice}){
      pending = {priceId, planName, planPrice};
      titleEl.textContent = `Confirm ${planName}`;
      amountEl.textContent = `$${Number(planPrice).toFixed(0)}/month`;
      copyEl.innerHTML = `You’re choosing the <strong>${planName}</strong> plan. It renews monthly until you cancel. You can change plans anytime.`;
      overlayEl.classList.add('show');
      overlayEl.setAttribute('aria-hidden', 'false');
    }
    function closeConfirm(){
      overlayEl.classList.remove('show');
      overlayEl.setAttribute('aria-hidden', 'true');
      pending = null;
      proceedBtn.disabled = false;
      proceedBtn.innerHTML = '<i class="fas fa-heart-circle-check"></i> Continue';
    }

    cancelBtn.addEventListener('click', closeConfirm);
    overlayEl.addEventListener('click', (e)=>{ if(e.target===overlayEl) closeConfirm(); });

    // Decide: first-time checkout OR switch existing subscription
    proceedBtn.addEventListener('click', async ()=>{
      if(!pending) return;
      const {priceId} = pending;
      proceedBtn.disabled = true;
      proceedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Working…';
      try{
        if (window.ZICBOT && window.ZICBOT.hasSub) {
          const res = await fetch('api/switch_plan.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ price_id: priceId })
          });
          const data = await res.json();
          if (data.success) {
            window.location.href = 'memberships.php?checkout=success&switched=1';
          } else {
            alert('Failed to switch plan: ' + (data.message || 'Unknown error'));
            proceedBtn.disabled = false;
            proceedBtn.innerHTML = '<i class="fas fa-heart-circle-check"></i> Continue';
          }
        } else {
          const res  = await fetch('api/create_checkout.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({ plan_id: priceId })
          });
          const data = await res.json();
          if(data.success && data.checkout_url){
            window.location.href = data.checkout_url;
          } else {
            alert('Failed: ' + (data.message || 'Unknown error'));
            proceedBtn.disabled = false;
            proceedBtn.innerHTML = '<i class="fas fa-heart-circle-check"></i> Continue';
          }
        }
      }catch(err){
        alert('Network error: ' + err.message);
        proceedBtn.disabled = false;
        proceedBtn.innerHTML = '<i class="fas fa-heart-circle-check"></i> Continue';
      }
    });

    // Hook “Choose plan” buttons to the pretty modal
    document.querySelectorAll('.choose-plan').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const priceId  = btn.dataset.priceId;
        
        
        const planName = btn.dataset.planName || 'Selected';
        const planPrice= btn.dataset.planPrice || '0';
        if(!priceId){
          alert('This plan is not available yet. Please contact support.');
          return;
        }
        openConfirm({priceId, planName, planPrice});
      });
    });

    // ===== Pretty cancel confirm flow (NEW) =====
    const cancelOverlay  = document.getElementById('cancelOverlay');
    const btnCancelOpen  = document.getElementById('btnCancelSub');  // existing button on the page
    const btnCancelKeep  = document.getElementById('btnCancelKeep'); // modal: keep subscription
    const btnCancelGo    = document.getElementById('btnCancelGo');   // modal: confirm cancel

    function openCancelModal(){
      cancelOverlay.classList.add('show');
      cancelOverlay.setAttribute('aria-hidden', 'false');
    }
    function closeCancelModal(){
      cancelOverlay.classList.remove('show');
      cancelOverlay.setAttribute('aria-hidden', 'true');
      btnCancelGo.disabled = false;
      btnCancelGo.innerHTML = '<i class="fas fa-ban"></i> Yes, cancel at period end';
    }

    if (btnCancelOpen) {
      btnCancelOpen.addEventListener('click', (e)=>{
        e.preventDefault();
        openCancelModal();
      });
    }
    if (btnCancelKeep) {
      btnCancelKeep.addEventListener('click', closeCancelModal);
    }
    if (cancelOverlay) {
      cancelOverlay.addEventListener('click', (e)=>{
        if (e.target === cancelOverlay) closeCancelModal();
      });
    }

    if (btnCancelGo) {
      btnCancelGo.addEventListener('click', async ()=>{
        btnCancelGo.disabled = true;
        btnCancelGo.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Canceling…';
        try{
          const res  = await fetch('api/cancel_subscription.php', { method:'POST' });
          const data = await res.json();
          if (data.success) {
            window.location.href = 'memberships.php?checkout=success';
          } else {
            alert('Cancel failed: ' + (data.message || 'Unknown error'));
            btnCancelGo.disabled = false;
            btnCancelGo.innerHTML = '<i class="fas fa-ban"></i> Yes, cancel at period end';
          }
        } catch (e) {
          alert('Network error: ' + e.message);
          btnCancelGo.disabled = false;
          btnCancelGo.innerHTML = '<i class="fas fa-ban"></i> Yes, cancel at period end';
        }
      });
    }
  </script>
</body>
</html>
