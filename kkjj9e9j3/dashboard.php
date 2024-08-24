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

/* ---------- Stats (INT membership_plan schema) ---------- */
$statsQuery = "SELECT 
    COUNT(*) AS total_users,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_users,
    SUM(CASE WHEN membership_plan IS NOT NULL THEN 1 ELSE 0 END) AS paid_users
FROM users";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* ---------- Recent users with plan name ---------- */
$usersQuery = "SELECT u.*, mp.name AS plan_name
               FROM users u
               LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
               ORDER BY u.created_at DESC
               LIMIT 10";
$usersStmt = $db->prepare($usersQuery);
$usersStmt->execute();
$recent_users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Zicbot</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
      /* spacing so the table doesn't glue to the banner */
      .stats-grid + .card,
      .page-header + .stats-grid { margin-top: 14px; }

      /* SCROLLABLE TABLE (horizontal) */
      .table-wrap{
        width:100%;
        overflow-x:auto;                 /* ← horizontal scroll */
        -webkit-overflow-scrolling:touch;
        scrollbar-gutter: stable both-edges;
      }
      .table-wrap table{
        width:100%;
        min-width:1200px;                /* force scroll on small viewports */
        border-collapse:separate;
        border-spacing:0;
        table-layout:auto;
      }
      .table-wrap thead th{
        position:sticky; top:0; z-index:1;
        white-space:nowrap;              /* prevent header wrap like ‘Coun try’ */
      }
      .table-wrap th,
      .table-wrap td{
        white-space:nowrap;              /* no wrapping inside cells */
        vertical-align:middle;
      }

      /* smart truncation for wide columns (show full on hover via title attr) */
      .col-email,
      .col-restaurant{
        max-width:320px;
        overflow:hidden;
        text-overflow:ellipsis;
      }
      .col-actions{ white-space:nowrap; }

      @media (max-width: 640px){
        .table-wrap table{ min-width:900px; }
      }
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
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="fas fa-users"></i> Manage Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="plans.php" class="nav-link">
                            <i class="fas fa-crown"></i> Membership Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <i class="fas fa-list"></i> All Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="credits.php" class="nav-link">
                            <i class="fas fa-coins"></i> Credits Management
                        </a>
                    </li>
                    <li class="nav-item" style="margin-top: 20px;">
                        <a href="../dashboard.php" class="nav-link" style="color: var(--accent-orange);">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="logout.php" class="nav-link" style="color: #ef4444;">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Admin Dashboard</h1>
                <div class="header-actions">
                    <span style="color: var(--text-secondary);">Welcome, <?php echo h($_SESSION['admin_username']); ?></span>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo (int)$stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo (int)$stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo (int)$stats['paid_users']; ?></div>
                    <div class="stat-label">Paid Memberships</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo (int)$stats['total_users'] - (int)$stats['active_users']; ?></div>
                    <div class="stat-label">Inactive Users</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Users</h2>
                    <a href="users.php" class="btn btn-primary">View All Users</a>
                </div>

                <!-- Scroll container prevents horizontal overflow; no wrapping inside cells -->
                <div class="table-wrap">
                  <table>
                      <thead>
                          <tr>
                              <th>Name</th>
                              <th class="col-restaurant">Restaurant</th>
                              <th class="col-email">Email</th>
                              <th>Country</th>
                              <th>Plan</th>
                              <th>Status</th>
                              <th>Joined</th>
                              <th class="col-actions">Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($recent_users as $user): 
                              $plan = $user['plan_name'] ? $user['plan_name'] : 'Free';
                          ?>
                          <tr>
                              <td title="<?php echo h($user['name']); ?>">
                                <?php echo h($user['name']); ?>
                              </td>
                              <td class="col-restaurant" title="<?php echo h($user['restaurant_name']); ?>">
                                <?php echo h($user['restaurant_name']); ?>
                              </td>
                              <td class="col-email" title="<?php echo h($user['email']); ?>">
                                <?php echo h($user['email']); ?>
                              </td>
                              <td title="<?php echo h($user['country']); ?>">
                                <?php echo h($user['country']); ?>
                              </td>
                              <td>
                                <span class="status-badge"><?php echo h($plan); ?></span>
                              </td>
                              <td>
                                <span class="status-badge <?php echo $user['is_active'] ? 'status-served' : 'status-pending'; ?>">
                                  <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                              </td>
                              <td title="<?php echo h(date('M j, Y', strtotime($user['created_at']))); ?>">
                                <?php echo h(date('M j, Y', strtotime($user['created_at']))); ?>
                              </td>
                              <td class="col-actions">
                                  <button onclick="toggleUserStatus(<?php echo (int)$user['id']; ?>, <?php echo (int)$user['is_active']; ?>)"
                                          class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                      <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                  </button>
                              </td>
                          </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleUserStatus(userId, currentStatus) {
            const action = currentStatus ? 'deactivate' : 'activate';
            if (confirm(`Are you sure you want to ${action} this user?`)) {
                fetch('api/toggle_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId, status: currentStatus ? 0 : 1 })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
    </script>
</body>
</html>
