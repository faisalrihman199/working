<?php
require_once 'includes/auth.php';

// If you have the same helper as forgot_password:
require_once __DIR__ . '/config/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// --------- Runtime OTP helpers (session only) ----------
function _gen_otp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
function _hash_otp(string $code): string {
    return password_hash($code, PASSWORD_DEFAULT);
}
function sendOTP(string $to, string $otp): bool {
    $subject = 'Your Zicbot Email Verification code';
    $html = '
      <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6">
        <h2 style="margin:0 0 10px">Email Verification code</h2>
        <p>Use the following one-time Email Verification code:</p>
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
function _send_register_otp_email(string $toEmail, string $code): bool {
    return sendOTP($toEmail, $code);
    
}
function _store_pending_registration(array $data, string $otpHash, int $expiresAt): void {
    $_SESSION['reg_pending'] = $data;
    $_SESSION['reg_otp_hash'] = $otpHash;
    $_SESSION['reg_otp_expires'] = $expiresAt;
    $_SESSION['reg_otp_attempts'] = 0;
    $_SESSION['reg_otp_last_sent'] = time();
}
function _clear_pending_registration(): void {
    unset(
        $_SESSION['reg_pending'],
        $_SESSION['reg_otp_hash'],
        $_SESSION['reg_otp_expires'],
        $_SESSION['reg_otp_attempts'],
        $_SESSION['reg_otp_last_sent']
    );
}

$otpPhase = isset($_SESSION['reg_pending']);

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;

    if (!$otpPhase && $action === 'send_otp') {
        // First submit: validate inputs, then generate/send OTP and hold data in session
        $name = trim($_POST['name'] ?? '');
        $restaurant_name = trim($_POST['restaurant_name'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($name) || empty($restaurant_name) || empty($country) || empty($phone) || empty($email) || empty($password)) {
            $error = 'Please fill in all fields';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long';
        } else {
            $code = _gen_otp();
            $hash = _hash_otp($code);
            $expiresAt = time() + 10 * 60; // 10 minutes

            _store_pending_registration([
                'name' => $name,
                'restaurant_name' => $restaurant_name,
                'country' => $country,
                'phone' => $phone,
                'email' => $email,
                'password' => $password,
            ], $hash, $expiresAt);

            $sent = _send_register_otp_email($email, $code);
            $otpPhase = true;

            if ($sent) {
                $success = 'A verification code has been sent to your email. Enter it below.';
            } else {
                // Dev-friendly: show the code if mailer returns false
                $success = 'Verification code (dev): ' . htmlspecialchars($code) . '. Enter it below.';
            }
        }

    } elseif ($otpPhase && $action === 'resend_otp') {
        // 60-second resend throttle
        $last = (int)($_SESSION['reg_otp_last_sent'] ?? 0);
        if (time() - $last < 60) {
            $error = 'Please wait a bit before requesting another code.';
        } else {
            $code = _gen_otp();
            $_SESSION['reg_otp_hash'] = _hash_otp($code);
            $_SESSION['reg_otp_expires'] = time() + 10 * 60;
            $_SESSION['reg_otp_attempts'] = 0;
            $_SESSION['reg_otp_last_sent'] = time();

            $email = $_SESSION['reg_pending']['email'] ?? '';
            $sent = _send_register_otp_email($email, $code);
            $otpPhase = true;
            $success = $sent ? 'A new verification code has been sent.' : 'New verification code (dev): ' . htmlspecialchars($code);
        }

    } elseif ($otpPhase && $action === 'verify_otp') {
        $inputCode = trim($_POST['otp'] ?? '');
        if ($inputCode === '') {
            $error = 'Please enter the code.';
        } elseif (!ctype_digit($inputCode) || strlen($inputCode) !== 6) {
            $error = 'Code must be 6 digits.';
        } else {
            $expires = (int)($_SESSION['reg_otp_expires'] ?? 0);
            $attempts = (int)($_SESSION['reg_otp_attempts'] ?? 0);
            $hash = $_SESSION['reg_otp_hash'] ?? '';

            if (time() > $expires) {
                $error = 'Code expired. Please resend a new code.';
            } elseif ($attempts >= 5) {
                $error = 'Too many attempts. Please resend a new code.';
            } elseif (!$hash || !password_verify($inputCode, $hash)) {
                $_SESSION['reg_otp_attempts'] = $attempts + 1;
                $error = 'Incorrect code.';
            } else {
                // OTP OK — perform real registration now
                $p = $_SESSION['reg_pending'];
                $result = $auth->register(
                    $p['name'],
                    $p['restaurant_name'],
                    $p['country'],
                    $p['phone'],
                    $p['email'],
                    $p['password']
                );
                if ($result['success']) {
                    _clear_pending_registration();
                    $otpPhase = false;
                    $success = ($result['message'] ?? 'Registration successful') . '. You can now login.';
                    // Optional redirect:
                    header("Refresh:3; url=index.php?registered=1"); exit();
                    $_POST = []; // clear inputs
                } else {
                    $error = $result['message'] ?? 'Could not complete registration.';
                    _clear_pending_registration();
                    $otpPhase = false; // back to full form so user can edit details
                }
            }
        }

    } elseif ($otpPhase && $action === 'start_over') {
        _clear_pending_registration();
        $otpPhase = false;
        $success = 'You can edit your details now.';
    }
}

// Prefill values for UI
$prefill = [
    'name' => '',
    'restaurant_name' => '',
    'country' => '',
    'phone' => '',
    'email' => '',
];
if ($otpPhase && !empty($_SESSION['reg_pending'])) {
    foreach ($prefill as $k => $_) $prefill[$k] = $_SESSION['reg_pending'][$k] ?? '';
} else {
    foreach ($prefill as $k => $_) $prefill[$k] = $_POST[$k] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zicbot - Restaurant Registration</title>
    <link rel="icon" href="/icon.png" type="image/x-icon">
    <link rel="stylesheet" href="./assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="logo">Zicbot</div>
                <p class="auth-subtitle">Join Our Restaurant Management System</p>
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
                <?php if (!$otpPhase): ?>
                    <div class="form-group">
                        <label for="name">Your Name</label>
                        <input type="text" id="name" name="name" class="form-control" required
                               value="<?php echo htmlspecialchars($prefill['name']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="restaurant_name">Restaurant Name</label>
                        <input type="text" id="restaurant_name" name="restaurant_name" class="form-control" required
                               value="<?php echo htmlspecialchars($prefill['restaurant_name']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" class="form-control" required
                               value="<?php echo htmlspecialchars($prefill['country']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" required
                               value="<?php echo htmlspecialchars($prefill['phone']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($prefill['email']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary" name="action" value="send_otp">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>

                <?php else: ?>
                    <div class="form-group">
                        <label>Your Email</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($prefill['email']); ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label for="otp">Verification Code</label>
                        <input type="text" id="otp" name="otp" class="form-control" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary" name="action" value="verify_otp">
                            <i class="fas fa-check"></i> Verify & Create Account
                        </button>
                        <button type="submit" class="btn btn-secondary" name="action" value="resend_otp" formnovalidate>
                            <i class="fas fa-paper-plane"></i> Resend code
                        </button>
                        <button type="submit" class="btn btn-link" name="action" value="start_over" formnovalidate>
                            Edit details
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <div class="auth-switch" style="margin-top:12px">
                Already have an account? <a href="index.php">Sign in here</a>
            </div>
        </div>
    </div>
</body>
</html>
