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

/* Fetch users + plan name (membership_plan is INT FK) */
$usersQuery = "
    SELECT u.*, mp.name AS plan_name
    FROM users u
    LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
    ORDER BY u.created_at DESC
";
$usersStmt = $db->prepare($usersQuery);
$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Zicbot Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
      /* spacing */
      .page-header + .card { margin-top: 14px; }

      /* TABLE SHELL — clips right/left edges; inner scroller handles overflow */
      .table-shell{
        border:1px solid var(--border, #2b2b31);
        background: var(--surface-2, #15151a);
        border-radius: 12px;
        overflow: hidden; /* clip */
      }
      .table-wrap{
        width:100%;
        overflow-x:auto;                  /* horizontal scroll here */
        -webkit-overflow-scrolling:touch;
        scrollbar-gutter: stable both-edges;
      }
      .table-wrap table{
        width:100%;
        min-width:1200px;                 /* force horizontal scroll on narrow viewports */
        border-collapse:separate;
        border-spacing:0;
        table-layout:auto;
      }

      /* header */
      .table-wrap thead th{
        position:sticky; top:0; z-index:1;
        white-space:nowrap;
        background:#1b1b1f;
        color:#eaeaf0;
        padding:14px 16px;
        border-bottom:1px solid var(--border, #2b2b31);
        text-align:left;
      }

      /* cells */
      .table-wrap tbody td{
        white-space:nowrap;               /* keep one line by default */
        vertical-align:middle;
        padding:14px 16px;
        border-bottom:1px solid var(--border, #2b2b31);
        color: var(--text, #e5e7eb);
        background: transparent;
      }
      .table-wrap tbody tr:nth-child(2n){ background: rgba(255,255,255,0.02); }
      .table-wrap tbody tr:hover{ background: rgba(255,255,255,0.04); }

      /* robust truncation inside text cells */
      .ellipsis{
        max-width: 320px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: block;
      }

      /* ACTIONS: allow wrapping + stack buttons vertically (two rows) */
      .col-actions{ white-space:normal; }               /* override nowrap for this column */
      .actions-stack{
        display:flex;
        flex-direction:column;                           /* stack vertically */
        gap:8px;
        align-items:flex-start;                          /* left align */
      }
      .actions-stack .btn{
        width: 140px;                                    /* consistent width; tweak as you like */
        padding: 6px 10px;
        font-size: 12px;
      }

      /* small screens: slightly narrower table/ellipsis */
      @media (max-width: 640px){
        .table-wrap table{ min-width:900px; }
        .ellipsis{ max-width: 220px; }
        .actions-stack .btn{ width: 120px; }
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
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="users.php" class="nav-link active">
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
                        <a href="logout.php" class="nav-link" style="color: #ef4444;">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Manage Users</h1>
                <div class="header-actions">
                    <span style="color: var(--text-secondary);">Total Users: <?php echo count($users); ?></span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">All Registered Users</h2>
                </div>

                <!-- Shell clips the table; inner div handles horizontal scroll -->
                <div class="table-shell">
                  <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Restaurant</th>
                                <th>Email</th>
                                <th>Country</th>
                                <th>Phone</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user):
                                $plan = $user['plan_name'] ? $user['plan_name'] : 'Free';
                                $isActive = (int)$user['is_active'] === 1;
                            ?>
                            <tr>
                                <td><?php echo (int)$user['id']; ?></td>
                                <td title="<?php echo h($user['name']); ?>">
                                  <span class="ellipsis"><?php echo h($user['name']); ?></span>
                                </td>
                                <td title="<?php echo h($user['restaurant_name']); ?>">
                                  <span class="ellipsis"><?php echo h($user['restaurant_name']); ?></span>
                                </td>
                                <td title="<?php echo h($user['email']); ?>">
                                  <span class="ellipsis"><?php echo h($user['email']); ?></span>
                                </td>
                                <td title="<?php echo h($user['country']); ?>">
                                  <span class="ellipsis"><?php echo h($user['country']); ?></span>
                                </td>
                                <td title="<?php echo h($user['phone']); ?>">
                                  <span class="ellipsis"><?php echo h($user['phone']); ?></span>
                                </td>
                                <td>
                                  <span class="status-badge"><?php echo h($plan); ?></span>
                                </td>
                                <td>
                                  <span class="status-badge <?php echo $isActive ? 'status-served' : 'status-pending'; ?>">
                                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                  </span>
                                </td>
                                <td title="<?php echo h(date('M j, Y', strtotime($user['created_at']))); ?>">
                                  <span class="ellipsis"><?php echo h(date('M j, Y', strtotime($user['created_at']))); ?></span>
                                </td>
                                <td class="col-actions">
                                  <div class="actions-stack">
                                    <button
                                        onclick="toggleUserStatus(<?php echo (int)$user['id']; ?>, <?php echo $isActive ? 1 : 0; ?>)"
                                        class="btn btn-primary">
                                        <?php echo $isActive ? 'Ban' : 'Activate'; ?>
                                    </button>
                                    <button
                                        onclick="editCredits(<?php echo (int)$user['id']; ?>)"
                                        class="btn btn-primary"
                                        style="background: var(--warning-yellow);">
                                        Credits
                                    </button>
                                  </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                  </div>
                </div>
            </div>
        </main>
    </div>

    <script>
      function toggleUserStatus(userId, currentStatus) {
          const action = Number(currentStatus) ? 'ban' : 'activate';
          if (confirm(`Are you sure you want to ${action} this user?`)) {
              fetch('api/toggle_user_status.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ user_id: userId, status: Number(currentStatus) ? 0 : 1 })
              })
              .then(r => r.json())
              .then(data => {
                  if (data.success) {
                      location.reload();
                  } else {
                      alert('Error: ' + data.message);
                  }
              })
              .catch(() => alert('Network error.'));
          }
      }

      function editCredits(userId) {
          const newCredits = prompt('Enter new credit limit for this user:');
          if (newCredits !== null && !isNaN(newCredits) && Number(newCredits) >= 0) {
              fetch('api/update_credits.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ user_id: userId, credits: parseInt(newCredits, 10) })
              })
              .then(r => r.json())
              .then(data => {
                  if (data.success) {
                      alert('Credits updated successfully!');
                  } else {
                      alert('Error: ' + data.message);
                  }
              })
              .catch(() => alert('Network error.'));
          }
      }
    </script>
</body>
</html>
