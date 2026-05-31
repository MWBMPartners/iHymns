<?php
declare(strict_types=1);

/**
 * iHymns - email verification (HTML) (#898)
 *
 * Sent after password-based registration when an email address is
 * supplied. Confirming the link flips tblUsers.EmailVerified 0 -> 1.
 *
 * Required vars:
 *   string $link         full https URL with ?token=...
 *   int    $expiresInMin minutes until expiry (typically 24*60 = 1440)
 * Optional:
 *   string $displayName
 */
$SUBJECT = 'Verify your iHymns email address';

$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 1440;
$displayName  = isset($displayName)  ? (string)$displayName : '';

$expiresHuman = $expiresInMin >= 60
    ? (intdiv($expiresInMin, 60) . ' hour' . (intdiv($expiresInMin, 60) === 1 ? '' : 's'))
    : ($expiresInMin . ' minute' . ($expiresInMin === 1 ? '' : 's'));

$greeting = $displayName !== ''
    ? 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ','
    : 'Hi,';

ob_start();
?>
<h1>Verify your email address</h1>
<p><?= $greeting ?></p>
<p>Thanks for joining iHymns. Please confirm this is your email address - the link expires in <?= htmlspecialchars($expiresHuman, ENT_QUOTES, 'UTF-8') ?>.</p>
<p style="margin:20px 0;">
  <a class="btn" href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">Verify email address</a>
</p>
<p class="muted">If the button doesn't work, copy and paste this URL into your browser:<br>
<a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?></a></p>
<p class="muted">Until you verify, some account features stay limited. If you didn't sign up, you can ignore this email - no account will be created without confirmation.</p>
<?php
$bodyHtml    = (string)ob_get_clean();
$previewText = 'Confirm your email address to finish setting up your iHymns account.';
$reasonLine  = 'You received this email because someone signed up for an iHymns account using this address.';
include __DIR__ . DIRECTORY_SEPARATOR . '_layout.php';
