<?php
declare(strict_types=1);

/**
 * iHymns - magic-link login (text) (#898)
 *
 * Plain-text variant for the magic-link sign-in flow. Same vars as
 * the .html.php sibling. Corporate spam filters down-rank text-only
 * or HTML-only mail; we always send both.
 */
$SUBJECT = 'Your iHymns sign-in code';

$code         = isset($code)         ? (string)$code : '';
$link         = isset($link)         ? (string)$link : '';
$expiresInMin = isset($expiresInMin) ? (int)$expiresInMin : 10;
$displayName  = isset($displayName)  ? (string)$displayName : '';

$greeting = $displayName !== '' ? 'Hi ' . $displayName . ',' : 'Hi,';
?>
<?= $greeting ?>


Use the link below to sign in to iHymns. It expires in <?= (int)$expiresInMin ?> minutes and can only be used once.

<?= $link ?>


Or enter this code on the sign-in page:

  <?= $code ?>


If you weren't expecting this email, you can safely ignore it.

-- iHymns
