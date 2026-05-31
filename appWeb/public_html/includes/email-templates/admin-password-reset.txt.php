<?php
declare(strict_types=1);

/**
 * iHymns - admin-forced password reset notification (text) (#898)
 */
$SUBJECT = 'Your iHymns password was reset by an administrator';

$username     = isset($username)     ? (string)$username     : '';
$loginUrl     = isset($loginUrl)     ? (string)$loginUrl     : '';
$displayName  = isset($displayName)  ? (string)$displayName  : '';
$adminName    = isset($adminName)    ? (string)$adminName    : 'an iHymns administrator';

$greeting = $displayName !== '' ? 'Hi ' . $displayName . ',' : 'Hi,';
?>
<?= $greeting ?>


Your iHymns account<?= $username !== '' ? ' (' . $username . ')' : '' ?> had its password reset by <?= $adminName ?>.

For security, all your existing iHymns sessions were signed out. You'll need to sign in with the new password the administrator shared with you:

<?= $loginUrl ?>


Didn't expect this? Reply to your administrator immediately - someone may have access to your account they shouldn't.

-- iHymns
