<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminAuth.php';

use MiniS3\Admin\AdminAuth;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

$_SESSION = [];
$hash = password_hash('admin-pass', PASSWORD_DEFAULT);
$auth = new AdminAuth($hash);

assertSameValue(false, $auth->isAuthenticated(), 'new session is not authenticated');
assertSameValue(false, $auth->login('wrong-pass'), 'wrong password is rejected');
assertSameValue(false, $auth->isAuthenticated(), 'wrong login does not authenticate');
assertSameValue(true, $auth->login('admin-pass'), 'right password is accepted');
assertSameValue(true, $auth->isAuthenticated(), 'right login authenticates');

$token = $auth->csrfToken();
assertSameValue(true, is_string($token) && strlen($token) >= 32, 'csrf token is generated');
assertSameValue(true, $auth->verifyCsrfToken($token), 'csrf token verifies');
assertSameValue(false, $auth->verifyCsrfToken('bad-token'), 'bad csrf token fails');

$auth->setFlash('Upgrade complete');
assertSameValue('Upgrade complete', $auth->consumeFlash(), 'flash message is consumed');
assertSameValue('', $auth->consumeFlash(), 'flash message is cleared after consume');

$auth->logout();
assertSameValue(false, $auth->isAuthenticated(), 'logout clears authentication');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminAuth tests passed" . PHP_EOL;
