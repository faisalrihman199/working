<?php
require_once __DIR__ . '/config/mailer.php';
$ok = sendEmailSMTP('faisalrihman199@gmail.com', 'SMTP test', '<b>Hello from Zicbot</b>', 'Hello from Zicbot');
echo $ok ? 'Mail sent!' : 'Mail failed (check error_log)';
