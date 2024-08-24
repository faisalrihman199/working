<?php
// config/email_templates.php
// Pretty, brand-consistent HTML + text emails for Stripe webhook events.

require_once __DIR__ . '/../env.php';
loadEnv(__DIR__ . '/../.env');

function email_brand(): array {
  // Tune these to taste
  return [
    'brand'   => $_ENV['APP_BRAND'] ?? 'Zicbot',
    'color'   => '#6b21ff',         // purple accent (matches your screenshot)
    'bg'      => '#0b1220',         // dark background
    'panel'   => '#10182a',         // card background
    'text'    => '#e5e7eb',         // body text
    'muted'   => '#9ca3af',         // muted text
    'border'  => 'rgba(255,255,255,.12)',
    'logo'    => $_ENV['APP_LOGO_URL'] ?? null, // optional absolute https://
    'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'), // e.g. https://app.example.com
  ];
}

function email_btn(string $url, string $label): string {
  $b = email_brand();
  $color = $b['color'];
  return '<a href="'.htmlspecialchars($url).'"
    style="display:inline-block;background:'.$color.';color:#ffffff;text-decoration:none;
           font-weight:700;border-radius:12px;padding:12px 18px;box-shadow:0 6px 18px rgba(107,33,255,.45);
           border:1px solid rgba(255,255,255,.08)">
    '.$label.'
  </a>';
}

function email_row(string $label, string $value): string {
  $b = email_brand();
  return '<tr>
    <td style="padding:8px 12px;border-bottom:1px solid '.$b['border'].';color:'.$b['muted'].';">'.$label.'</td>
    <td style="padding:8px 12px;border-bottom:1px solid '.$b['border'].';color:'.$b['text'].';text-align:right">'.$value.'</td>
  </tr>';
}

function email_wrap(string $title, string $bodyHtml, string $preheader=''): array {
  $b = email_brand();
  $brand  = $b['brand'];
  $bg     = $b['bg'];
  $panel  = $b['panel'];
  $text   = $b['text'];
  $muted  = $b['muted'];
  $border = $b['border'];

  // Prefer PNG/JPG for emails; if someone configured an SVG, try a .png twin
  $logo = $b['logo'] ?? null;
  if ($logo && preg_match('/\.svg(\?.*)?$/i', $logo)) {
    $logo = preg_replace('/\.svg(\?.*)?$/i', '.png$1', $logo);
  }
  // Normalize accidental double slashes (except after https://)
  if ($logo) {
    $logo = preg_replace('#(?<!:)//+#', '/', $logo);
  }

  $html = '
  <!doctype html><html><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>'.htmlspecialchars($title).'</title></head>
  <body style="margin:0;padding:0;background:'.$bg.';">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">'.$preheader.'</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:'.$bg.';">
      <tr><td align="center" style="padding:32px 12px">
        <table role="presentation" width="100%" style="max-width:640px;background:'.$panel.';
               border:1px solid '.$border.';border-radius:16px;box-shadow:0 22px 60px rgba(0,0,0,.35);">
          <tr>
            <td style="padding:20px 22px;border-bottom:1px solid '.$border.';">
              <table role="presentation" width="100%"><tr>
                <td style="font:700 18px/1.2 system-ui,Segoe UI,Roboto,Helvetica,Arial;color:'.$text.';">
                  '.htmlspecialchars($brand).'
                </td>
                <td align="right">
                  '.($logo ? '<img src="'.htmlspecialchars($logo).'" alt="'.htmlspecialchars($brand).'"
                       width="140" style="display:block;border:0;max-width:140px;height:auto">' : '').'
                </td>
              </tr></table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 22px 6px 22px;">
              <h1 style="margin:0 0 8px 0;color:#ffffff;font:800 22px/1.25 system-ui,Segoe UI,Roboto,Helvetica,Arial;">
                '.htmlspecialchars($title).'
              </h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 22px 22px 22px;color:'.$text.';font:400 15px/1.6 system-ui,Segoe UI,Roboto,Helvetica,Arial;">
              '.$bodyHtml.'
            </td>
          </tr>
          <tr>
            <td style="padding:14px 22px;border-top:1px solid '.$border.';color:'.$muted.';font:400 12px/1.6 system-ui,Segoe UI,Roboto,Helvetica,Arial;">
              Need help? Reply to this email and our team will jump in.
            </td>
          </tr>
        </table>
        <div style="color:'.$muted.';font:400 12px/1.6 system-ui,Segoe UI,Roboto,Helvetica,Arial;margin-top:12px;opacity:.8">
          © '.date('Y').' '.htmlspecialchars($brand).'
        </div>
      </td></tr>
    </table>
  </body></html>';

  $textFallback = strip_tags(str_replace(['<br>','<br/>','<br />','</p>','</li>'], ["\n","\n","\n","\n","\n"], $bodyHtml));
  return ['html' => $html, 'text' => $textFallback];
}


/* ---------- plan feature chips (to echo your screenshot vibe) ---------- */
function plan_features_by_name(?string $planName): array {
  $name = strtolower(trim($planName ?? ''));
  if ($name === 'basic') {
    return ['15,000 message credits', 'AI Training', 'Basic order management', 'Instant order notifications', 'Email support'];
  } elseif ($name === 'growth') {
    return ['30,000 message credits', 'Everything in Basic', 'Payment gateway integration', 'Fast support'];
  } elseif ($name === 'brand' || $name === 'enterprise') {
    return ['60,000 message credits', 'Everything in Growth', 'Multi-device support', 'Custom chat branding', 'Priority support'];
  }
  return [];
}

function plan_feature_list_html(array $features): string {
  $b = email_brand();
  $chips = array_map(function($f) use ($b) {
    return '<span style="display:inline-block;background:rgba(255,255,255,.06);border:1px solid '.$b['border'].';color:'.$b['text'].';padding:8px 12px;border-radius:999px;margin:6px 8px 0 0;font-size:13px">✓ '.$f.'</span>';
  }, $features);
  return '<div>'.implode('', $chips).'</div>';
}

/* ===================== TEMPLATES ===================== */

function tmpl_subscription_started(string $userName, ?string $planName, string $manageUrl): array {
  $title = 'Your subscription is active';
  $features = plan_features_by_name($planName);
  $body = '
    <p>Hi <strong>'.htmlspecialchars($userName ?: 'there').'</strong>,</p>
    <p>Welcome aboard! Your <strong>'.htmlspecialchars($planName ?: 'subscription').'</strong> is now active.</p>'.
    ($features ? ('<p style="margin:14px 0 6px 0;color:#cbd5e1">What you get:</p>'.plan_feature_list_html($features)) : '').
    '<p style="margin-top:18px">'.email_btn($manageUrl, 'Manage billing').'</p>';
  return ['subject' => 'Subscription started', ...email_wrap($title, $body, 'Your subscription is now active.')];
}

function tmpl_payment_received(string $userName, string $amount, ?string $invoiceId, string $manageUrl): array {
  $title = 'Payment received';
  $rows  = '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:8px;border-collapse:collapse">'.email_row('Amount', htmlspecialchars($amount)).($invoiceId ? email_row('Invoice', htmlspecialchars($invoiceId)) : '').'</table>';
  $body  = '
    <p>Hi <strong>'.htmlspecialchars($userName ?: 'there').'</strong>,</p>
    <p>Thanks! We successfully processed your payment.</p>'.
    $rows.
    '<p style="margin-top:18px">'.email_btn($manageUrl, 'View invoice in portal').'</p>';
  return ['subject' => 'Payment received', ...email_wrap($title, $body, 'Thanks—your payment was received.')];
}

function tmpl_subscription_updated(string $userName, ?string $oldPlan, ?string $newPlan, string $manageUrl): array {
  $title = 'Subscription updated';
  $features = plan_features_by_name($newPlan);
  $body = '
    <p>Hi <strong>'.htmlspecialchars($userName ?: 'there').'</strong>,</p>
    <p>Your subscription was updated from <strong>'.htmlspecialchars($oldPlan ?: 'previous').'</strong> to
       <strong>'.htmlspecialchars($newPlan ?: 'new').'</strong>.</p>'.
    ($features ? ('<p style="margin:14px 0 6px 0;color:#cbd5e1">Highlights:</p>'.plan_feature_list_html($features)) : '').
    '<p style="margin-top:18px">'.email_btn($manageUrl, 'Manage billing').'</p>';
  return ['subject' => 'Subscription updated', ...email_wrap($title, $body, 'Your plan has changed.')];
}

function tmpl_cancellation_scheduled(string $userName, string $manageUrl): array {
  $title = 'Subscription will cancel at period end';
  $body  = '
    <p>Hi <strong>'.htmlspecialchars($userName ?: 'there').'</strong>,</p>
    <p>Your subscription is set to <strong>cancel at the end of this billing period</strong>.</p>
    <p>If this wasn’t intended, you can resume anytime.</p>
    <p style="margin-top:18px">'.email_btn($manageUrl, 'Resume or manage billing').'</p>';
  return ['subject' => 'Subscription scheduled to cancel', ...email_wrap($title, $body, 'Your subscription will cancel at period end.')];
}

function tmpl_subscription_cancelled(string $userName, string $manageUrl): array {
  $title = 'Subscription cancelled';
  $body  = '
    <p>Hi <strong>'.htmlspecialchars($userName ?: 'there').'</strong>,</p>
    <p>Your subscription has been <strong>cancelled</strong>. We’d love to have you back anytime.</p>
    <p style="margin-top:18px">'.email_btn($manageUrl, 'Restart subscription').'</p>';
  return ['subject' => 'Subscription cancelled', ...email_wrap($title, $body, 'Your subscription has been cancelled.')];
}

/* Convenience: derive a safe Manage Billing URL (your billing page opens the portal) */
function manage_billing_url(): string {
  $b = email_brand();
  if (!empty($b['app_url'])) return $b['app_url'].'/billing.php';
  return '#'; // fallback – still renders the button
}
