<?php
session_start();

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Users + credits + plan name
$query = "
  SELECT 
    u.id, u.name, u.restaurant_name, u.email, u.membership_plan,
    COALESCE(uc.credits_limit, 100)  AS credits_limit,
    COALESCE(uc.credits_used, 0)     AS credits_used,
    COALESCE(uc.last_reset, u.created_at) AS last_reset,
    mp.name AS plan_name
  FROM users u
  LEFT JOIN user_credits uc ON u.id = uc.user_id
  LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
  WHERE u.is_admin = 0
  ORDER BY u.name
";
$stmt = $db->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalCreditsAllocated = 0;
$totalCreditsUsed = 0;
foreach ($users as $user) {
    $totalCreditsAllocated += (int)$user['credits_limit'];
    $totalCreditsUsed      += (int)$user['credits_used'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Credits Management - Zicbot Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

  <style>
    .page-header + .stats-grid { margin-top: 10px; }
    .stats-grid + .card { margin-top: 14px; }

    /* Clipped shell + inner horizontal scroller */
    .table-shell{
      border:1px solid var(--border, #2b2b31);
      background: var(--surface-2, #15151a);
      border-radius:12px;
      overflow:hidden; /* clip edges */
    }
    .table-wrap{
      width:100%;
      overflow-x:auto;                 /* horizontal scroll lives here */
      -webkit-overflow-scrolling:touch;
      scrollbar-gutter: stable both-edges;
    }
    .table-wrap table{
      width:100%;
      min-width:1200px;                /* force scroll on narrow screens */
      border-collapse:separate;
      border-spacing:0;
      table-layout:auto;
    }

    thead th{
      position:sticky; top:0; z-index:1;
      background:#1b1b1f;
      color:#eaeaf0;
      padding:14px 16px;
      border-bottom:1px solid var(--border, #2b2b31);
      text-align:left;
      white-space:nowrap;
    }
    tbody td{
      padding:14px 16px;
      border-bottom:1px solid var(--border, #2b2b31);
      color:var(--text, #e5e7eb);
      white-space:nowrap;              /* keep in one line; scroller handles overflow */
      vertical-align:middle;
    }
    tbody tr:nth-child(2n){ background: rgba(255,255,255,0.02); }
    tbody tr:hover{ background: rgba(255,255,255,0.04); }

    /* truncation helpers */
    .ellipsis{ max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
    @media (max-width: 640px){
      .table-wrap table{ min-width:900px; }
      .ellipsis{ max-width:220px; }
    }

    /* usage bar */
    .usage-wrap{ min-width:160px; }
    .bar{ height:8px; width:100%; background:#2a2a2e; border-radius:999px; overflow:hidden; }
    .fill{ height:100%; background:linear-gradient(90deg,#22c55e,#f59e0b,#ef4444); }
    .bar-meta{ font-size:12px; color:#a6abb3; margin-top:6px; display:flex; justify-content:space-between; gap:10px; }

    /* color accents for remaining cell */
    .left-good{ color: var(--success-green, #86efac); }
    .left-warn{ color: var(--warning-yellow, #fbbf24); }
    .left-bad{  color: var(--danger-red, #ef4444); }

    /* actions stacked vertically */
    .col-actions{ white-space:normal; }
    .actions-stack{ display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
    .actions-stack .btn{ padding:6px 10px; font-size:12px; width:120px; }
    .btn-warn{ background: var(--warning-yellow, #f59e0b) !important; color:#111 !important; }
  </style>
</head>
<body>
  <div class="dashboard">
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">Zicbot Admin</div>
        <div class="sidebar-subtitle">Administration Panel</div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Manage Users</a></li>
          <li class="nav-item"><a href="plans.php" class="nav-link"><i class="fas fa-crown"></i> Membership Plans</a></li>
          <li class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-list"></i> All Orders</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link active"><i class="fas fa-coins"></i> Credits Management</a></li>
          <li class="nav-item" style="margin-top: 20px;"><a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <h1 class="page-title">Credits Management</h1>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-number"><?= number_format($totalCreditsAllocated); ?></div>
          <div class="stat-label">Total Credits Allocated</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?= number_format($totalCreditsUsed); ?></div>
          <div class="stat-label">Total Credits Used</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?= number_format($totalCreditsAllocated - $totalCreditsUsed); ?></div>
          <div class="stat-label">Credits Remaining</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?= count($users); ?></div>
          <div class="stat-label">Active Restaurants</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 class="card-title">User Credits Overview</h2>
        </div>

        <?php if (empty($users)): ?>
          <p style="text-align:center; color:var(--text-secondary); padding:40px;">No users found.</p>
        <?php else: ?>
          <div class="table-shell">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Restaurant</th>
                    <th>Owner</th>
                    <th>Email</th>
                    <th>Plan</th>
                    <th>Credits Limit</th>
                    <th>Credits Used</th>
                    <th>Credits Left</th>
                    <th>Usage</th>
                    <th>Last Reset</th>
                    <th class="col-actions">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $u):
                    $limit  = max(0, (int)$u['credits_limit']);
                    $used   = min($limit, max(0, (int)$u['credits_used']));
                    $left   = $limit - $used;
                    $usage  = $limit > 0 ? ($used / $limit) * 100 : 0;
                    $leftClass = $left < 50 ? 'left-bad' : ($left < 100 ? 'left-warn' : 'left-good');
                    $planName = $u['plan_name'] ? $u['plan_name'] : 'Free';
                  ?>
                  <tr>
                    <td title="<?= h($u['restaurant_name']); ?>"><span class="ellipsis"><?= h($u['restaurant_name']); ?></span></td>
                    <td title="<?= h($u['name']); ?>"><span class="ellipsis"><?= h($u['name']); ?></span></td>
                    <td title="<?= h($u['email']); ?>"><span class="ellipsis"><?= h($u['email']); ?></span></td>
                    <td><span class="status-badge"><?= h($planName); ?></span></td>
                    <td><?= number_format($limit); ?></td>
                    <td><?= number_format($used); ?></td>
                    <td class="<?= $leftClass; ?>"><?= number_format($left); ?></td>
                    <td class="usage-wrap">
                      <div class="bar"><div class="fill" style="width: <?= min(100, $usage); ?>%"></div></div>
                      <div class="bar-meta"><span><?= number_format($usage,1); ?>%</span><span><?= number_format($used); ?>/<?= number_format($limit); ?></span></div>
                    </td>
                    <td><?= date('M j, Y', strtotime($u['last_reset'])); ?></td>
                    <td class="col-actions">
                      <div class="actions-stack">
                        <button onclick="editCredits(<?= (int)$u['id']; ?>, <?= (int)$limit; ?>)" class="btn btn-primary">
                          <i class="fas fa-pen"></i> Edit
                        </button>
                        <button onclick="resetCredits(<?= (int)$u['id']; ?>)" class="btn btn-primary btn-warn">
                          <i class="fas fa-rotate-right"></i> Reset
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    function editCredits(userId, currentLimit) {
      const input = prompt(`Enter new credit limit for this user:\nCurrent limit: ${currentLimit}`, currentLimit);
      const newCredits = Number(input);
      if (input !== null && Number.isFinite(newCredits) && newCredits >= 0) {
        fetch('api/update_credits.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ user_id: userId, credits: Math.trunc(newCredits) })
        })
        .then(r => r.json())
        .then(d => {
          if (d.success) { alert('Credits updated successfully!'); location.reload(); }
          else { alert('Error: ' + d.message); }
        })
        .catch(() => alert('Network error.'));
      }
    }

    function resetCredits(userId) {
      if (!confirm("Reset this user's credits used to 0?")) return;
      fetch('api/reset_credits.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: userId })
      })
      .then(r => r.json())
      .then(d => {
        if (d.success) { alert('Credits reset successfully!'); location.reload(); }
        else { alert('Error: ' + d.message); }
      })
      .catch(() => alert('Network error.'));
    }
  </script>
</body>
</html>
