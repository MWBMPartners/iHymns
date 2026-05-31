<?php
declare(strict_types=1);

/**
 * iHymns - password reset (HTML) (#898)
 *
 * Required vars:
 *   string $link         full https URL with ?token=...
 *   int    $expiresInMin minutes until expiry
 * Optional:
 *   string $displayName
 *   string $username     surfaced for users with multiple iHymns accounts
 */
$SUBJECT = 'Reset your iHymns password';

$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 60;
$displayName  = isset($displayName)  ? (string)$displayName : '';
$username     = isset($username)     ? (string)$username : '';

$greeting = $displayName !== ''
    ? 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ','
    : 'Hi,';

ob_start();
?>
<h1>Reset your iHymns password</h1>
<p><?= $greeting ?></p>
<p>We received a request to reset the password for your iHymns account<?= $username !== '' ? ' (<strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>)' : '' ?>.</p>
<p>The reset link below expires in <?= (int)$expiresInMin ?> minutes and can only be used once.</p>
<p style="margin:20px 0;">
  <a class="btn" href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">Reset password</a>
</p>
<p class="muted">If the button doesn't work, copy and paste this URL into your browser:<br>
<a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?></a></p>
<p class="muted">If you didn't request this, no action is needed - your password is unchanged.</p>
<?php
$bodyHtml    = (string)ob_get_clean();
$previewText = 'Reset link for your iHymns account (expires in ' . (int)$expiresInMin . ' minutes).';
$reasonLine  = 'You received this email because someone asked to reset the password for an iHymns account associated with this address.';
include __DIR__ . DIRECTORY_SEPARATOR . '_layout.php';
