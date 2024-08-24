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

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_plan']) || isset($_POST['edit_plan'])) {
        $name = $_POST['name'] ?? '';
        $price = $_POST['price'] ?? '';
        $credits = $_POST['credits'] ?? '';
        $features = $_POST['features'] ?? '';
        $planId = $_POST['plan_id'] ?? '';
        
        if (!empty($name) && !empty($price) && !empty($credits)) {
            try {
                if (isset($_POST['edit_plan']) && !empty($planId)) {
                    // Update existing plan
                    $query = "UPDATE membership_plans SET name = :name, price = :price, credits_limit = :credits, features = :features WHERE id = :id";
                } else {
                    // Add new plan
                    $query = "INSERT INTO membership_plans (name, price, credits_limit, duration_days, features) VALUES (:name, :price, :credits, 30, :features)";
                }
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':price', $price);
                $stmt->bindParam(':credits', $credits);
                $stmt->bindParam(':features', $features);
                if (isset($_POST['edit_plan']) && !empty($planId)) {
                    $stmt->bindParam(':id', $planId);
                }
                
                if ($stmt->execute()) {
                    $success = isset($_POST['edit_plan']) ? 'Membership plan updated successfully!' : 'Membership plan added successfully!';
                } else {
                    $error = isset($_POST['edit_plan']) ? 'Failed to update membership plan' : 'Failed to add membership plan';
                }
            } catch(PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        } else {
            $error = 'Please fill in all required fields';
        }
    } elseif (isset($_POST['delete_plan'])) {
        $planId = $_POST['plan_id'] ?? '';
        if (!empty($planId)) {
            try {
                $query = "DELETE FROM membership_plans WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $planId);
                $stmt->execute();
                $success = 'Membership plan deleted successfully!';
            } catch(PDOException $e) {
                $error = 'Failed to delete plan';
            }
        }
    }
}

// Get all membership plans
$plansQuery = "SELECT * FROM membership_plans ORDER BY price ASC";
$plansStmt = $db->prepare($plansQuery);
$plansStmt->execute();
$plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Plans - Zicbot Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                        <a href="users.php" class="nav-link">
                            <i class="fas fa-users"></i> Manage Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="plans.php" class="nav-link active">
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
                <h1 class="page-title">Membership Plans Management</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title" id="formTitle">Add New Membership Plan</h2>
                </div>
                
                <form method="POST" action="" id="planForm">
                    <input type="hidden" id="plan_id" name="plan_id" value="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="name">Plan Name</label>
                            <input type="text" id="name" name="name" class="form-control" required placeholder="e.g., Basic, Professional">
                        </div>
                        
                        <div class="form-group">
                            <label for="price">Monthly Price ($)</label>
                            <input type="number" id="price" name="price" class="form-control" step="0.01" required placeholder="29.99">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="credits">Credits Limit</label>
                        <input type="number" id="credits" name="credits" class="form-control" required placeholder="1000">
                    </div>

                    <div class="form-group">
                        <label for="features">Features (comma separated)</label>
                        <textarea id="features" name="features" class="form-control" rows="3" placeholder="Order management, Email support, Analytics"></textarea>
                    </div>

                    <button type="submit" name="add_plan" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-plus"></i> <span id="submitText">Add Membership Plan</span>
                    </button>
                    <button type="button" class="btn btn-primary" onclick="cancelEdit()" id="cancelBtn" style="display: none; background: var(--text-secondary); margin-left: 10px;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Current Membership Plans</h2>
                </div>
                
                <?php if (empty($plans)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No membership plans found. Add your first plan above.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Plan Name</th>
                                <th>Price</th>
                                <th>Credits</th>
                                <th>Features</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($plan['name']); ?></strong></td>
                                <td>$<?php echo number_format($plan['price'], 2); ?>/month</td>
                                <td><?php echo number_format($plan['credits_limit']); ?> credits</td>
                                <td><?php echo htmlspecialchars($plan['features'] ?: 'No features listed'); ?></td>
                                <td><span class="status-badge <?php echo $plan['is_active'] ? 'status-served' : 'status-pending'; ?>"><?php echo $plan['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td>
                                    <button onclick="editPlan(<?php echo $plan['id']; ?>, '<?php echo addslashes($plan['name']); ?>', <?php echo $plan['price']; ?>, <?php echo $plan['credits_limit']; ?>, '<?php echo addslashes($plan['features']); ?>')" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px; margin-right: 5px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this plan?')">
                                        <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" name="delete_plan" class="btn btn-primary" style="background: var(--danger-red); padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function editPlan(id, name, price, credits, features) {
            document.getElementById('plan_id').value = id;
            document.getElementById('name').value = name;
            document.getElementById('price').value = price;
            document.getElementById('credits').value = credits;
            document.getElementById('features').value = features;
            
            document.getElementById('formTitle').textContent = 'Edit Membership Plan';
            document.getElementById('submitText').textContent = 'Update Membership Plan';
            document.querySelector('button[name="add_plan"]').name = 'edit_plan';
            document.getElementById('cancelBtn').style.display = 'inline-block';
            
            // Scroll to form
            document.getElementById('planForm').scrollIntoView({ behavior: 'smooth' });
        }
        
        function cancelEdit() {
            document.getElementById('plan_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('price').value = '';
            document.getElementById('credits').value = '';
            document.getElementById('features').value = '';
            
            document.getElementById('formTitle').textContent = 'Add New Membership Plan';
            document.getElementById('submitText').textContent = 'Add Membership Plan';
            document.querySelector('button[name="edit_plan"]').name = 'add_plan';
            document.getElementById('cancelBtn').style.display = 'none';
        }
    </script>
</body>
</html>