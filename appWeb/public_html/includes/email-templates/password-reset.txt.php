<?php
declare(strict_types=1);

/**
 * iHymns - password reset (text) (#898)
 */
$SUBJECT = 'Reset your iHymns password';

$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 60;
$displayName  = isset($displayName)  ? (string)$displayName : '';
$username     = isset($username)     ? (string)$username : '';

$greeting = $displayName !== '' ? 'Hi ' . $displayName . ',' : 'Hi,';
?>
<?= $greeting ?>


We received a request to reset the password for your iHymns account<?= $username !== '' ? ' (' . $username . ')' : '' ?>.

The link below expires in <?= (int)$expiresInMin ?> minutes and can only be used once:

<?= $link ?>


If you didn't request this, no action is needed - your password is unchanged.

-- iHymns
