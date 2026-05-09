<?php
declare(strict_types=1);

/**
 * iHymns - magic-link login (HTML) (#898)
 *
 * Required vars:
 *   string $code         6-digit numeric code
 *   string $link         full https URL of the magic link
 *   int    $expiresInMin minutes until expiry
 * Optional:
 *   string $displayName
 */
$SUBJECT = 'Your iHymns sign-in code';

$code         = isset($code)         ? (string)$code : '';
$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 10;
$displayName  = isset($displayName)  ? (string)$displayName : '';

$greeting = $displayName !== ''
    ? 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ','
    : 'Hi,';

ob_start();
?>
<h1>Sign in to iHymns</h1>
<p><?= $greeting ?></p>
<p>Use the button below to sign in. The link expires in <?= (int)$expiresInMin ?> minutes and can only be used once.</p>
<p style="margin:20px 0;">
  <a class="btn" href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">Sign in to iHymns</a>
</p>
<p>Or enter this code on the sign-in page:</p>
<p><span class="code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span></p>
<p class="muted">If the button doesn't work, copy and paste this URL into your browser:<br>
<a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?></a></p>
<?php
$bodyHtml    = (string)ob_get_clean();
$previewText = 'Your iHymns sign-in code (expires in ' . (int)$expiresInMin . ' minutes).';
$reasonLine  = 'You received this email because someone requested a sign-in link for this address on iHymns.';
include __DIR__ . DIRECTORY_SEPARATOR . '_layout.php';
