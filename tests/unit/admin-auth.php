<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
require_once __DIR__ . '/../../src/Admin/LoginThrottle.php';

use MiniS3\Admin\AdminAuth;
use MiniS3\Admin\LoginThrottle;

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
$auth = new AdminAuth('admin', $hash);

assertSameValue(false, $auth->isAuthenticated(), 'new session is not authenticated');
assertSameValue(false, $auth->login('wrong-admin', 'admin-pass'), 'wrong username is rejected');
assertSameValue(false, $auth->isAuthenticated(), 'wrong username does not authenticate');
assertSameValue(false, $auth->login('admin', 'wrong-pass'), 'wrong password is rejected');
assertSameValue(false, $auth->isAuthenticated(), 'wrong password does not authenticate');
assertSameValue(true, $auth->login('admin', 'admin-pass'), 'right username and password are accepted');
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

// Session cookie hardening
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);
$params = AdminAuth::cookieParams();
assertSameValue(true, $params['httponly'], 'session cookie is HttpOnly');
assertSameValue('Lax', $params['samesite'], 'session cookie is SameSite=Lax');
assertSameValue('/_', $params['path'], 'session cookie is scoped to the admin path');
assertSameValue(false, $params['secure'], 'plain HTTP cookie is not Secure');
$_SERVER['HTTPS'] = 'on';
assertSameValue(true, AdminAuth::cookieParams()['secure'], 'HTTPS cookie is Secure');
unset($_SERVER['HTTPS']);
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
assertSameValue(true, AdminAuth::isHttpsRequest(), 'X-Forwarded-Proto https is detected');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

// Login throttle
$throttleDir = sys_get_temp_dir() . '/mini-s3-throttle-' . bin2hex(random_bytes(4));
mkdir($throttleDir);
$throttlePath = $throttleDir . '/throttle.json';
$now = 1_700_000_000;
for ($i = 1; $i < LoginThrottle::MAX_FAILURES; $i++) {
    (new LoginThrottle($throttlePath, $now))->recordFailure('10.0.0.1');
}
assertSameValue(0, (new LoginThrottle($throttlePath, $now))->retryAfter('10.0.0.1'), 'below the limit a login is allowed');
(new LoginThrottle($throttlePath, $now))->recordFailure('10.0.0.1');
assertSameValue(LoginThrottle::WINDOW_SECONDS, (new LoginThrottle($throttlePath, $now))->retryAfter('10.0.0.1'), 'reaching the limit locks the IP');
assertSameValue(0, (new LoginThrottle($throttlePath, $now))->retryAfter('10.0.0.2'), 'other IPs are not locked');
assertSameValue(0, (new LoginThrottle($throttlePath, $now + LoginThrottle::WINDOW_SECONDS + 1))->retryAfter('10.0.0.1'), 'lock expires');
assertSameValue(false, str_contains((string) file_get_contents($throttlePath), '10.0.0.1'), 'raw IPs are not stored');
(new LoginThrottle($throttlePath, $now))->reset('10.0.0.1');
assertSameValue(0, (new LoginThrottle($throttlePath, $now))->retryAfter('10.0.0.1'), 'reset clears the lock');
(new LoginThrottle($throttlePath, $now))->recordFailure('10.0.0.3');
(new LoginThrottle($throttlePath, $now + LoginThrottle::WINDOW_SECONDS + 5))->recordFailure('10.0.0.3');
assertSameValue(0, (new LoginThrottle($throttlePath, $now + LoginThrottle::WINDOW_SECONDS + 5))->retryAfter('10.0.0.3'), 'failures outside the window start a new count');
@unlink($throttlePath);
@rmdir($throttleDir);
assertSameValue(0, (new LoginThrottle('/nonexistent-dir/throttle.json', $now))->retryAfter('10.0.0.1'), 'missing state file allows login');
(new LoginThrottle('/nonexistent-dir/throttle.json', $now))->recordFailure('10.0.0.1');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminAuth tests passed" . PHP_EOL;
