<?php
declare(strict_types=1);

/**
 * iHymns - Shared HTML wrapper for transactional emails (#898)
 *
 * Each <name>.html.php template builds its message body in a $bodyHtml
 * string then includes this wrapper to get a consistent header, footer,
 * and CAN-SPAM compliance line. Plain-text variants don't use this
 * (one-off block of \n-separated lines is simpler).
 *
 * Inputs (set by caller before include):
 *   $previewText  : single-line preheader shown in inbox previews
 *   $bodyHtml     : the message body (already-escaped HTML)
 *   $reasonLine   : one short sentence explaining why this email was sent
 *
 * Outputs: HTML5 document echoed inline.
 */

/** @var string $previewText */
/** @var string $bodyHtml */
/** @var string $reasonLine */
$previewText = isset($previewText) ? (string)$previewText : '';
$bodyHtml    = isset($bodyHtml)    ? (string)$bodyHtml    : '';
$reasonLine  = isset($reasonLine)  ? (string)$reasonLine  : 'You received this email from iHymns.';
$year        = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>iHymns</title>
<style>
  body { margin:0; padding:0; background:#f6f7f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1c1f24; }
  .wrap { max-width:560px; margin:0 auto; padding:24px 16px; }
  .card { background:#ffffff; border:1px solid #e1e4e8; border-radius:8px; padding:28px 24px; }
  h1 { font-size:20px; margin:0 0 12px 0; color:#0d1117; }
  p  { font-size:15px; line-height:1.5; margin:0 0 14px 0; }
  .btn { display:inline-block; background:#0d6efd; color:#ffffff !important; text-decoration:none; padding:11px 20px; border-radius:6px; font-weight:600; }
  .code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:22px; letter-spacing:4px; background:#f1f3f5; padding:10px 14px; border-radius:6px; display:inline-block; }
  .muted { color:#6a737d; font-size:12px; line-height:1.5; }
  .footer { margin-top:18px; padding-top:14px; border-top:1px solid #e1e4e8; }
  a { color:#0d6efd; }
</style>
</head>
<body>
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;"><?= htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8') ?></div>
<div class="wrap">
  <div class="card">
    <p style="margin-bottom:18px; font-weight:600; color:#0d6efd;">iHymns</p>
    <?= $bodyHtml ?>
    <div class="footer muted">
      <p style="margin:0 0 6px 0;"><?= htmlspecialchars($reasonLine, ENT_QUOTES, 'UTF-8') ?></p>
      <p style="margin:0;">&copy; <?= $year ?> iHymns. If you weren't expecting this email, you can safely ignore it.</p>
    </div>
  </div>
</div>
</body>
</html>
