<?php
require_once '../includes/auth.php';
require_once '../config/database.php';

$auth = new Auth();

// Check if user is admin
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header("Location: ../index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get statistics
$statsQuery = "SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN membership_plan != 'free' THEN 1 ELSE 0 END) as paid_users
FROM users";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get recent users
$usersQuery = "SELECT * FROM users ORDER BY created_at DESC LIMIT 10";
$usersStmt = $db->prepare($usersQuery);
$usersStmt->execute();
$recent_users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Ziclon</title>
    <link rel="icon" href="/icon.png" type="image/x-icon">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">Ziclon Admin</div>
                <div class="sidebar-subtitle">Administration Panel</div>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
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
                        <a href="../logout.php" class="nav-link" style="color: #ef4444;">
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
                    <span style="color: var(--text-secondary);">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['paid_users']; ?></div>
                    <div class="stat-label">Paid Memberships</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_users'] - $stats['active_users']; ?></div>
                    <div class="stat-label">Inactive Users</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Users</h2>
                    <a href="users.php" class="btn btn-primary">View All Users</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Restaurant</th>
                            <th>Email</th>
                            <th>Country</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['restaurant_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['country']); ?></td>
                            <td><span class="status-badge status-<?php echo $user['membership_plan']; ?>"><?php echo ucfirst($user['membership_plan']); ?></span></td>
                            <td><span class="status-badge <?php echo $user['is_active'] ? 'status-served' : 'status-pending'; ?>"><?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <button onclick="toggleUserStatus(<?php echo $user['id']; ?>, <?php echo $user['is_active']; ?>)" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                    <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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