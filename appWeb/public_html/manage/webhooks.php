<?php

declare(strict_types=1);

/**
 * iHymns — Admin: outbound partner-event webhooks (#1909)
 *
 * ELI5: this is the control panel where an admin tells iHymns "send a signed
 * HTTP message to this partner's URL whenever a song changes / a songbook
 * changes / a set-list is shared / a service starts or ends". It lists the
 * partners already registered, lets you create/edit one, prove the partner's
 * endpoint is really theirs (Verify), pause/resume delivery, roll or reveal
 * the signing secret, fire a one-off test message, and see (and retry) the
 * recent delivery attempts.
 *
 * DETAIL:
 * -------
 * This file is PURE UI + FORM HANDLING — it never touches SQL, curl, HMAC or
 * secret-encryption code directly. Every one of those concerns lives in
 * includes/webhook_admin.php (the CRUD core) and its two collaborators,
 * includes/webhooks.php (the delivery engine: dialer, sign, gates, drain) and
 * includes/webhook_events.php (the event vocabulary + payload builders) —
 * see the modularity rule in .claude/CLAUDE.md: "if a shared module already
 * exists, reuse it — do not duplicate." This page reads the arrays those
 * functions hand back and renders them; it never runs `$db->query(...)`
 * itself (rule from the task: "the core does the queries — the page reads
 * the arrays").
 *
 * Mutations are ordinary <form method="post"> submissions (no fetch/JSON) —
 * appropriate for a low-traffic admin CRUD surface — protected by
 * validateCsrfRequest() (rule #29) and skip the action entirely (with an
 * error flash) when the check fails, rather than a hard 403, so a stray
 * double-submit degrades to "nothing happened" instead of a scary error page.
 * Every mutation is best-effort activity-logged via logActivity() (guarded
 * with function_exists(), since not every install has it wired) using the
 * dotted `webhook.<verb>` action-key convention this project uses elsewhere
 * (song.soft_delete, apikey.revoke, …).
 *
 * Dormancy (design §9, mirrored from includes/webhooks.php's own doc-block):
 * this page works (full CRUD) even when the CHANNEL-LEVEL kill switch
 * (`webhooksEnabled()`) is off — subscriptions can be prepared ahead of an
 * admin flipping that switch on /manage/configuration — and it refuses to
 * touch the database at all when the SCHEMA-LEVEL gate (`webhookSchemaReady()`)
 * says the tables aren't migrated yet, exactly like the STRICT-safe pattern
 * CLAUDE.md rule #9 / #41 describe for every other un-migrated-install page.
 *
 * @see includes/webhook_admin.php   the CRUD core this page delegates to
 * @see includes/webhooks.php        the delivery engine (gates, dialer, drain)
 * @see includes/webhook_events.php  the event vocabulary + payload builders
 * @see manage/api-keys.php          the sibling admin page this one's skeleton mirrors
 * @see .claude/webhooks-1909-design.md §8.1 (admin surface)
 * @see .claude/CLAUDE.md rule #29 (validateCsrfRequest), #35 (status is the contract)
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhook_admin.php';
/* webhook_admin.php itself requires includes/webhooks.php + includes/webhook_events.php
   (webhooksEnabled(), webhookEventFamilies(), IHYMNS_WEBHOOK_EVENTS, …), so this page
   gets the whole contract from the one require above — never re-require the engine
   files directly (rule #22: one core, everyone delegates to it). */

/* ELI5: are you even logged in? WHY: every /manage/* page starts here — an
   anonymous visitor is bounced to the login form, never shown so much as a
   403 page (that would leak that the route exists). */
if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();

/* ELI5: are you allowed to manage webhooks? WHY: the page gate MUST be the
   EXACT SAME entitlement check the nav entry in admin-links.php advertises
   ('manage_webhooks') — CLAUDE.md's #1587 red flag is a page whose own gate
   differs from what its menu link promises, which lets a role either
   deep-link past the menu or click a menu item straight into a 403. There is
   deliberately no separate "request" entitlement here (unlike api-keys.php's
   two-tier manage/request split) — webhooks are a single-tier admin surface. */
$canManage = $currentUser ? userHasEntitlement('manage_webhooks', $currentUser['role'] ?? null) : false;
if (!$currentUser || !$canManage) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — Webhook management access required</h1><p>The manage_webhooks entitlement is required to view this page.</p></body></html>';
    exit;
}
$activePage = 'webhooks';

$db          = getDbMysqli();
$csrf        = csrfToken();
/* ELI5: are the three webhook tables actually there? WHY: gate #2 from
   includes/webhooks.php's own doc-block — an un-migrated docroot must no-op
   cleanly rather than throw (mysqli runs STRICT). Computed ONCE up front so
   both the POST dispatcher below and the GET render further down agree on
   whether it is safe to touch tblWebhook* at all this request. */
$schemaReady = webhookSchemaReady($db);

/* A ready-made hidden CSRF field, reused verbatim in every form on this page —
   one string, not eleven copies of the same htmlspecialchars() call. */
$csrfHidden = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';

/**
 * ELI5: best-effort audit-log a webhook admin action.
 * WHY: logActivity() may not exist on every install (guarded exactly like
 * api-keys.php's own $logKey closure), and it must never be allowed to fail
 * the admin action it is merely recording. Entity type is always the literal
 * 'webhook' string per this feature's audit convention — the entity id
 * distinguishes WHICH subscription (or, for a re-drive, which delivery).
 * $details must NEVER contain the plaintext secret — secret.rotate and
 * secret.reveal below deliberately log nothing but the id.
 */
$logWebhook = static function (string $verb, string $entityId, array $details): void {
    if (function_exists('logActivity')) {
        try {
            logActivity('webhook.' . $verb, 'webhook', $entityId, $details);
        } catch (\Throwable $_e) {
            /* audit is best-effort — never block the admin action on a logging hiccup */
        }
    }
};

/**
 * ELI5: turn the create/edit form's checkboxes into the space-separated
 * selector string the core expects.
 * WHY: pure page-local helper — every checkbox VALUE the page itself renders
 * is already a real event type straight from webhookEventFamilies() (never a
 * hand-typed one), so this just concatenates whatever came back checked. A
 * checked "match all" toggle wins outright and short-circuits to '*'.
 */
function _webhookEventsFromPost(array $post): string
{
    if (!empty($post['events_all'])) {
        return '*';
    }
    $raw = isset($post['events']) && is_array($post['events']) ? $post['events'] : [];
    $types = [];
    foreach ($raw as $t) {
        $t = trim((string)$t);
        if ($t !== '') {
            $types[$t] = true;
        }
    }
    return implode(' ', array_keys($types));
}

/**
 * ELI5: figure out which checkboxes should start "checked" when editing an
 * existing subscription, from its stored Events string.
 * WHY: derived from the SAME registry constant IHYMNS_WEBHOOK_EVENTS already
 * loaded via webhook_admin.php's require chain — never a typed list (rule
 * #34). A bare `*` selects only the "match all" toggle; a `family.*`
 * wildcard token expands to every concrete type in that family so the boxes
 * reflect what the subscription actually receives. Re-saving flattens that
 * back to explicit type tokens rather than preserving the wildcard spelling
 * — functionally identical, since webhookEventMatches() treats both
 * spellings the same at fan-out time.
 *
 * @return array{all:bool, types:array<string,true>}
 */
function _webhookEditCheckedState(string $eventsStr): array
{
    $tokens = preg_split('/\s+/', trim($eventsStr), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (in_array('*', $tokens, true)) {
        return ['all' => true, 'types' => []];
    }
    $checked = [];
    foreach ($tokens as $tok) {
        if (str_ends_with($tok, '.*')) {
            $prefix = substr($tok, 0, -2) . '.';
            foreach (array_keys(IHYMNS_WEBHOOK_EVENTS) as $type) {
                if (strncmp($type, $prefix, strlen($prefix)) === 0) {
                    $checked[$type] = true;
                }
            }
        } else {
            $checked[$tok] = true;
        }
    }
    return ['all' => false, 'types' => $checked];
}

/** ELI5: which Bootstrap badge colour goes with a subscription's lifecycle
 *  state? WHY: presentation-only map (the actual valid-value enforcement is
 *  WEBHOOK_SUB_STATUSES in the core) — falls back to a neutral grey for
 *  anything unrecognised rather than erroring. */
function _webhookStatusBadgeClass(string $status): string
{
    switch ($status) {
        case 'active':               return 'bg-success';
        case 'pending_verification': return 'bg-warning text-dark';
        case 'paused':                return 'bg-secondary';
        case 'disabled_failing':      return 'bg-danger';
        case 'revoked':                return 'bg-dark';
        default:                       return 'bg-secondary';
    }
}

/** ELI5: which badge colour goes with a delivery attempt's outcome? */
function _webhookDeliveryStatusBadgeClass(string $status): string
{
    switch ($status) {
        case 'succeeded':  return 'bg-success';
        case 'pending':
        case 'delivering': return 'bg-info text-dark';
        case 'failed':      return 'bg-warning text-dark';
        case 'dead':        return 'bg-danger';
        case 'cancelled':   return 'bg-secondary';
        default:             return 'bg-secondary';
    }
}

/**
 * ELI5: render the create-or-edit subscription form.
 * WHY: ONE function for BOTH create and edit (rule: extract shared UI, don't
 * copy-paste it) — the only difference is whether $editRow is null.
 */
function _webhookRenderSubscriptionForm(array $families, ?array $editRow, string $csrfHidden): void
{
    $isEdit  = $editRow !== null;
    $id      = $isEdit ? (int)$editRow['Id'] : 0;
    $label   = $isEdit ? (string)$editRow['Label'] : '';
    $url     = $isEdit ? (string)$editRow['TargetUrl'] : '';
    $checked = $isEdit ? _webhookEditCheckedState((string)$editRow['Events']) : ['all' => false, 'types' => []];
    $action  = $isEdit ? 'update' : 'create';
    ?>
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5 mb-3">
          <?php if ($isEdit): ?>
            <i class="bi bi-pencil-square me-2"></i>Edit subscription &mdash; <?= htmlspecialchars($label, ENT_QUOTES) ?>
          <?php else: ?>
            <i class="bi bi-plus-lg me-2"></i>New subscription
          <?php endif; ?>
        </h2>
        <form method="post" action="/manage/webhooks">
          <?= $csrfHidden ?>
          <input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
          <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label" for="whLabel">Label</label>
              <input type="text" class="form-control" id="whLabel" name="label" maxlength="120" required
                     value="<?= htmlspecialchars($label, ENT_QUOTES) ?>" placeholder="e.g. MeedyaDL sync">
              <div class="form-text">A human name so you can recognise this partner later.</div>
            </div>
            <div class="col-md-7">
              <label class="form-label" for="whUrl">Target URL</label>
              <input type="url" class="form-control font-monospace" id="whUrl" name="target_url" maxlength="500" required
                     value="<?= htmlspecialchars($url, ENT_QUOTES) ?>" placeholder="https://partner.example.com/webhooks/ihymns">
              <div class="form-text">Must be <code>https://</code> and resolve to a public address. Changing it re-opens verification.</div>
            </div>
          </div>

          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="whEventsAll" name="events_all" value="1" <?= $checked['all'] ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="whEventsAll">Match all events (*)</label>
            </div>
            <p class="form-text mb-2">Or pick specific events below.</p>

            <div class="row">
              <?php foreach ($families as $family => $types): ?>
                <div class="col-md-4 col-sm-6 mb-3">
                  <fieldset class="border rounded p-2 h-100">
                    <legend class="h6 float-none w-auto px-1 mb-1 text-capitalize"><?= htmlspecialchars($family, ENT_QUOTES) ?></legend>
                    <?php foreach ($types as $type => $typeLabel):
                        $cid = 'whEvt_' . preg_replace('/[^a-z0-9]+/i', '_', $type);
                    ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="<?= htmlspecialchars($cid, ENT_QUOTES) ?>" name="events[]"
                               value="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
                               <?= !empty($checked['types'][$type]) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="<?= htmlspecialchars($cid, ENT_QUOTES) ?>">
                          <?= htmlspecialchars($typeLabel, ENT_QUOTES) ?>
                          <code class="text-secondary"><?= htmlspecialchars($type, ENT_QUOTES) ?></code>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </fieldset>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create subscription' ?></button>
            <?php if ($isEdit): ?>
              <a href="/manage/webhooks" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php
}

/* ----------------------------------------------------------------------
 * POST dispatcher — plain form submissions (no fetch/JSON). Every action
 * below reads its inputs, calls the ONE matching core function, and sets a
 * flash for the render below. Nothing here talks to the database directly —
 * that is the whole point of delegating to includes/webhook_admin.php.
 * ---------------------------------------------------------------------- */
$flash        = null;   // ['type' => 'success'|'danger', 'message' => string]
$revealSecret = null;   // ['label' => string, 'secret' => string] — ONE-SHOT, never persisted

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* Same-origin CSRF check (rule #29) — a submitted session token still
       passes (path a), or a genuine same-origin form post (path b). A failed
       check SKIPS the action with an error flash rather than a hard 403, so
       a stale tab reload degrades gracefully instead of alarming an admin. */
    if (!validateCsrfRequest($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'message' => 'Security check failed — please retry.'];
    } elseif (!$schemaReady) {
        /* STRICT-safe: every core function below except webhookSubscriptionCreate()
           assumes the tables exist and would throw under mysqli's STRICT mode on a
           missing table — so refuse to even try, exactly like the render further
           down refuses to query when the schema isn't migrated yet. */
        $flash = ['type' => 'danger', 'message' => 'Webhook tables are not migrated on this environment — run the migration on /manage/setup-database.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        $userId = (int)($currentUser['id'] ?? 0) ?: null;

        switch ($action) {
            case 'create': {
                $label  = trim((string)($_POST['label'] ?? ''));
                $target = trim((string)($_POST['target_url'] ?? ''));
                $events = _webhookEventsFromPost($_POST);
                $result = webhookSubscriptionCreate($db, [
                    'label'      => $label,
                    'target_url' => $target,
                    'events'     => $events,
                ], $userId);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Subscription "' . $label . '" created — copy its signing secret below before you navigate away.'];
                    $revealSecret = ['label' => $label, 'secret' => (string)$result['secret']];
                    $logWebhook('create', (string)$result['id'], ['label' => $label, 'events' => $events]);
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not create the subscription.')];
                }
                break;
            }

            case 'update': {
                $id     = (int)($_POST['id'] ?? 0);
                $label  = trim((string)($_POST['label'] ?? ''));
                $target = trim((string)($_POST['target_url'] ?? ''));
                $events = _webhookEventsFromPost($_POST);
                if ($id <= 0) {
                    $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.'];
                    break;
                }
                $result = webhookSubscriptionUpdate($db, $id, [
                    'label'      => $label,
                    'target_url' => $target,
                    'events'     => $events,
                ], $userId);
                if ($result['ok']) {
                    $msg = 'Subscription "' . $label . '" updated.';
                    if (!empty($result['url_changed'])) {
                        $msg .= ' The target URL changed, so verification has re-opened — click Verify once the new endpoint is ready.';
                    }
                    $flash = ['type' => 'success', 'message' => $msg];
                    $logWebhook('update', (string)$id, ['label' => $label, 'events' => $events, 'url_changed' => !empty($result['url_changed'])]);
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not update the subscription.')];
                }
                break;
            }

            case 'verify': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $result = webhookSendVerification($db, $id);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Endpoint verified — the subscription is now active.'];
                    $logWebhook('verify', (string)$id, ['http_status' => $result['status'] ?? null]);
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Verification failed: ' . (string)($result['error'] ?? 'unknown error') . '.'];
                }
                break;
            }

            case 'pause': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $result = webhookSubscriptionSetStatus($db, $id, 'paused');
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Subscription paused — its queued deliveries were cancelled.'];
                    $logWebhook('pause', (string)$id, []);
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not pause the subscription.')];
                }
                break;
            }

            case 'resume': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $result = webhookSubscriptionSetStatus($db, $id, 'active');
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Resuming re-opens verification — click Verify to bring it back online.'];
                    $logWebhook('resume', (string)$id, []);
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not resume the subscription.')];
                }
                break;
            }

            case 'rotate_secret': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $row    = webhookSubscriptionGet($db, $id);
                $result = webhookSubscriptionRotateSecret($db, $id);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'New secret minted — the previous one keeps working for 24 hours so the partner can roll over without a hard cutover.'];
                    $revealSecret = ['label' => $row ? (string)$row['Label'] : ('#' . $id), 'secret' => (string)$result['secret']];
                    $logWebhook('secret.rotate', (string)$id, []);   /* NEVER the secret itself */
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not rotate the secret.')];
                }
                break;
            }

            case 'reveal_secret': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $row    = webhookSubscriptionGet($db, $id);
                $secret = webhookSubscriptionRevealSecret($db, $id);
                if ($secret !== null) {
                    $flash = ['type' => 'success', 'message' => 'Current signing secret revealed below.'];
                    $revealSecret = ['label' => $row ? (string)$row['Label'] : ('#' . $id), 'secret' => $secret];
                    $logWebhook('secret.reveal', (string)$id, []);   /* NEVER the secret itself */
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Could not reveal the secret — subscription not found.'];
                }
                break;
            }

            case 'send_test': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $note   = 'Test delivery triggered by ' . (string)($currentUser['username'] ?? 'an admin') . ' from /manage/webhooks.';
                $result = webhookEnqueueForSubscription($db, $id, 'webhook.test', ['note' => $note], ['source' => 'admin']);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Test event queued (delivery #' . (string)$result['delivery_id'] . ') — check the delivery log below.'];
                    $logWebhook('test', (string)$id, ['delivery_id' => $result['delivery_id'] ?? null]);
                } else {
                    $flash = ['type' => 'danger', 'message' => (string)($result['error'] ?? 'Could not queue a test event.')];
                }
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid subscription id.']; break; }
                $row = webhookSubscriptionGet($db, $id);
                if ($row === null) { $flash = ['type' => 'danger', 'message' => 'Subscription not found.']; break; }
                /* The destructive confirm itself is client-side (this form's
                   onsubmit="return confirm(...)" below) — a simple confirm is
                   fine per the task spec, mirroring api-keys.php's delete flow. */
                $result = webhookSubscriptionDelete($db, $id);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Subscription "' . (string)$row['Label'] . '" deleted.'];
                    $logWebhook('delete', (string)$id, ['label' => $row['Label']]);
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Could not delete the subscription.'];
                }
                break;
            }

            case 'redrive': {
                $deliveryId = (int)($_POST['delivery_id'] ?? 0);
                if ($deliveryId <= 0) { $flash = ['type' => 'danger', 'message' => 'Invalid delivery id.']; break; }
                $result = webhookDeliveryRedrive($db, $deliveryId);
                if ($result['ok']) {
                    $flash = ['type' => 'success', 'message' => 'Delivery #' . (string)$deliveryId . ' re-queued for immediate retry.'];
                    $logWebhook('delivery.redrive', (string)$deliveryId, []);
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Could not re-drive — the delivery may no longer be in a retryable state.'];
                }
                break;
            }

            default:
                $flash = ['type' => 'danger', 'message' => 'Unknown action.'];
        }
    }
}

/* ----------------------------------------------------------------------
 * GET data — only ever touches the tables when the schema is ready, so an
 * un-migrated install renders the warning card below and nothing else.
 * ---------------------------------------------------------------------- */
$families      = webhookEventFamilies();   // pure — safe to call even when the schema isn't ready
$subscriptions = [];
$deliveries    = [];
$recentEvents  = [];
$editRow       = null;
$subLabelById  = [];

if ($schemaReady) {
    $subscriptions = webhookSubscriptionList($db);
    $deliveries    = webhookDeliveryLog($db, 0, 50);
    $recentEvents  = webhookRecentEvents($db, 25);
    foreach ($subscriptions as $sub) {
        $subLabelById[(int)$sub['Id']] = (string)$sub['Label'];
    }

    if (isset($_GET['edit'])) {
        $editId = (int)$_GET['edit'];
        if ($editId > 0) {
            $editRow = webhookSubscriptionGet($db, $editId);
            if ($editRow === null && $flash === null) {
                $flash = ['type' => 'danger', 'message' => 'Subscription #' . $editId . ' was not found — it may already have been deleted.'];
            }
        }
    }
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhooks — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
</head>
<body>
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

<main class="container-fluid py-4">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-broadcast me-2"></i>Webhooks</h1>
        <p class="text-secondary small mb-0">
            Register a partner's URL to receive signed HTTP callbacks when
            catalogue, sharing or live events happen — a song changes, a
            songbook changes, a set-list is shared, a service starts or ends.
            Each subscription must prove it controls its endpoint (Verify)
            before it receives anything, and its signing secret is shown in
            full only <strong>once</strong>.
        </p>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>" role="alert">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <?php if ($revealSecret !== null): ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Copy this secret now — it will not be shown again.</strong>
            <div class="input-group mt-2">
                <input type="text" class="form-control font-monospace" id="whSecretValue" readonly
                       value="<?= htmlspecialchars($revealSecret['secret'], ENT_QUOTES) ?>">
                <button type="button" class="btn btn-outline-secondary" id="whSecretCopyBtn" aria-label="Copy secret to clipboard">
                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                </button>
            </div>
            <p class="small text-secondary mb-0 mt-2">
                Signing secret for <strong><?= htmlspecialchars($revealSecret['label'], ENT_QUOTES) ?></strong>.
                Verify each delivery's <code>X-iHymns-Signature</code> header with it
                (<code>t=&lt;unix ts&gt;,v1=&lt;hex hmac-sha256 of "ts.body"&gt;</code>).
            </p>
        </div>
    <?php endif; ?>

    <?php if (!webhooksEnabled()): ?>
        <div class="alert alert-info" role="alert">
            <i class="bi bi-info-circle me-1"></i>
            Webhooks are dormant on this channel — subscriptions can be configured
            but nothing is delivered until an admin enables the channel on
            <a href="/manage/configuration">/manage/configuration</a>.
        </div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <div class="card border-warning">
            <div class="card-body">
                <h2 class="h5 text-warning-emphasis"><i class="bi bi-exclamation-triangle me-2"></i>Webhook tables not migrated</h2>
                <p class="mb-0">
                    This environment hasn't run the webhook schema migration yet, so
                    there is nothing to show or manage here. Run it on
                    <a href="/manage/setup-database">/manage/setup-database</a>, then
                    reload this page.
                </p>
            </div>
        </div>
    <?php else: ?>

        <?php _webhookRenderSubscriptionForm($families, $editRow, $csrfHidden); ?>

        <h2 class="h5 mb-2">Subscriptions</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle admin-table-responsive cp-sortable">
                <thead>
                    <tr>
                        <th data-col-priority="primary" data-sort-key="label" data-sort-type="text">Label</th>
                        <th data-col-priority="primary" data-sort-key="target" data-sort-type="text">Target</th>
                        <th data-col-priority="secondary" data-sort-key="events" data-sort-type="text">Events</th>
                        <th data-col-priority="primary" data-sort-key="status" data-sort-type="text">Status</th>
                        <th data-col-priority="secondary" data-sort-key="failures" data-sort-type="number">Consecutive failures</th>
                        <th data-col-priority="tertiary" data-sort-key="lastsuccess" data-sort-type="date">Last success</th>
                        <th data-col-priority="primary" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($subscriptions)): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">No webhook subscriptions yet. Create one above.</td></tr>
                <?php else: foreach ($subscriptions as $sub):
                    $sid      = (int)$sub['Id'];
                    $status   = (string)$sub['Status'];
                    $failures = (int)$sub['ConsecutiveFailures'];
                ?>
                    <tr>
                        <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars((string)$sub['Label'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars((string)$sub['Label'], ENT_QUOTES) ?>
                        </td>
                        <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars((string)$sub['TargetHost'], ENT_QUOTES) ?>">
                            <div class="fw-semibold"><?= htmlspecialchars((string)$sub['TargetHost'], ENT_QUOTES) ?></div>
                            <div class="small text-secondary text-truncate" style="max-width: 22rem;" title="<?= htmlspecialchars((string)$sub['TargetUrl'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars((string)$sub['TargetUrl'], ENT_QUOTES) ?>
                            </div>
                        </td>
                        <td data-col-priority="secondary">
                            <code class="small text-truncate d-inline-block" style="max-width: 14rem;" title="<?= htmlspecialchars((string)$sub['Events'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars((string)$sub['Events'], ENT_QUOTES) ?>
                            </code>
                        </td>
                        <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                            <span class="badge <?= _webhookStatusBadgeClass($status) ?>"><?= htmlspecialchars(str_replace('_', ' ', $status), ENT_QUOTES) ?></span>
                        </td>
                        <td data-col-priority="secondary" data-sort-value="<?= $failures ?>">
                            <?php if ($failures > 0): ?>
                                <span class="badge bg-warning text-dark"><?= $failures ?></span>
                            <?php else: ?>
                                <span class="text-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td data-col-priority="tertiary" data-sort-value="<?= htmlspecialchars((string)($sub['LastSuccessAt'] ?? ''), ENT_QUOTES) ?>">
                            <?= $sub['LastSuccessAt'] ? htmlspecialchars((string)$sub['LastSuccessAt'], ENT_QUOTES) : '<span class="text-secondary">never</span>' ?>
                        </td>
                        <td data-col-priority="primary" class="text-end">
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <form method="post" action="/manage/webhooks" class="d-inline">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="verify">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Verify</button>
                                </form>
                                <?php if ($status === 'active'): ?>
                                    <form method="post" action="/manage/webhooks" class="d-inline">
                                        <?= $csrfHidden ?>
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Pause</button>
                                    </form>
                                <?php elseif (in_array($status, ['paused', 'disabled_failing'], true)): ?>
                                    <form method="post" action="/manage/webhooks" class="d-inline">
                                        <?= $csrfHidden ?>
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Resume</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="/manage/webhooks" class="d-inline">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="rotate_secret">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Rotate secret</button>
                                </form>
                                <form method="post" action="/manage/webhooks" class="d-inline">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="reveal_secret">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Reveal secret</button>
                                </form>
                                <form method="post" action="/manage/webhooks" class="d-inline">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="send_test">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-info">Send test</button>
                                </form>
                                <a href="/manage/webhooks?edit=<?= $sid ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <form method="post" action="/manage/webhooks" class="d-inline"
                                      onsubmit="return confirm('Delete this webhook subscription permanently? The partner stops receiving events immediately.');">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <h2 class="h5 mb-2 mt-4">Delivery log</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle admin-table-responsive cp-sortable">
                <thead>
                    <tr>
                        <th data-col-priority="primary" data-sort-key="event" data-sort-type="text">Event type</th>
                        <th data-col-priority="secondary" data-sort-key="sub" data-sort-type="text">Subscription</th>
                        <th data-col-priority="primary" data-sort-key="status" data-sort-type="text">Status</th>
                        <th data-col-priority="secondary" data-sort-key="http" data-sort-type="number">HTTP code</th>
                        <th data-col-priority="secondary" data-sort-key="attempts" data-sort-type="number">Attempts</th>
                        <th data-col-priority="tertiary" data-sort-key="next" data-sort-type="date">Next attempt</th>
                        <th data-col-priority="tertiary" data-sort-key="last" data-sort-type="date">Last attempt</th>
                        <th data-col-priority="tertiary">Details</th>
                        <th data-col-priority="primary" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No deliveries logged yet.</td></tr>
                <?php else: foreach ($deliveries as $d):
                    $did       = (int)$d['Id'];
                    $dStatus   = (string)$d['Status'];
                    $canRedrive = in_array($dStatus, ['failed', 'dead', 'cancelled'], true);
                    $subLabel  = $subLabelById[(int)$d['SubscriptionId']] ?? ('#' . (int)$d['SubscriptionId']);
                    $logDecoded = json_decode((string)($d['AttemptLogJson'] ?? '[]'), true);
                    $logPretty  = json_encode(is_array($logDecoded) ? $logDecoded : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                ?>
                    <tr>
                        <td data-col-priority="primary"><code class="small"><?= htmlspecialchars((string)$d['EventType'], ENT_QUOTES) ?></code></td>
                        <td data-col-priority="secondary"><?= htmlspecialchars($subLabel, ENT_QUOTES) ?></td>
                        <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars($dStatus, ENT_QUOTES) ?>">
                            <span class="badge <?= _webhookDeliveryStatusBadgeClass($dStatus) ?>"><?= htmlspecialchars($dStatus, ENT_QUOTES) ?></span>
                        </td>
                        <td data-col-priority="secondary"><?= $d['LastHttpStatus'] !== null ? (int)$d['LastHttpStatus'] : '<span class="text-secondary">—</span>' ?></td>
                        <td data-col-priority="secondary"><?= (int)$d['AttemptCount'] ?></td>
                        <td data-col-priority="tertiary"><?= $d['NextAttemptAt'] ? htmlspecialchars((string)$d['NextAttemptAt'], ENT_QUOTES) : '<span class="text-secondary">—</span>' ?></td>
                        <td data-col-priority="tertiary"><?= $d['LastAttemptAt'] ? htmlspecialchars((string)$d['LastAttemptAt'], ENT_QUOTES) : '<span class="text-secondary">—</span>' ?></td>
                        <td data-col-priority="tertiary">
                            <details>
                                <summary class="small text-secondary" style="cursor:pointer;">log</summary>
                                <pre class="small mb-0 mt-1"><?= htmlspecialchars((string)$logPretty, ENT_QUOTES) ?></pre>
                                <?php if (!empty($d['LastError'])): ?>
                                    <div class="small text-danger mt-1"><?= htmlspecialchars((string)$d['LastError'], ENT_QUOTES) ?></div>
                                <?php endif; ?>
                            </details>
                        </td>
                        <td data-col-priority="primary" class="text-end">
                            <?php if ($canRedrive): ?>
                                <form method="post" action="/manage/webhooks" class="d-inline">
                                    <?= $csrfHidden ?>
                                    <input type="hidden" name="action" value="redrive">
                                    <input type="hidden" name="delivery_id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Re-drive</button>
                                </form>
                            <?php else: ?>
                                <span class="text-secondary small">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <h2 class="h5 mb-2 mt-4">Recent events</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle admin-table-responsive cp-sortable">
                <thead>
                    <tr>
                        <th data-col-priority="primary" data-sort-key="type" data-sort-type="text">Type</th>
                        <th data-col-priority="secondary" data-sort-key="entity" data-sort-type="text">Entity</th>
                        <th data-col-priority="tertiary" data-sort-key="source" data-sort-type="text">Source</th>
                        <th data-col-priority="tertiary" data-sort-key="when" data-sort-type="date">Occurred</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recentEvents)): ?>
                    <tr><td colspan="4" class="text-center text-secondary py-4">No events recorded yet.</td></tr>
                <?php else: foreach ($recentEvents as $ev): ?>
                    <tr>
                        <td data-col-priority="primary"><code class="small"><?= htmlspecialchars((string)$ev['EventType'], ENT_QUOTES) ?></code></td>
                        <td data-col-priority="secondary"><?= htmlspecialchars((string)$ev['EntityType'], ENT_QUOTES) ?><?= $ev['EntityId'] !== '' ? ' &middot; ' . htmlspecialchars((string)$ev['EntityId'], ENT_QUOTES) : '' ?></td>
                        <td data-col-priority="tertiary"><?= htmlspecialchars((string)($ev['Source'] ?? ''), ENT_QUOTES) ?: '<span class="text-secondary">—</span>' ?></td>
                        <td data-col-priority="tertiary"><?= htmlspecialchars((string)$ev['OccurredAt'], ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; /* $schemaReady */ ?>
</main>

<script>
(function () {
    'use strict';
    /* One-shot secret copy-to-clipboard — mirrors api-keys.php's keyResultCopy
       button. Guarded on element existence since it only renders right after
       a create/rotate/reveal action. */
    var copyBtn = document.getElementById('whSecretCopyBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var v = document.getElementById('whSecretValue');
            v.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(v.value); }
            else { document.execCommand('copy'); }
        });
    }
})();
</script>

<!-- Sortable table headers (#1786 sweep, same module every other admin list uses). -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
