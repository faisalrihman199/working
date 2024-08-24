<?php
// forgot_password.php
session_start();
require_once __DIR__ . '/config/database.php';

// (Optional) email helper – add SMTP later; return false to show OTP in dev
require_once __DIR__ . '/config/mailer.php';
function sendOtpMailIfConfigured(string $to, string $otp): bool {
    $subject = 'Your Zicbot password reset code';
    $html = '
      <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6">
        <h2 style="margin:0 0 10px">Password reset verification</h2>
        <p>Use the following one-time code to reset your password:</p>
        <div style="font-size:22px;font-weight:700;letter-spacing:3px;
                    background:#f3f4f6;border:1px dashed #d1d5db;padding:12px 16px;
                    display:inline-block;">
          ' . htmlspecialchars($otp) . '
        </div>
        <p style="margin-top:12px">This code expires in 10 minutes.</p>
        <p style="color:#6b7280;font-size:12px">If you did not request this, you can safely ignore this email.</p>
      </div>';
    $text = "Your password reset code is: {$otp}\n\nThis code expires in 10 minutes.";
    return sendEmailSMTP($to, $subject, $html, $text);
}


function normalize_email(string $e): string {
    return strtolower(trim($e));
}

$db = (new Database())->getConnection();

// Ensure OTP table exists
$db->exec("
  CREATE TABLE IF NOT EXISTS password_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email),
    UNIQUE KEY uniq_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$phase       = $_SESSION['otp_phase']  ?? 'request'; // 'request' | 'verify' | 'done'
$phase_email = $_SESSION['otp_email']  ?? '';
$dev_otp     = ''; // dev-only display
$error       = '';
$success     = '';
$cooldownSec = 60;
$lastSentAt  = null; // for JS cooldown

/**
 * Helper: generate + store OTP for email (upsert), returns ['sent'=>bool,'otp'=>string(for dev), 'last_sent_at'=>string]
 */
function generateAndSendOtp(PDO $db, string $email): array {
    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + 10 * 60); // 10 minutes
    $now     = date('Y-m-d H:i:s');

    // Upsert (remove older OTPs)
    $db->prepare("DELETE FROM password_otps WHERE email = :email")->execute([':email' => $email]);
    $ins = $db->prepare("
        INSERT INTO password_otps (email, otp_hash, expires_at, attempts, last_sent_at)
        VALUES (:email, :hash, :exp, 0, :now)
    ");
    $ins->execute([':email' => $email, ':hash' => $otpHash, ':exp' => $expires, ':now' => $now]);

    $sent = sendOtpMailIfConfigured($email, $otp);
    return ['sent' => $sent, 'otp' => $otp, 'last_sent_at' => $now];
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: request OTP
    if ($action === 'request_otp') {
        $email = normalize_email($_POST['email'] ?? '');
        if ($email === '') {
            $error = 'Please enter your email address.';
        } else {
            $res = generateAndSendOtp($db, $email);
            if ($res['sent']) {
                $success = 'We sent a 6-digit OTP to your email. Enter it below with your new password.';
            } else {
                $success = 'Dev mode: OTP generated. Enter it below with your new password.';
                $dev_otp = $res['otp']; // show on page for dev
            }
            $_SESSION['otp_phase'] = 'verify';
            $_SESSION['otp_email'] = $email;
            $phase       = 'verify';
            $phase_email = $email;
            $lastSentAt  = $res['last_sent_at'];
        }
    }

    // Resend OTP (with cooldown)
    if ($action === 'resend_otp') {
        $email = normalize_email($_POST['email'] ?? '');
        if (!$email) {
            $error = 'Missing email. Please start over.';
            $phase = 'request';
            unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
        } else {
            // Check cooldown (60s)
            $stmt = $db->prepare("SELECT last_sent_at FROM password_otps WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $canSend = true;
            if ($row && !empty($row['last_sent_at'])) {
                $last = strtotime($row['last_sent_at']);
                if ($last && (time() - $last) < $cooldownSec) {
                    $canSend = false;
                    $remain  = $cooldownSec - (time() - $last);
                    $error   = "Please wait {$remain}s before resending OTP.";
                    $lastSentAt = $row['last_sent_at'];
                }
            }
            if ($canSend) {
                $res = generateAndSendOtp($db, $email);
                if ($res['sent']) {
                    $success = 'OTP resent to your email.';
                } else {
                    $success = 'Dev mode: OTP resent (shown below).';
                    $dev_otp = $res['otp'];
                }
                $lastSentAt = $res['last_sent_at'];
            }
            $phase = 'verify';
            $phase_email = $email;
            $_SESSION['otp_phase'] = 'verify';
            $_SESSION['otp_email'] = $email;
        }
    }

    // Change email (go back to request phase)
    if ($action === 'change_email') {
        $phase = 'request';
        $phase_email = '';
        unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
        $success = '';
        $error = '';
    }

    // Step 2: verify OTP and reset password
    if ($action === 'verify_reset') {
        $email = normalize_email($_POST['email'] ?? '');
        $otp   = trim($_POST['otp'] ?? ''); // keep as string (leading zeros!)
        $pwd   = $_POST['password'] ?? '';
        $pwd2  = $_POST['confirm_password'] ?? '';

        if ($email === '' || $otp === '') {
            $error = 'Please enter the OTP sent to your email.';
            $phase = 'verify';
            $phase_email = $email;
        } elseif (strlen($pwd) < 6) {
            $error = 'Password must be at least 6 characters.';
            $phase = 'verify';
            $phase_email = $email;
        } elseif ($pwd !== $pwd2) {
            $error = 'Passwords do not match.';
            $phase = 'verify';
            $phase_email = $email;
        } else {
            $stmt = $db->prepare("SELECT * FROM password_otps WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $error = 'No OTP request found for this email. Please request a new OTP.';
                $phase = 'request';
                unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
            } else {
                if (strtotime($row['expires_at']) <= time()) {
                    $db->prepare("DELETE FROM password_otps WHERE email = :email")->execute([':email' => $email]);
                    $error = 'This OTP expired. Please request a new one.';
                    $phase = 'request';
                    unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
                } elseif ((int)$row['attempts'] >= 5) {
                    $db->prepare("DELETE FROM password_otps WHERE email = :email")->execute([':email' => $email]);
                    $error = 'Too many attempts. Please request a new OTP.';
                    $phase = 'request';
                    unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
                } else {
                    if (!password_verify($otp, $row['otp_hash'])) {
                        $db->prepare("UPDATE password_otps SET attempts = attempts + 1 WHERE email = :email")
                           ->execute([':email' => $email]);
                        $error = 'Incorrect OTP. Please try again.';
                        $phase = 'verify';
                        $phase_email = $email;
                        $lastSentAt = $row['last_sent_at'] ?? null;
                    } else {
                        // OTP ok → update password
                        $hash = password_hash($pwd, PASSWORD_DEFAULT);
                        $upd = $db->prepare("UPDATE users SET password = :p WHERE email = :e LIMIT 1");
                        $upd->execute([':p' => $hash, ':e' => $email]);

                        // clear OTP
                        $db->prepare("DELETE FROM password_otps WHERE email = :email")->execute([':email' => $email]);

                        $success = 'Your password has been updated. Redirecting to login…';
                        $phase = 'done';
                        unset($_SESSION['otp_phase'], $_SESSION['otp_email']);
                    }
                }
            }
        }
    }
}

// If we’re in verify phase and last_sent_at not set from POST, hydrate it for cooldown JS
if ($phase === 'verify' && !$lastSentAt && $phase_email) {
    $stmt = $db->prepare("SELECT last_sent_at FROM password_otps WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => normalize_email($phase_email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $lastSentAt = $row['last_sent_at'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Reset Password (OTP) - Zicbot</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" href="/icon.png" type="image/x-icon">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    .inline-actions { display:flex; gap:14px; align-items:center; margin-top:12px; flex-wrap: wrap; }
    .email-pill {
      display:inline-flex; align-items:center; gap:8px;
      background: #f3f4f6; border:1px solid #e5e7eb; padding:8px 12px; border-radius: 999px;
      font-size: .95rem;
    }
    .btn.linklike { background: transparent; color: var(--primary, #1f6feb); border: none; padding: 0; cursor: pointer; }
    .btn.linklike:hover { text-decoration: underline; }
    .muted { color: var(--text-secondary, #6b7280); font-size: .9rem; }
    .row-between { display:flex; justify-content:space-between; align-items:center; gap:10px; margin: 6px 0 12px; }
    .cooldown { font-size: .9rem; color: #6b7280; }
    .btn-ghost { background: #fff; border: 1px solid #e5e7eb; color:#111827; padding:8px 12px; border-radius:8px; cursor:pointer; }
    .btn-ghost:disabled { opacity:.6; cursor:not-allowed; }
  </style>
</head>
<body>
  <div class="auth-container">
    <div class="auth-card">
      <div class="auth-header">
        <div class="logo">Zicbot</div>
        <p class="auth-subtitle">Reset your password with OTP</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success && $phase !== 'done'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>

      <?php if ($phase === 'request'): ?>
        <!-- STEP 1: ask email to send OTP -->
        <form method="POST" action="">
          <input type="hidden" name="action" value="request_otp">
          <div class="form-group">
            <label for="email">Your email</label>
            <input type="email" id="email" name="email" class="form-control" required
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-paper-plane"></i> Send OTP
          </button>
        </form>

      <?php elseif ($phase === 'verify'): ?>
        <!-- HEADER: email pill + actions (NOT nested in the main form) -->
        <div class="row-between">
          

          <div class="" style="display: flex;">
            <!-- Change email -->
            <form method="POST" action="" style="margin-right: 50px;">
              <input type="hidden" name="action" value="change_email">
              <button type="submit" class="btn-ghost"><i class="fas fa-edit"></i> Change email</button>
            </form>

            <!-- Resend OTP -->
            <form method="POST" action="" id="resendForm">
              <input type="hidden" name="action" value="resend_otp">
              <input type="hidden" name="email" value="<?php echo htmlspecialchars($phase_email); ?>">
              <button type="submit" class="btn-ghost" id="resendBtn"><i class="fas fa-sync-alt"></i> Resend OTP</button>
              <span class="cooldown" id="resendCooldown" style="display:none;"></span>
            </form>
          </div>
        </div>

        <?php if ($dev_otp): ?>
          <div class="alert" style="word-break: break-all; background:#f3f4f6; border:1px dashed #ddd;">
            <strong>Dev OTP:</strong> <?php echo htmlspecialchars($dev_otp); ?>
          </div>
        <?php endif; ?>

        <!-- STEP 2: OTP + new password -->
        <form method="POST" action="">
          <input type="hidden" name="action" value="verify_reset">
          <input type="hidden" name="email" value="<?php echo htmlspecialchars($phase_email); ?>">

          <div class="form-group">
            <label for="otp">6-digit OTP sent to <strong><?php echo htmlspecialchars($phase_email); ?></strong></label>
            <input type="text" id="otp" name="otp" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="e.g. 012345">
          </div>

          <div class="form-group">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" class="form-control" minlength="6" required>
          </div>

          <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6" required>
          </div>

          <button type="submit" class="btn btn-primary"><i class="fas fa-unlock"></i> Reset password</button>
          <div class="muted" style="margin-top:8px;">Tip: Check your spam folder if you don’t see the email.</div>
        </form>

        <script>
          // Resend cooldown UI
          (function(){
            const btn   = document.getElementById('resendBtn');
            const cdEl  = document.getElementById('resendCooldown');
            const last  = <?php echo $lastSentAt ? 'new Date("'.addslashes($lastSentAt).'").getTime()' : 'null'; ?>;
            const cool  = <?php echo (int)$cooldownSec; ?> * 1000;

            function update(){
              if (!last) return; // no cooldown known
              const now = Date.now();
              const remain = (last + cool) - now;
              if (remain > 0) {
                btn.disabled = true;
                cdEl.style.display = 'inline';
                cdEl.textContent = 'Resend available in ' + Math.ceil(remain/1000) + 's';
                requestAnimationFrame(() => setTimeout(update, 250));
              } else {
                btn.disabled = false;
                cdEl.style.display = 'none';
              }
            }
            update();
          })();
        </script>

      <?php elseif ($phase === 'done'): ?>
        <div class="alert alert-success" id="reset-success">
          <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success ?: 'Password changed.'); ?>
        </div>
        <div class="auth-switch" style="margin-top:12px;">
          <a class="btn btn-primary" href="index.php"><i class="fas fa-sign-in-alt"></i> Go to Sign in</a>
        </div>
        <script>
          // Toast-ish and auto-redirect after ~2.2s
          setTimeout(function(){ window.location.href = 'index.php'; }, 200);
        </script>
      <?php endif; ?>

      <div class="auth-switch" style="margin-top: 12px;">
        <a href="index.php"><i class="fas fa-arrow-left"></i> Back to sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
