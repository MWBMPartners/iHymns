<?php
declare(strict_types=1);

/**
 * iHymns - admin-forced password reset notification (HTML) (#898)
 *
 * Sent to the target user when an admin force-resets their password
 * via /manage/users.php or the admin_user_password_reset API. The
 * target needs to know it happened (security-critical), and which
 * admin to contact if it wasn't expected.
 *
 * Required vars:
 *   string $username     the target's username (so they recognise the account)
 *   string $loginUrl     full https URL to the login page
 * Optional:
 *   string $displayName  target's display name
 *   string $adminName    actor's display name (or username)
 */
$SUBJECT = 'Your iHymns password was reset by an administrator';

$username     = isset($username)     ? (string)$username     : '';
$loginUrl     = isset($loginUrl)     ? (string)$loginUrl     : '';
$displayName  = isset($displayName)  ? (string)$displayName  : '';
$adminName    = isset($adminName)    ? (string)$adminName    : 'an iHymns administrator';

$greeting = $displayName !== ''
    ? 'Hi ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ','
    : 'Hi,';

ob_start();
?>
<h1>Your iHymns password was reset</h1>
<p><?= $greeting ?></p>
<p>Your iHymns account<?= $username !== '' ? ' (<strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>)' : '' ?> had its password reset by <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
<p>For security, all your existing iHymns sessions were signed out. You'll need to sign in with the new password the administrator shared with you.</p>
<p style="margin:20px 0;">
  <a class="btn" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Sign in to iHymns</a>
</p>
<p class="muted"><strong>Didn't expect this?</strong> Reply to your administrator immediately - someone may have access to your account they shouldn't.</p>
<?php
$bodyHtml    = (string)ob_get_clean();
$previewText = 'An administrator reset the password on your iHymns account.';
$reasonLine  = 'You received this email because the password on your iHymns account was changed by an administrator.';
include __DIR__ . DIRECTORY_SEPARATOR . '_layout.php';
