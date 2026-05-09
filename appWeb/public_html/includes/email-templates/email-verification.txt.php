<?php
declare(strict_types=1);

/**
 * iHymns - email verification (text) (#898)
 */
$SUBJECT = 'Verify your iHymns email address';

$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 1440;
$displayName  = isset($displayName)  ? (string)$displayName : '';

$expiresHuman = $expiresInMin >= 60
    ? (intdiv($expiresInMin, 60) . ' hour' . (intdiv($expiresInMin, 60) === 1 ? '' : 's'))
    : ($expiresInMin . ' minute' . ($expiresInMin === 1 ? '' : 's'));

$greeting = $displayName !== '' ? 'Hi ' . $displayName . ',' : 'Hi,';
?>
<?= $greeting ?>


Thanks for joining iHymns. Please confirm this is your email address by following the link below (expires in <?= $expiresHuman ?>):

<?= $link ?>


If you didn't sign up, you can ignore this email - no account will be created without confirmation.

-- iHymns
