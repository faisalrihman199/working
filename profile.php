<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$db = (new Database())->getConnection();
$userId = (int)$_SESSION['user_id'];

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim($_POST['name'] ?? '');
    $restaurant_name = trim($_POST['restaurant_name'] ?? '');
    $country         = trim($_POST['country'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $current_password= $_POST['current_password'] ?? '';
    $new_password    = $_POST['new_password'] ?? '';
    $confirm_password= $_POST['confirm_password'] ?? '';

    if ($name === '' || $restaurant_name === '' || $country === '' || $phone === '' || $email === '') {
        $error = 'Please fill in all required fields';
    } else {
        try {
            // Check if email is already taken by another user
            $emailQuery = "SELECT id FROM users WHERE email = :email AND id != :user_id LIMIT 1";
            $emailStmt = $db->prepare($emailQuery);
            $emailStmt->bindValue(':email', $email);
            $emailStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $emailStmt->execute();

            if ($emailStmt->rowCount() > 0) {
                $error = 'Email is already taken by another user';
            } else {
                // Update basic info
                $updateQuery = "
                    UPDATE users
                    SET name = :name,
                        restaurant_name = :restaurant_name,
                        country = :country,
                        phone = :phone,
                        email = :email
                    WHERE id = :user_id
                    LIMIT 1
                ";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindValue(':name', $name);
                $updateStmt->bindValue(':restaurant_name', $restaurant_name);
                $updateStmt->bindValue(':country', $country);
                $updateStmt->bindValue(':phone', $phone);
                $updateStmt->bindValue(':email', $email);
                $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

                if ($updateStmt->execute()) {
                    // Update session values used around the app
                    $_SESSION['user_name'] = $name;
                    $_SESSION['restaurant_name'] = $restaurant_name;

                    // Optional password change
                    if ($current_password !== '' || $new_password !== '' || $confirm_password !== '') {
                        if ($new_password !== $confirm_password) {
                            $error = 'New passwords do not match';
                        } elseif (strlen($new_password) < 6) {
                            $error = 'New password must be at least 6 characters long';
                        } else {
                            // Verify current password
                            $passQuery = "SELECT password FROM users WHERE id = :user_id LIMIT 1";
                            $passStmt  = $db->prepare($passQuery);
                            $passStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                            $passStmt->execute();
                            $row = $passStmt->fetch(PDO::FETCH_ASSOC);

                            if ($row && password_verify($current_password, $row['password'])) {
                                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                                $passUpdate = $db->prepare("UPDATE users SET password = :p WHERE id = :uid LIMIT 1");
                                $passUpdate->bindValue(':p', $hashed);
                                $passUpdate->bindValue(':uid', $userId, PDO::PARAM_INT);
                                $passUpdate->execute();
                                if (!$error) $success = 'Profile and password updated successfully';
                            } else {
                                $error = 'Current password is incorrect';
                            }
                        }
                    }

                    if (!$error && $success === '') {
                        $success = 'Profile updated successfully';
                    }
                } else {
                    $error = 'Failed to update profile';
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error occurred';
        }
    }
}

// Get current user data (+ optional plan name)
$user = [];
try {
    $userStmt = $db->prepare("
        SELECT u.*, mp.name AS plan_name
        FROM users u
        LEFT JOIN membership_plans mp ON mp.id = u.membership_plan
        WHERE u.id = :user_id
        LIMIT 1
    ");
    $userStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $userStmt->execute();
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $user = [];
}

// Safe getters
$u_name            = htmlspecialchars($user['name'] ?? '');
$u_restaurant_name = htmlspecialchars($user['restaurant_name'] ?? ($_SESSION['restaurant_name'] ?? ''));
$u_country         = htmlspecialchars($user['country'] ?? '');
$u_phone           = htmlspecialchars($user['phone'] ?? '');
$u_email           = htmlspecialchars($user['email'] ?? '');
$u_created_at      = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : '—';
$u_plan_name       = htmlspecialchars($user['plan_name'] ?? ($user['membership_plan'] ?? 'Free'));
$u_membership_exp  = !empty($user['membership_expires'] ?? null)
                    ? date('F j, Y', strtotime($user['membership_expires']))
                    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profile - Zicbot</title>
  <link rel="icon" href="/icon.png" type="image/x-icon">
    <link rel="stylesheet" href="./assets//css//style.css" />

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    /* Hamburger like your other pages */
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
    .sidebar.mobile-open { transform: translateX(0); }
  </style>
</head>
<body>
  <!-- Mobile Overlay -->
  <div class="mobile-overlay" id="mobileOverlay"></div>

  <div class="dashboard">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <img src="./assets//images/zicbot_logo.svg" alt="Zicbot">
        </div>
        <div class="sidebar-subtitle"><?php echo htmlspecialchars($_SESSION['restaurant_name'] ?? ''); ?></div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="memberships.php" class="nav-link"><i class="fas fa-crown"></i> Memberships</a></li>
          <li class="nav-item"><a href="billing.php" class="nav-link "><i class="fas fa-credit-card"></i> Billing</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link"><i class="fas fa-coins"></i> Credits</a></li>
          <li class="nav-item"><a href="profile.php" class="nav-link active"><i class="fas fa-user"></i> Profile</a></li>
          <li class="nav-item"><a href="#" class="nav-link" onclick="contactSupport()"><i class="fas fa-question-circle"></i> Help</a></li>
          <li class="nav-item" style="margin-top: 20px;">
            <a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
          </li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <button class="hamburger" id="hamburgerBtn" onclick="toggleMobileMenu()">
          <i class="fas fa-bars"></i>
        </button>
        <h1 class="page-title">Profile Settings</h1>
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
          <h2 class="card-title">Personal Information</h2>
        </div>

        <form method="POST" action="">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div class="form-group">
              <label for="name">Your Name</label>
              <input type="text" id="name" name="name" class="form-control" required value="<?php echo $u_name; ?>">
            </div>
            <div class="form-group">
              <label for="restaurant_name">Restaurant Name</label>
              <input type="text" id="restaurant_name" name="restaurant_name" class="form-control" required value="<?php echo $u_restaurant_name; ?>">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div class="form-group">
              <label for="country">Country</label>
              <input type="text" id="country" name="country" class="form-control" required value="<?php echo $u_country; ?>">
            </div>
            <div class="form-group">
              <label for="phone">Phone Number</label>
              <input type="tel" id="phone" name="phone" class="form-control" required value="<?php echo $u_phone; ?>">
            </div>
          </div>

          <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control" required value="<?php echo $u_email; ?>">
          </div>

          <hr style="border: 1px solid var(--border-dark); margin: 30px 0;">

          <h3 style="color: var(--text-light); margin-bottom: 20px;">Change Password (Optional)</h3>

          <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" default="" autocomplete="new-password" name="current_password" class="form-control">
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <div class="form-group">
              <label for="new_password">New Password</label>
              <input type="password" id="new_password" name="new_password" class="form-control">
            </div>
            <div class="form-group">
              <label for="confirm_password">Confirm New Password</label>
              <input type="password" id="confirm_password" name="confirm_password" class="form-control">
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top:14px;">
            <i class="fas fa-save"></i> Update Profile
          </button>
        </form>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 class="card-title">Account Information</h2>
        </div>
        <div style="color: var(--text-secondary); line-height: 1.8;">
          <p><strong>Account Status:</strong> <span style="color: var(--success-green);">Active</span></p>
          <p><strong>Membership Plan:</strong> <span style="color: var(--accent-orange);"><?php echo $u_plan_name ? htmlspecialchars(ucfirst($u_plan_name)) : 'Free'; ?></span></p>
          <p><strong>Member Since:</strong> <?php echo $u_created_at; ?></p>
          <?php if ($u_membership_exp): ?>
            <p><strong>Membership Expires:</strong> <?php echo $u_membership_exp; ?></p>
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

    // Close on overlay click
    document.getElementById('mobileOverlay').addEventListener('click', toggleMobileMenu);

    // Close on nav click (mobile)
    document.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', function () {
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

    // ESC to close
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
