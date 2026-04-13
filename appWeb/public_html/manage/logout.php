<?php

declare(strict_types=1);

/**
 * iHymns — Admin Logout Handler (#229)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes/auth.php';

logout();
header('Location: /manage/login');
exit;
