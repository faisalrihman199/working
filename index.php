<?php
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$inactive = false;
$inactiveMsg = 'Your account is inactive. Please contact the administrator to restore access.';
$supportEmail = 'support@yourdomain.com'; // ← set to your real support/admin email

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($email) && !empty($password)) {
        $result = $auth->login($email, $password);

        if (!empty($result['success'])) {
            header("Location: dashboard.php");
            exit();
        } else {
            // If Auth::login provided a status and user is inactive, show the modal
            if (isset($result['status']) && (int)$result['status'] !== 1) {
                $inactive = true;
                if (!empty($result['message'])) {
                    $inactiveMsg = $result['message'];
                }
            } else {
                $error = $result['message'] ?? 'Invalid email or password';
            }
        }
    } else {
        $error = 'Please fill in all fields';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zicbot - Login</title>
    <link rel="stylesheet" href="assets//css/style.css">
    <link rel="icon" href="/icon.png" type="image/x-icon">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Minimal modal styling to match your dark theme -->
    <style>
      :root{
        --accent:#2d2d2d;           /* neutral accent per your theme */
        --surface:#1f1f23;
        --surface-2:#15151a;
        --border:#2b2b31;
        --text:#e5e7eb;
        --muted:#a3a3a3;
      }
      .modal{
        position:fixed; inset:0; z-index:1000;
        display:none; align-items:center; justify-content:center;
        background: rgba(0,0,0,.65); backdrop-filter: blur(3px);
      }
      .modal.open{ display:flex; }
      .modal-card{
        width:min(520px, 92vw);
        background:var(--surface);
        border:1px solid var(--border);
        border-radius:18px;
        box-shadow: 0 24px 60px rgba(0,0,0,.5);
        overflow:hidden; animation:pop .14s ease-out;
      }
      @keyframes pop{ from{ transform:scale(.98); opacity:0 } to{ transform:scale(1); opacity:1 } }
      .modal-head{
        display:flex; align-items:center; gap:12px;
        padding:18px 20px; border-bottom:1px solid var(--border);
        background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(0,0,0,0));
      }
      .modal-title{ color:#fff; font-weight:700; letter-spacing:.3px; }
      .modal-body{ padding:18px 20px; color:var(--text); }
      .modal-body p{ margin:0 0 10px 0; color:var(--text); }
      .muted{ color:var(--muted); font-size:.95rem; }
      .modal-foot{
        display:flex; justify-content:flex-end; gap:10px;
        padding:14px 20px; border-top:1px solid var(--border);
        background: var(--surface-2);
      }
      .icon-circle{
        width:40px; height:40px; border-radius:50%;
        display:grid; place-items:center;
        background:#3b1f20; color:#fca5a5; border:1px solid #7f1d1d;
      }
      .btn-outline{
        background:transparent; color:var(--text);
        border:1px solid var(--border); padding:10px 14px; border-radius:10px;
      }
      .btn-outline:hover{ border-color:#3f3f3f; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="logo">Zicbot</div>
                <p class="auth-subtitle">AI Waiter For Restaurants</p>
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
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="auth-switch" style="display:flex; justify-content:end; margin-bottom:20px;">
                  <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
            
            <div class="auth-switch">
                Don't have an account? <a href="signup.php">Sign up here</a>
            </div>
        </div>
    </div>

    <!-- Inactive account modal -->
    <div id="inactiveModal" class="modal <?php echo $inactive ? 'open' : ''; ?>" aria-hidden="<?php echo $inactive ? 'false' : 'true'; ?>">
      <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="inactiveTitle">
        <div class="modal-head">
          <div class="icon-circle"><i class="fas fa-ban"></i></div>
          <div class="modal-title" id="inactiveTitle">Account Inactive</div>
        </div>
        <div class="modal-body">
          <p><?php echo htmlspecialchars($inactiveMsg); ?></p>
          <p class="muted">If you believe this is a mistake, please reach out to the administrator.</p>
        </div>
        <div class="modal-foot">
          <a href="mailto:<?php echo htmlspecialchars($supportEmail); ?>" class="btn btn-primary">
            <i class="fas fa-envelope"></i> Contact Admin
          </a>
          <button class="btn btn-outline" id="closeInactive">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
      </div>
    </div>

    <script>
      // Close modal handlers
      (function(){
        const modal = document.getElementById('inactiveModal');
        const closeBtn = document.getElementById('closeInactive');
        if (!modal) return;

        function closeModal(){
          modal.classList.remove('open');
          modal.setAttribute('aria-hidden','true');
        }
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e)=>{ if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
      })();
    </script>
</body>
</html>
