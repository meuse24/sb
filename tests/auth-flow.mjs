// Opt-in integration test against the configured Supabase project.
// Run: node tests/auth-flow.mjs <php-executable> [additional PHP options]
// Creates disposable users; always removes them and their rate-limit buckets.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { once } from 'node:events';
import { cp, mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, dirname, basename } from 'node:path';
import { createServer } from 'node:net';
import { randomBytes, createHash } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';

if (process.env.AUTH_TEST_LIVE !== '1') {
    console.log('Set AUTH_TEST_LIVE=1 to run real HTTP/database integration tests with disposable accounts.');
    process.exit(0);
}
const root = resolve(import.meta.dirname, '..');
const php = process.argv[2] || 'php';
const phpOptions = process.argv.slice(3);
const config = JSON.parse(execFileSync(php, [...phpOptions, '-r', 'echo json_encode(require "config/config.local.php");'], { cwd: root, windowsHide: true, encoding: 'utf8' }));
assert.ok(/^sb_secret_(?!REPLACE_ME)/.test(config.supabase_secret_key ?? ''), 'Configure a backend Secret Key locally first.');
const suffix = randomBytes(8).toString('hex');
const email = `auth-test-${suffix}@example.invalid`;
const secondEmail = `profile-test-${suffix}@example.invalid`;
const unknownEmail = `unknown-${suffix}@example.invalid`;
const password = randomBytes(24).toString('base64url');
const displayName = '<script>alert(1)</script>';
const temp = await mkdtemp(join(tmpdir(), 'supabase-auth-test-'));
const portProbe = createServer().listen(0, '127.0.0.1');
await once(portProbe, 'listening');
const port = portProbe.address().port;
await new Promise(resolve => portProbe.close(resolve));
let server;
let createdUser = false;
let createdSecondUser = false;
let secondUserId = null;
const digest = text => createHash('sha256').update(text).digest('hex');
async function api(method, path, body) {
    const response = await fetch(`${config.supabase_url}/rest/v1/${path}`, {
        method,
        headers: { apikey: config.supabase_secret_key, 'Content-Type': 'application/json', Prefer: 'return=representation' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    assert.ok(response.ok, `Backend HTTP ${response.status} during ${method}`);
    const text = await response.text();
    return text ? JSON.parse(text) : null;
}
const userFilter = 'email=eq.' + encodeURIComponent(email);
const secondFilter = 'email=eq.' + encodeURIComponent(secondEmail);
try {
    // Exercise the real provisioning command, including PHP's actual hashing.
    execFileSync(php, [...phpOptions, 'bin/create-user.php', email, displayName], {
        cwd: root, windowsHide: true, input: password + '\n', stdio: ['pipe', 'pipe', 'pipe'],
    });
    createdUser = true;
    const initial = (await api('GET', `users?${userFilter}&select=id,password_hash`))[0];
    assert.ok(initial.id);
    assert.ok(initial.password_hash !== password, 'Password must be hashed.');
    const second = (await api('POST', 'users?select=id', {
        email: secondEmail, password_hash: initial.password_hash, display_name: 'Konto B',
    }))[0];
    createdSecondUser = true;
    secondUserId = second.id;
    // Simulate an old hash so successful login also exercises automatic rehashing.
    const oldHash = execFileSync(php, [...phpOptions, '-r', 'echo password_hash(trim(stream_get_contents(STDIN)), PASSWORD_BCRYPT, ["cost"=>10]);'], {
        input: password, windowsHide: true, encoding: 'utf8',
    });
    await api('PATCH', `users?${userFilter}`, { password_hash: oldHash });
    await cp(join(root, 'app'), join(temp, 'app'), { recursive: true });
    await cp(join(root, 'public'), join(temp, 'public'), { recursive: true });
    await mkdir(join(temp, 'config'));
    await cp(join(root, 'config/config.local.php'), join(temp, 'config/config.local.php'));
    await mkdir(join(temp, 'sessions'));
    // This helper exists ONLY in the disposable tree, never in the repository public/.
    await writeFile(join(temp, 'public/fixture.php'), `<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$mode = $_GET['mode'] ?? '';
if ($mode === 'attempt-reset') unset($_SESSION['last_login_attempt']);
if ($mode === 'idle') $_SESSION['auth']['last_activity'] = time() - AUTH_IDLE_SECONDS;
if ($mode === 'absolute') $_SESSION['auth']['created_at'] = time() - AUTH_MAX_SECONDS;
if ($mode === 'legacy') $_SESSION['auth'] = ['access_token' => 'legacy', 'refresh_token' => 'legacy'];
if ($mode === 'state') { header('Content-Type: application/json'); echo json_encode($_SESSION); }
`);
    server = spawn(php, [...phpOptions, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', `session.save_path=${join(temp, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', join(temp, 'public')], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let stderr = '';
    server.stderr.on('data', chunk => { stderr += chunk; });
    let cookie = '';
    async function request(page, form) {
        const response = await fetch(`http://127.0.0.1:${port}/${page}`, {
            redirect: 'manual', headers: cookie ? { Cookie: cookie } : {},
            ...(form ? { method: 'POST', body: new URLSearchParams(form) } : {}),
        });
        const setCookie = response.headers.get('set-cookie');
        if (setCookie) cookie = setCookie.split(';')[0];
        return { status: response.status, headers: response.headers, body: await response.text() };
    }
    let ready = false;
    for (let i = 0; i < 60; i++) {
        try { await request('login.php'); ready = true; break; } catch { await delay(50); }
    }
    assert.ok(ready, `PHP server did not start: ${stderr}`);
    const csrf = body => body.match(/name="csrf" value="([a-f0-9]+)"/)[1];
    let page = await request('login.php');
    let token = csrf(page.body);
    assert.equal((await request('profile.php')).headers.get('location'), 'login.php');
    assert.equal((await request('password.php')).headers.get('location'), 'login.php');
    assert.equal((await request('logout.php')).status, 405);
    assert.equal((await request('login.php', { csrf: 'invalid' })).status, 403);
    assert.equal((await request('login.php', { csrf: token, email: 'invalid', password })).status, 422);
    assert.equal((await request('login.php', { csrf: token, email, password: 'wrong' })).status, 429);
    await request('fixture.php?mode=attempt-reset');
    page = await request('login.php', { csrf: token, email, password: 'wrong' });
    assert.equal(page.status, 401);
    assert.doesNotMatch(page.body, /value="wrong"/);
    await request('fixture.php?mode=attempt-reset');
    assert.equal((await request('login.php', { csrf: token, email, password: 'a'.repeat(73) })).status, 422);

    async function login() {
        await request('fixture.php?mode=attempt-reset');
        const form = await request('login.php');
        const oldCookie = cookie;
        const response = await request('login.php', { csrf: csrf(form.body), email: ' ' + email.toUpperCase() + ' ', password });
        assert.equal(response.status, 303);
        assert.equal(response.headers.get('location'), 'profile.php');
        assert.notEqual(cookie, oldCookie, 'Session ID must rotate');
        assert.match(response.headers.get('set-cookie'), /secure/i);
        assert.match(response.headers.get('set-cookie'), /HttpOnly/i);
        assert.match(response.headers.get('set-cookie'), /SameSite=Lax/i);
    }
    await login();
    const rehashed = (await api('GET', `users?${userFilter}&select=password_hash`))[0].password_hash;
    assert.ok(rehashed !== oldHash, 'Old hash must be upgraded.');
    page = await request('profile.php');
    assert.equal(page.status, 200);
    assert.match(page.body, /&lt;script&gt;/);
    assert.ok(!/<script>|password_hash|sb_secret_|access_token|refresh_token/.test(page.body), 'Sensitive fields or unescaped HTML in profile.');
    assert.ok(!page.body.includes(password));
    token = csrf(page.body);
    // A forged target ID and unrelated fields must never escape session ownership.
    const editedName = 'Änderung 👋 <script>test</script>';
    page = await request(`profile.php?id=${second.id}`);
    assert.ok(page.body.includes(email) && !page.body.includes(secondEmail));
    page = await request('profile.php', {
        csrf: token, display_name: '  ' + editedName + '  ',
        id: second.id, user_id: second.id, email: secondEmail,
        is_active: 'false', auth_version: '99', password_hash: 'forged',
    });
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), 'profile.php');
    page = await request('profile.php');
    assert.ok(page.body.includes('Dein Anzeigename wurde gespeichert.'));
    assert.ok(page.body.includes('&lt;script&gt;test&lt;/script&gt;'));
    assert.ok(!page.body.includes('<script>'));
    assert.ok(!(await request('profile.php')).body.includes('Dein Anzeigename wurde gespeichert.'));
    const own = (await api('GET', `users?${userFilter}&select=id,email,display_name,is_active,auth_version,password_hash`))[0];
    assert.equal(own.display_name, editedName);
    assert.equal(own.email, email);
    assert.equal(own.is_active, true);
    assert.equal(own.auth_version, 1);
    assert.ok(own.password_hash === rehashed, 'Unrelated password hash changed.');
    assert.equal((await api('GET', `users?${secondFilter}&select=display_name`))[0].display_name, 'Konto B');
    assert.equal((await request('profile.php', { csrf: 'bad', display_name: 'forbidden' })).status, 403);
    for (const value of ['x'.repeat(101), '😀'.repeat(101), 'line\nbreak', 'null\0byte']) {
        assert.equal((await request('profile.php', { csrf: token, display_name: value })).status, 422);
    }
    assert.equal((await request('profile.php', { csrf: token, 'display_name[]': 'array' })).status, 422);
    assert.equal((await request('profile.php', { csrf: token })).status, 422);
    assert.equal((await api('GET', `users?${userFilter}&select=display_name`))[0].display_name, editedName);
    for (const name of ['😀'.repeat(100), '']) {
        assert.equal((await request('profile.php', { csrf: token, display_name: name })).status, 303);
        assert.equal((await api('GET', `users?${userFilter}&select=display_name`))[0].display_name, name);
    }
    const firstCookie = cookie;
    cookie = '';
    const secondForm = await request('login.php');
    assert.equal((await request('login.php', { csrf: csrf(secondForm.body), email: secondEmail, password })).status, 303);
    page = await request(`profile.php?id=${initial.id}`);
    assert.ok(page.body.includes(secondEmail) && !page.body.includes(email));
    assert.equal((await request('profile.php', {
        csrf: csrf(page.body), display_name: 'Nur B', id: initial.id, user_id: initial.id,
    })).status, 303);
    assert.equal((await api('GET', `users?${userFilter}&select=display_name`))[0].display_name, '');
    assert.equal((await api('GET', `users?${secondFilter}&select=display_name`))[0].display_name, 'Nur B');
    await request('logout.php', { csrf: csrf((await request('profile.php')).body) });
    cookie = firstCookie;
    // A stale version or concurrently disabled account must not accept the PATCH.
    function guardedUpdate(version) {
        return execFileSync(php, [...phpOptions, '-r',
            'require "app/user-store.php"; require "app/supabase-http-store.php"; $p=json_decode(stream_get_contents(STDIN),true); echo json_encode(userStore()->updateDisplayName($p["id"],$p["version"],"stale"));'], {
            cwd: root, windowsHide: true, encoding: 'utf8', input: JSON.stringify({ id: second.id, version }),
        });
    }
    assert.equal(guardedUpdate(99), 'false');
    await api('PATCH', `users?${secondFilter}`, { is_active: false });
    assert.equal(guardedUpdate(1), 'false');
    assert.equal((await api('GET', `users?${secondFilter}&select=display_name`))[0].display_name, 'Nur B');
    await api('PATCH', `users?${secondFilter}`, { is_active: true });
    // Password changes: two independent B sessions, while A must stay untouched.
    const mainCookie = cookie;
    async function loginSecond(candidate) {
        cookie = '';
        const form = await request('login.php');
        return request('login.php', { csrf: csrf(form.body), email: secondEmail, password: candidate });
    }
    assert.equal((await loginSecond(password)).status, 303);
    const otherSession = cookie;
    const otherToken = csrf((await request('password.php')).body);
    assert.equal((await loginSecond(password)).status, 303);
    page = await request('password.php');
    const passwordToken = csrf(page.body);
    const newPassword = randomBytes(18).toString('base64url') + 'Ä🔐';
    const change = (extra = {}) => ({
        csrf: passwordToken, current_password: password, new_password: newPassword,
        confirm_password: newPassword, ...extra,
    });
    assert.equal((await request('password.php', change({ csrf: 'bad' }))).status, 403);
    for (const extra of [
        { confirm_password: 'different' },
        { new_password: 'x'.repeat(11), confirm_password: 'x'.repeat(11) },
        { new_password: '😀'.repeat(3), confirm_password: '😀'.repeat(3) },
        { new_password: 'x'.repeat(73), confirm_password: 'x'.repeat(73) },
        { current_password: undefined, 'current_password[]': 'array' },
    ]) {
        assert.equal((await request('password.php', change(extra))).status, 422);
    }
    page = await request('password.php', change({ current_password: 'wrong' }));
    assert.equal(page.status, 403);
    assert.ok(!page.body.includes(newPassword) && !page.body.includes(password), 'Password was echoed.');
    assert.equal((await request('password.php', change({ new_password: password, confirm_password: password }))).status, 422);
    const beforePassword = (await api('GET', `users?${secondFilter}&select=password_hash,auth_version`))[0];
    assert.equal(beforePassword.auth_version, 1);
    assert.ok(beforePassword.password_hash === initial.password_hash, 'Invalid attempts changed the password.');
    const beforeChangeCookie = cookie;
    page = await request('password.php', change({ id: initial.id, user_id: initial.id, auth_version: '999' }));
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), 'profile.php');
    assert.notEqual(cookie, beforeChangeCookie, 'Password change must rotate the session ID.');
    const activeSession = cookie;
    page = await request('profile.php');
    assert.equal(page.status, 200);
    assert.ok(page.body.includes('Dein Passwort wurde geändert.'));
    assert.notEqual(csrf(page.body), passwordToken, 'Password change must rotate CSRF.');
    const afterPassword = (await api('GET', `users?${secondFilter}&select=password_hash,auth_version`))[0];
    assert.equal(afterPassword.auth_version, 2);
    assert.ok(afterPassword.password_hash !== beforePassword.password_hash && afterPassword.password_hash !== newPassword, 'Password must be stored as a new hash.');
    assert.ok((await api('GET', `users?${userFilter}&select=password_hash`))[0].password_hash === rehashed, 'Password of A was changed by B.');
    assert.equal((await request('password.php', change())).status, 403, 'Old CSRF must fail.');
    cookie = otherSession;
    assert.equal((await request('password.php', { csrf: otherToken, current_password: password, new_password: newPassword, confirm_password: newPassword })).headers.get('location'), 'login.php?expired=1');
    cookie = activeSession;
    assert.equal((await request('profile.php')).status, 200);
    assert.equal((await loginSecond(password)).status, 401, 'Old password must fail.');
    assert.equal((await loginSecond(newPassword)).status, 303, 'New password must work.');

    async function compareAndSwap(version, oldHashValue, newHashValue) {
        const source = 'require "app/user-store.php"; require "app/supabase-http-store.php"; $p=json_decode(stream_get_contents(STDIN),true); echo json_encode(userStore()->changePassword($p["id"],$p["version"],$p["old"],$p["new"]));';
        const child = spawn(php, [...phpOptions, '-r', source], { cwd: root, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
        let output = '';
        child.stdout.on('data', chunk => { output += chunk; });
        child.stderr.resume();
        child.stdin.end(JSON.stringify({ id: second.id, version, old: oldHashValue, new: newHashValue }));
        const [code] = await once(child, 'exit');
        assert.equal(code, 0, 'Conditional password update failed.');
        return JSON.parse(output);
    }
    assert.equal(await compareAndSwap(1, afterPassword.password_hash, initial.password_hash), false);
    assert.equal(await compareAndSwap(2, 'stale-hash', initial.password_hash), false);
    await api('PATCH', `users?${secondFilter}`, { is_active: false });
    assert.equal(await compareAndSwap(2, afterPassword.password_hash, initial.password_hash), false);
    await api('PATCH', `users?${secondFilter}`, { is_active: true });
    const concurrentResults = await Promise.all([
        compareAndSwap(2, afterPassword.password_hash, initial.password_hash),
        compareAndSwap(2, afterPassword.password_hash, oldHash),
    ]);
    assert.deepEqual(concurrentResults.sort(), [false, true], 'Only one concurrent update may succeed.');
    const afterRace = (await api('GET', `users?${secondFilter}&select=password_hash,auth_version`))[0];
    assert.equal(afterRace.auth_version, 3);
    assert.ok([initial.password_hash, oldHash].includes(afterRace.password_hash), 'Unexpected password after concurrent update.');
    assert.equal((await request('profile.php')).headers.get('location'), 'login.php?expired=1');
    assert.equal((await loginSecond(password)).status, 303);
    let rateToken = csrf((await request('password.php')).body);
    const passwordBucket = digest('email:password-change:' + second.id);
    await api('DELETE', `login_attempts?bucket=eq.${passwordBucket}`);
    for (let i = 1; i <= 11; i++) {
        if (i === 6) {
            assert.equal((await loginSecond(password)).status, 303);
            rateToken = csrf((await request('password.php')).body);
        }
        assert.equal((await request('password.php', {
            csrf: rateToken, current_password: 'wrong', new_password: newPassword, confirm_password: newPassword,
        })).status, i <= 10 ? 403 : 429, `Password-change limit attempt ${i}`);
    }
    await request('logout.php', { csrf: rateToken });
    cookie = mainCookie;
    const state = JSON.parse((await request('fixture.php?mode=state')).body);
    assert.deepEqual(Object.keys(state.auth).sort(), ['created_at', 'last_activity', 'user_id', 'version']);
    assert.equal((await request('logout.php', { csrf: 'invalid' })).status, 403);
    assert.equal((await request('profile.php')).status, 200);
    page = await request('logout.php', { csrf: token });
    assert.equal(page.headers.get('location'), 'login.php?logout=1');
    assert.match(page.headers.get('set-cookie'), /Max-Age=0/);
    assert.equal((await request('profile.php')).headers.get('location'), 'login.php');
    for (const mode of ['idle', 'absolute', 'legacy']) {
        await login();
        await request(`fixture.php?mode=${mode}`);
        page = await request('profile.php');
        assert.equal(page.headers.get('location'), 'login.php?expired=1', mode);
    }
    await login();
    await api('PATCH', `users?${userFilter}`, { auth_version: 2 });
    assert.equal((await request('profile.php')).headers.get('location'), 'login.php?expired=1');
    await login();
    await api('PATCH', `users?${userFilter}`, { is_active: false });
    assert.equal((await request('profile.php')).headers.get('location'), 'login.php?expired=1');
    await api('PATCH', `users?${userFilter}`, { is_active: true });
    // Use a clean bucket for the remaining positive-login test.
    await api('DELETE', `login_attempts?bucket=eq.${digest('email:' + email)}`);
    await login();
    token = csrf((await request('profile.php')).body);
    await writeFile(join(temp, 'config/config.local.php'), '<?php return [];');
    assert.equal((await request('profile.php')).status, 503);
    assert.equal((await request('logout.php', { csrf: token })).headers.get('location'), 'login.php?logout=1');
    await cp(join(root, 'config/config.local.php'), join(temp, 'config/config.local.php'));
    // Cookie deletion must not bypass the database's email limit.
    for (let i = 1; i <= 11; i++) {
        cookie = '';
        const form = await request('login.php');
        const response = await request('login.php', { csrf: csrf(form.body), email: unknownEmail, password: 'wrong' });
        assert.equal(response.status, i <= 10 ? 401 : 429, `persistent rate limit attempt ${i}`);
    }
    console.log('PASS: authentication/profile isolation and password changes, old/new password verification, session/CSRF rotation, revocation of other sessions, concurrent-update protection, persistent limits, validation and backend-outage handling.');
} finally {
    if (server && server.exitCode === null) {
        server.kill();
        await once(server, 'exit');
    }
    if (createdUser) await api('DELETE', `users?${userFilter}`);
    if (createdSecondUser) await api('DELETE', `users?${secondFilter}`);
    if (secondUserId) await api('DELETE', `login_attempts?bucket=eq.${digest('email:password-change:' + secondUserId)}`);
    for (const identity of ['email:' + email, 'email:' + secondEmail, 'email:' + unknownEmail, 'ip:127.0.0.1']) {
        await api('DELETE', `login_attempts?bucket=eq.${digest(identity)}`);
    }
    // Verify the exact mkdtemp target before recursive cleanup on Windows.
    assert.equal(dirname(temp), resolve(tmpdir()));
    assert.match(basename(temp), /^supabase-auth-test-/);
    await rm(temp, { recursive: true, force: true });
}
