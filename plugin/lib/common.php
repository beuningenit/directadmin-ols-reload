<?php

declare(strict_types=1);

const OLS_PLUGIN_DIR = '/usr/local/directadmin/plugins/openlitespeed_reload';
const OLS_CONFIG_DIR = '/etc/directadmin-openlitespeed-reload';
const OLS_ALLOWLIST_FILE = OLS_CONFIG_DIR . '/allowed_resellers';
const OLS_SECRET_FILE = OLS_CONFIG_DIR . '/secret';
const OLS_LOG_FILE = '/var/log/directadmin-openlitespeed-reload.log';
const OLS_LOCK_DIR = '/run/directadmin-openlitespeed-reload';
const OLS_LOCK_FILE = OLS_LOCK_DIR . '/reload.lock';
const OLS_COOLDOWN_SECONDS = 10;
const OLS_STATE_CACHE_FILE = OLS_LOCK_DIR . '/state.cache';
const OLS_STATE_CACHE_SECONDS = 3;
const OLS_LOG_MAX_BYTES = 33554432;
const OLS_SERVICE = 'lsws.service';
const OLS_USERNAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
const OLS_DA_USERS_DIR = '/usr/local/directadmin/data/users';
const OLS_RESELLER_URL = '/CMD_PLUGINS_RESELLER/openlitespeed_reload/index.html';
const OLS_ADMIN_URL = '/CMD_PLUGINS_ADMIN/openlitespeed_reload/index.html';
const OLS_BRAND_COLOR = '#FFA900';

function ols_running_as_root(): bool
{
    if (function_exists('posix_getuid')) {
        return posix_getuid() === 0;
    }
    return is_readable('/etc/shadow');
}

function ols_php_supported(): bool
{
    return PHP_VERSION_ID >= 70400;
}

function ols_decode_request(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    parse_str($raw, $pairs);
    $params = [];
    foreach ($pairs as $key => $value) {
        if (is_string($value)) {
            $params[(string)$key] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        }
    }
    return $params;
}

function ols_post_params(): array
{
    $raw = getenv('POST');
    return ols_decode_request($raw === false ? null : $raw);
}

function ols_param(array $params, string $key): string
{
    $value = $params[$key] ?? '';
    return is_string($value) ? $value : '';
}

function ols_is_post(): bool
{
    $method = getenv('REQUEST_METHOD');
    if ($method !== false && $method !== '') {
        return strtoupper($method) === 'POST';
    }
    $raw = getenv('POST');
    return $raw !== false && $raw !== '';
}

function ols_anonymous_identity(): array
{
    return ['effective' => '', 'master' => ''];
}

function ols_identity(): ?array
{
    $raw = getenv('USERNAME');
    if ($raw === false || $raw === '') {
        return null;
    }
    $parts = explode('|', $raw);
    if (count($parts) > 2) {
        return null;
    }
    foreach ($parts as $part) {
        if (trim($part) === '') {
            return null;
        }
    }
    $effective = strtolower(trim((string)end($parts)));
    $master = count($parts) === 2 ? strtolower(trim($parts[0])) : '';
    if ($master === '') {
        foreach (['LOGIN_AS_MASTER_NAME', 'login_as_master_name'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $master = strtolower(trim($value));
                break;
            }
        }
    }
    if (!preg_match(OLS_USERNAME_PATTERN, $effective)) {
        return null;
    }
    if ($master !== '' && !preg_match(OLS_USERNAME_PATTERN, $master)) {
        return null;
    }
    return ['effective' => $effective, 'master' => $master];
}

function ols_da_data_owner(): ?int
{
    clearstatcache(true, OLS_DA_USERS_DIR);
    $info = @stat(OLS_DA_USERS_DIR);
    if ($info === false) {
        return null;
    }
    return (int)$info['uid'];
}

function ols_user_conf_trusted(string $path): bool
{
    clearstatcache(true, $path);
    $info = @lstat($path);
    if ($info === false || ($info['mode'] & 0170000) !== 0100000) {
        return false;
    }
    if (($info['mode'] & 0022) !== 0) {
        return false;
    }
    if ($info['uid'] === 0) {
        return true;
    }
    $owner = ols_da_data_owner();
    return $owner !== null && $info['uid'] === $owner;
}

function ols_account_usertype(string $username): string
{
    if (!preg_match(OLS_USERNAME_PATTERN, $username)) {
        return '';
    }
    $path = OLS_DA_USERS_DIR . '/' . $username . '/user.conf';
    if (!is_file($path) || !ols_user_conf_trusted($path)) {
        return '';
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }
    $usertype = '';
    $suspended = '';
    foreach ($lines as $line) {
        if (strpos($line, 'usertype=') === 0) {
            $usertype = strtolower(trim(substr($line, 9)));
        } elseif (strpos($line, 'suspended=') === 0) {
            $suspended = strtolower(trim(substr($line, 10)));
        }
    }
    if ($suspended === 'yes') {
        return '';
    }
    return $usertype;
}

function ols_known_resellers(): array
{
    $names = [];
    $handle = @opendir(OLS_DA_USERS_DIR);
    if ($handle === false) {
        return $names;
    }
    while (($entry = readdir($handle)) !== false) {
        if (!preg_match(OLS_USERNAME_PATTERN, $entry)) {
            continue;
        }
        if (ols_account_usertype($entry) === 'reseller') {
            $names[] = $entry;
        }
    }
    closedir($handle);
    sort($names);
    return $names;
}

function ols_file_is_root_protected(string $path): bool
{
    clearstatcache(true, $path);
    $info = @stat($path);
    if ($info === false) {
        return false;
    }
    if ($info['uid'] !== 0) {
        return false;
    }
    return ($info['mode'] & 0022) === 0;
}

function ols_allowlist_lines(): array
{
    if (!ols_file_is_root_protected(OLS_ALLOWLIST_FILE)) {
        return [];
    }
    $lines = @file(OLS_ALLOWLIST_FILE, FILE_IGNORE_NEW_LINES);
    return $lines === false ? [] : $lines;
}

function ols_allowlist_entries(): array
{
    $entries = [];
    foreach (ols_allowlist_lines() as $line) {
        $entry = strtolower(trim($line));
        if ($entry === '' || $entry[0] === '#') {
            continue;
        }
        if (!preg_match(OLS_USERNAME_PATTERN, $entry)) {
            continue;
        }
        $entries[$entry] = true;
    }
    $result = array_keys($entries);
    sort($result);
    return $result;
}

function ols_allowlist_lock()
{
    if (!ols_lock_dir_ready()) {
        return null;
    }
    $path = OLS_LOCK_DIR . '/allowlist.lock';
    if (is_link($path)) {
        return null;
    }
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return null;
    }
    @chmod($path, 0600);
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function ols_allowlist_write(array $lines): bool
{
    if (!ols_file_is_root_protected(OLS_ALLOWLIST_FILE)) {
        return false;
    }
    $temp = OLS_ALLOWLIST_FILE . '.tmp';
    if (is_link($temp)) {
        return false;
    }
    $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
    $handle = @fopen($temp, 'w');
    if ($handle === false) {
        return false;
    }
    @chmod($temp, 0600);
    $written = fwrite($handle, $body);
    $flushed = fflush($handle);
    fclose($handle);
    if ($written !== strlen($body) || $flushed === false) {
        @unlink($temp);
        return false;
    }
    if (!@rename($temp, OLS_ALLOWLIST_FILE)) {
        @unlink($temp);
        return false;
    }
    @chmod(OLS_ALLOWLIST_FILE, 0600);
    return true;
}

function ols_allowlist_update(string $username, bool $shouldBePresent): bool
{
    if (!preg_match(OLS_USERNAME_PATTERN, $username)) {
        return false;
    }
    $lock = ols_allowlist_lock();
    if ($lock === null) {
        return false;
    }
    $kept = [];
    $present = false;
    foreach (ols_allowlist_lines() as $line) {
        if (strtolower(trim($line)) === $username) {
            $present = true;
            if (!$shouldBePresent) {
                continue;
            }
        }
        $kept[] = $line;
    }
    if ($shouldBePresent && !$present) {
        $kept[] = $username;
    }
    $result = ols_allowlist_write($kept);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function ols_allowlist_add(string $username): bool
{
    return ols_allowlist_update($username, true);
}

function ols_allowlist_remove(string $username): bool
{
    return ols_allowlist_update($username, false);
}

function ols_reseller_authorized(array $identity): bool
{
    $usertype = ols_account_usertype($identity['effective']);
    if ($usertype === 'admin') {
        return true;
    }
    if ($usertype !== 'reseller') {
        return false;
    }
    return in_array($identity['effective'], ols_allowlist_entries(), true);
}

function ols_admin_authorized(array $identity): bool
{
    return ols_account_usertype($identity['effective']) === 'admin';
}

function ols_systemctl_bin(): ?string
{
    foreach (['/usr/bin/systemctl', '/bin/systemctl'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function ols_run_systemctl(array $arguments, int $timeoutSeconds): array
{
    if (!ols_php_supported()) {
        return ['spawned' => false, 'exit' => -1, 'stdout' => '', 'stderr' => 'unsupported PHP version'];
    }
    $bin = ols_systemctl_bin();
    if ($bin === null) {
        return ['spawned' => false, 'exit' => -1, 'stdout' => '', 'stderr' => 'systemctl binary not found'];
    }
    $command = array_merge([$bin], $arguments);
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C'];
    $process = @proc_open($command, $descriptors, $pipes, '/', $env);
    if (!is_resource($process)) {
        return ['spawned' => false, 'exit' => -1, 'stdout' => '', 'stderr' => 'failed to start systemctl'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = time() + $timeoutSeconds;
    $timedOut = false;
    $exitCode = null;
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (time() > $deadline) {
            proc_terminate($process, 9);
            $timedOut = true;
            break;
        }
        usleep(100000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exit = ($exitCode !== null && $exitCode !== -1) ? $exitCode : $closeCode;
    if ($timedOut) {
        return ['spawned' => true, 'exit' => -1, 'stdout' => $stdout, 'stderr' => 'systemctl timed out'];
    }
    return ['spawned' => true, 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function ols_state_cache_get(): ?string
{
    if (is_link(OLS_STATE_CACHE_FILE)) {
        return null;
    }
    $raw = @file_get_contents(OLS_STATE_CACHE_FILE);
    if ($raw === false) {
        return null;
    }
    $parts = explode(' ', trim($raw));
    if (count($parts) !== 2) {
        return null;
    }
    $age = time() - (int)$parts[0];
    if ((int)$parts[0] <= 0 || $age < 0 || $age >= OLS_STATE_CACHE_SECONDS) {
        return null;
    }
    return in_array($parts[1], ['running', 'stopped', 'unavailable', 'unknown'], true) ? $parts[1] : null;
}

function ols_state_cache_put(string $state): void
{
    if (!ols_lock_dir_ready() || is_link(OLS_STATE_CACHE_FILE)) {
        return;
    }
    $handle = @fopen(OLS_STATE_CACHE_FILE, 'c');
    if ($handle === false) {
        return;
    }
    @chmod(OLS_STATE_CACHE_FILE, 0600);
    if (flock($handle, LOCK_EX | LOCK_NB)) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, time() . ' ' . $state);
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

function ols_state_cache_clear(): void
{
    if (!is_link(OLS_STATE_CACHE_FILE)) {
        @unlink(OLS_STATE_CACHE_FILE);
    }
}

function ols_service_state(): string
{
    $cached = ols_state_cache_get();
    if ($cached !== null) {
        return $cached;
    }
    $state = ols_service_state_uncached();
    ols_state_cache_put($state);
    return $state;
}

function ols_service_state_uncached(): string
{
    $load = ols_run_systemctl(['show', OLS_SERVICE, '--property=LoadState', '--value'], 10);
    if (!$load['spawned']) {
        return 'unknown';
    }
    $loadState = trim($load['stdout']);
    if ($loadState === '') {
        return 'unknown';
    }
    if ($loadState !== 'loaded') {
        return 'unavailable';
    }
    $active = ols_run_systemctl(['is-active', OLS_SERVICE], 10);
    if (!$active['spawned']) {
        return 'unknown';
    }
    $state = trim($active['stdout']);
    if ($state === 'active') {
        return 'running';
    }
    if (in_array($state, ['inactive', 'failed', 'deactivating'], true)) {
        return 'stopped';
    }
    return 'unknown';
}

function ols_lock_dir_ready(): bool
{
    if (!is_dir(OLS_LOCK_DIR)) {
        @mkdir(OLS_LOCK_DIR, 0700);
    }
    if (is_link(OLS_LOCK_DIR) || !is_dir(OLS_LOCK_DIR)) {
        return false;
    }
    clearstatcache(true, OLS_LOCK_DIR);
    $info = @stat(OLS_LOCK_DIR);
    if ($info === false || $info['uid'] !== 0) {
        return false;
    }
    return !is_link(OLS_LOCK_FILE);
}

function ols_open_lock()
{
    if (!ols_lock_dir_ready()) {
        return null;
    }
    $handle = @fopen(OLS_LOCK_FILE, 'c+');
    if ($handle === false) {
        return null;
    }
    @chmod(OLS_LOCK_FILE, 0600);
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function ols_cooldown_remaining($handle): int
{
    rewind($handle);
    $last = (int)trim((string)fread($handle, 32));
    if ($last <= 0) {
        return 0;
    }
    $elapsed = time() - $last;
    if ($elapsed >= 0 && $elapsed < OLS_COOLDOWN_SECONDS) {
        return OLS_COOLDOWN_SECONDS - $elapsed;
    }
    return 0;
}

function ols_mark_reload_time($handle): void
{
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string)time());
    fflush($handle);
}

function ols_perform_reload(array $identity): array
{
    $state = ols_service_state();
    if ($state === 'unavailable') {
        return ['outcome' => 'not_installed', 'detail' => ''];
    }
    $handle = ols_open_lock();
    if ($handle === null) {
        return ['outcome' => 'busy', 'detail' => ''];
    }
    $wait = ols_cooldown_remaining($handle);
    if ($wait > 0) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return ['outcome' => 'cooldown', 'detail' => 'wait=' . $wait];
    }
    ols_audit($identity, 'reload', true, true, 'started');
    $restart = ols_run_systemctl(['restart', OLS_SERVICE], 120);
    ols_mark_reload_time($handle);
    ols_state_cache_clear();
    $active = '';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $check = ols_run_systemctl(['is-active', OLS_SERVICE], 10);
        $active = trim($check['stdout']);
        if ($active === 'active' || $active === 'failed') {
            break;
        }
        usleep(500000);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    $detail = 'exit=' . $restart['exit'] . ' post_state=' . ($active === '' ? 'unknown' : $active);
    if (trim($restart['stderr']) !== '') {
        $detail .= ' stderr=' . trim($restart['stderr']);
    }
    if ($restart['spawned'] && $restart['exit'] === 0 && $active === 'active') {
        return ['outcome' => 'success', 'detail' => $detail];
    }
    return ['outcome' => 'failed', 'detail' => $detail];
}

function ols_sanitize_log_value(string $value): string
{
    $clean = (string)preg_replace('/[^\x20-\x7E]/', ' ', $value);
    $clean = str_replace('"', "'", $clean);
    if (strlen($clean) > 300) {
        $clean = substr($clean, 0, 300) . '...';
    }
    return trim($clean);
}

function ols_request_ip(): string
{
    foreach (['caller_ip', 'CALLER_IP', 'REMOTE_ADDR', 'SESSION_IP', 'IP'] as $key) {
        $value = getenv($key);
        if ($value !== false && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
    }
    return '-';
}

function ols_audit(array $identity, string $event, bool $authorized, bool $attempted, string $result, string $detail = ''): void
{
    $line = sprintf(
        '%s user=%s master=%s ip=%s event=%s authorized=%s attempted=%s result=%s',
        date('Y-m-d\TH:i:sP'),
        $identity['effective'] !== '' ? $identity['effective'] : '-',
        $identity['master'] !== '' ? $identity['master'] : '-',
        ols_request_ip(),
        $event,
        $authorized ? 'yes' : 'no',
        $attempted ? 'yes' : 'no',
        $result
    );
    if ($detail !== '') {
        $line .= ' detail="' . ols_sanitize_log_value($detail) . '"';
    }
    $line .= "\n";
    clearstatcache(true, OLS_LOG_FILE);
    $oversized = is_file(OLS_LOG_FILE) && (int)@filesize(OLS_LOG_FILE) >= OLS_LOG_MAX_BYTES;
    $handle = $oversized ? false : @fopen(OLS_LOG_FILE, 'a');
    if ($handle !== false) {
        @chmod(OLS_LOG_FILE, 0600);
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, $line);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return;
    }
    openlog('openlitespeed_reload', LOG_PID, LOG_DAEMON);
    syslog(LOG_WARNING, trim($line));
    closelog();
}

function ols_log_tail(int $maxLines): array
{
    if (!is_file(OLS_LOG_FILE)) {
        return [];
    }
    $size = (int)filesize(OLS_LOG_FILE);
    $handle = @fopen(OLS_LOG_FILE, 'r');
    if ($handle === false) {
        return [];
    }
    $offset = max(0, $size - 16384);
    fseek($handle, $offset);
    $data = (string)stream_get_contents($handle);
    fclose($handle);
    $lines = array_values(array_filter(explode("\n", $data), 'strlen'));
    if ($offset > 0 && count($lines) > 0) {
        array_shift($lines);
    }
    return array_slice($lines, -$maxLines);
}

function ols_csrf_token(array $identity): ?string
{
    if (!ols_file_is_root_protected(OLS_SECRET_FILE)) {
        return null;
    }
    $secret = trim((string)@file_get_contents(OLS_SECRET_FILE));
    if (strlen($secret) < 32) {
        return null;
    }
    $session = getenv('SESSION_ID');
    if ($session === false || $session === '') {
        return null;
    }
    return hash_hmac('sha256', 'openlitespeed-reload:' . $session . ':' . $identity['effective'], $secret);
}

function ols_csrf_valid(array $identity, string $provided): bool
{
    if (!preg_match('/^[0-9a-f]{64}$/', $provided)) {
        return false;
    }
    $expected = ols_csrf_token($identity);
    return $expected !== null && hash_equals($expected, $provided);
}

function ols_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ols_asset_url(string $pageUrl, string $file): string
{
    return rtrim(str_replace('index.html', '', $pageUrl), '/') . '/images/' . $file;
}

function ols_styles(): string
{
    return '<style>'
        . '.ols-wrap{--ols-brand:' . OLS_BRAND_COLOR . ';--ols-ink:#101114;--ols-muted:#5f6673;--ols-line:#e4e7ec;'
        . 'max-width:680px;margin:16px auto;background:#fff;border:1px solid var(--ols-line);border-radius:10px;'
        . 'box-shadow:0 1px 2px rgba(16,17,20,.05);color:var(--ols-ink);font-size:14px;line-height:1.55;overflow:hidden}'
        . '.ols-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:20px 28px;border-bottom:1px solid var(--ols-line)}'
        . '.ols-head img{height:30px;width:auto;max-width:210px}'
        . '.ols-head-rule{width:1px;align-self:stretch;background:var(--ols-line)}'
        . '.ols-head h2{margin:0;font-size:17px;font-weight:600;letter-spacing:-.01em}'
        . '.ols-body{padding:24px 28px}'
        . '.ols-status{display:flex;align-items:center;gap:10px;margin:0 0 20px;font-weight:500}'
        . '.ols-badge{display:inline-flex;align-items:center;gap:7px;padding:4px 12px;border-radius:999px;font-size:13px;font-weight:600}'
        . '.ols-dot{width:8px;height:8px;border-radius:50%;background:currentColor}'
        . '.ols-state-running{background:#e7f6ec;color:#12703a}'
        . '.ols-state-stopped{background:#fdeceb;color:#a91b12}'
        . '.ols-state-unavailable{background:#f2f3f5;color:#5f6673}'
        . '.ols-state-unknown{background:#fff5e3;color:#8a5a00}'
        . '.ols-notice{margin:0 0 18px;padding:13px 16px;border-radius:8px;border-left:3px solid transparent}'
        . '.ols-notice-info{background:#f2f3f5;border-color:#8a909c}'
        . '.ols-notice-success{background:#e7f6ec;border-color:#12703a;color:#0e5c2f}'
        . '.ols-notice-error{background:#fdeceb;border-color:#a91b12;color:#8f170f}'
        . '.ols-notice-warning{background:#fff5e3;border-color:var(--ols-brand);color:#7a4f00}'
        . '.ols-button{display:inline-block;padding:10px 20px;border:0;border-radius:7px;background:var(--ols-brand);'
        . 'color:var(--ols-ink);font:inherit;font-weight:600;cursor:pointer;transition:filter .12s ease}'
        . '.ols-button:hover{filter:brightness(.93)}'
        . '.ols-button:disabled{opacity:.6;cursor:default}'
        . '.ols-button-quiet{background:#f2f3f5;color:var(--ols-ink);padding:7px 14px;font-size:13px;font-weight:500}'
        . '.ols-button-quiet:hover{background:#e4e7ec;filter:none}'
        . '.ols-cancel{margin-left:14px;color:var(--ols-muted);text-decoration:underline}'
        . '.ols-muted{color:var(--ols-muted);font-size:13px}'
        . '.ols-hint{margin:14px 0 0;color:var(--ols-muted);font-size:13px}'
        . '.ols-section{padding:22px 28px;border-top:1px solid var(--ols-line);background:#fbfbfc}'
        . '.ols-section h3{margin:0 0 4px;font-size:14px;font-weight:600}'
        . '.ols-section p.ols-muted{margin:0 0 14px}'
        . '.ols-rows{margin:0 0 16px;border:1px solid var(--ols-line);border-radius:8px;background:#fff;overflow:hidden}'
        . '.ols-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-top:1px solid var(--ols-line)}'
        . '.ols-row:first-child{border-top:0}'
        . '.ols-row form{margin:0}'
        . '.ols-name{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}'
        . '.ols-empty{padding:14px;color:var(--ols-muted);font-size:13px;background:#fff}'
        . '.ols-add{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0}'
        . '.ols-add select,.ols-add input{padding:9px 11px;border:1px solid #cdd2da;border-radius:7px;font:inherit;'
        . 'background:#fff;color:var(--ols-ink);min-width:220px}'
        . '.ols-add select:focus,.ols-add input:focus{outline:2px solid var(--ols-brand);outline-offset:-1px;border-color:var(--ols-brand)}'
        . '.ols-log{margin:0;padding:13px;background:#fff;border:1px solid var(--ols-line);border-radius:8px;'
        . 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;line-height:1.7;'
        . 'overflow-x:auto;white-space:pre;color:#3a4050}'
        . '.ols-foot{padding:14px 28px;border-top:1px solid var(--ols-line);color:var(--ols-muted);font-size:12px}'
        . '</style>';
}

function ols_page_open(string $url): string
{
    return ols_styles()
        . '<div class="ols-wrap">'
        . '<div class="ols-head">'
        . '<img src="' . ols_h(ols_asset_url($url, 'logo-beuningenit.svg')) . '" alt="Beuningen IT">'
        . '<div class="ols-head-rule"></div>'
        . '<h2>OpenLiteSpeed</h2>'
        . '</div>';
}

function ols_page_close(): string
{
    return '<div class="ols-foot">Managed by Beuningen IT</div></div>';
}

function ols_render(string $url, string $bodyHtml, string $sectionsHtml = ''): void
{
    echo ols_page_open($url) . '<div class="ols-body">' . $bodyHtml . '</div>' . $sectionsHtml . ols_page_close();
}

function ols_notice(string $kind, string $messageHtml): string
{
    return '<div class="ols-notice ols-notice-' . $kind . '">' . $messageHtml . '</div>';
}

function ols_state_badge(string $state): string
{
    $labels = [
        'running' => ['Running', 'ols-state-running'],
        'stopped' => ['Stopped', 'ols-state-stopped'],
        'unavailable' => ['Not installed', 'ols-state-unavailable'],
        'unknown' => ['Unknown', 'ols-state-unknown'],
    ];
    $entry = $labels[$state] ?? $labels['unknown'];
    return '<p class="ols-status">Status'
        . '<span class="ols-badge ' . $entry[1] . '"><span class="ols-dot"></span>' . ols_h($entry[0]) . '</span></p>';
}

function ols_reload_form(string $url, string $token): string
{
    return '<form method="post" action="' . ols_h($url) . '" onsubmit="var b=this.querySelector(\'button\');if(b){b.disabled=true;b.textContent=\'Working...\';}">'
        . '<input type="hidden" name="action" value="reload">'
        . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
        . '<button type="submit" class="ols-button">Reload OpenLiteSpeed</button>'
        . '</form>'
        . '<p class="ols-hint">A reload gracefully restarts OpenLiteSpeed to apply configuration changes. It affects all websites hosted on this server.</p>';
}

function ols_confirm_form(string $url, string $token): string
{
    return ols_notice('warning', '<strong>Are you sure you want to gracefully reload OpenLiteSpeed?</strong><br>This operation affects all websites hosted on this server.')
        . '<form method="post" action="' . ols_h($url) . '" onsubmit="var b=this.querySelector(\'button\');if(b){b.disabled=true;b.textContent=\'Reloading...\';}">'
        . '<input type="hidden" name="action" value="reload">'
        . '<input type="hidden" name="confirm" value="yes">'
        . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
        . '<button type="submit" class="ols-button">Yes, reload OpenLiteSpeed</button>'
        . '<a class="ols-cancel" href="' . ols_h($url) . '">Cancel</a>'
        . '</form>';
}

function ols_back_link(string $url): string
{
    return '<p class="ols-hint"><a href="' . ols_h($url) . '">Back to status</a></p>';
}

function ols_allowlist_section(string $url, ?string $token): string
{
    $entries = ols_allowlist_entries();
    $html = '<div class="ols-section"><h3>Authorized resellers</h3>'
        . '<p class="ols-muted">Only these resellers may reload OpenLiteSpeed.</p>';
    $html .= '<div class="ols-rows">';
    if ($entries === []) {
        $html .= '<div class="ols-empty">No resellers are authorized yet.</div>';
    } else {
        foreach ($entries as $entry) {
            $html .= '<div class="ols-row"><span class="ols-name">' . ols_h($entry) . '</span>';
            if ($token !== null) {
                $html .= '<form method="post" action="' . ols_h($url) . '">'
                    . '<input type="hidden" name="action" value="remove_reseller">'
                    . '<input type="hidden" name="username" value="' . ols_h($entry) . '">'
                    . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
                    . '<button type="submit" class="ols-button ols-button-quiet">Remove</button>'
                    . '</form>';
            }
            $html .= '</div>';
        }
    }
    $html .= '</div>';

    if ($token !== null) {
        $candidates = array_values(array_diff(ols_known_resellers(), $entries));
        if ($candidates === []) {
            $html .= '<p class="ols-muted">Every reseller on this server is already authorized.</p>';
        } else {
            $html .= '<form class="ols-add" method="post" action="' . ols_h($url) . '">'
                . '<input type="hidden" name="action" value="add_reseller">'
                . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
                . '<select name="username" aria-label="Reseller to authorize">';
            foreach ($candidates as $candidate) {
                $html .= '<option value="' . ols_h($candidate) . '">' . ols_h($candidate) . '</option>';
            }
            $html .= '</select><button type="submit" class="ols-button">Add reseller</button></form>';
        }
    } else {
        $html .= '<p class="ols-muted">Editing is unavailable because the request session could not be validated.</p>';
    }
    $html .= '<p class="ols-hint">Changes take effect immediately and are written to ' . ols_h(OLS_ALLOWLIST_FILE) . '.</p>';
    return $html . '</div>';
}

function ols_log_section(): string
{
    $html = '<div class="ols-section"><h3>Recent audit entries</h3>'
        . '<p class="ols-muted">Every authorization decision and reload attempt is recorded.</p>';
    $lines = ols_log_tail(20);
    if ($lines === []) {
        $html .= '<div class="ols-empty">No audit entries yet.</div>';
    } else {
        $html .= '<pre class="ols-log">' . ols_h(implode("\n", $lines)) . '</pre>';
    }
    return $html . '</div>';
}

function ols_admin_sections(string $url, array $identity): string
{
    return ols_allowlist_section($url, ols_csrf_token($identity)) . ols_log_section();
}

function ols_sections(string $level, string $url, array $identity, bool $authorized): string
{
    return ($level === 'admin' && $authorized) ? ols_admin_sections($url, $identity) : '';
}

function ols_render_status_page(string $url, array $identity, string $sections, string $flash = ''): void
{
    $state = ols_service_state();
    $token = ols_csrf_token($identity);
    $body = ols_state_badge($state) . $flash;
    if ($state === 'unavailable') {
        $body .= ols_notice('warning', 'The OpenLiteSpeed service (lsws) was not found on this server. Reloading is not available.');
    } elseif ($token === null) {
        $body .= ols_notice('error', 'The reload action is unavailable because the request session could not be validated. Reopen this page from the DirectAdmin panel.');
    } else {
        $body .= ols_reload_form($url, $token);
    }
    ols_render($url, $body, $sections);
}

function ols_render_result_page(string $url, string $kind, string $message, string $sections): void
{
    ols_render($url, ols_state_badge(ols_service_state()) . ols_notice($kind, ols_h($message)) . ols_back_link($url), $sections);
}

function ols_handle_allowlist_change(array $identity, string $action, array $post, string $url): void
{
    if (!ols_csrf_valid($identity, ols_param($post, 'csrf_token'))) {
        ols_audit($identity, $action, true, false, 'denied_invalid_token');
        ols_render_result_page($url, 'error', 'The request could not be validated. Reopen this page and try again.', ols_admin_sections($url, $identity));
        return;
    }
    $username = strtolower(trim(ols_param($post, 'username')));
    if (!preg_match(OLS_USERNAME_PATTERN, $username)) {
        ols_audit($identity, $action, true, false, 'denied_invalid_username');
        ols_render_status_page($url, $identity, ols_admin_sections($url, $identity), ols_notice('error', 'That username is not valid.'));
        return;
    }
    if ($action === 'add_reseller') {
        if (ols_account_usertype($username) !== 'reseller') {
            ols_audit($identity, $action, true, false, 'denied_not_a_reseller', 'target=' . $username);
            ols_render_status_page($url, $identity, ols_admin_sections($url, $identity), ols_notice('error', ols_h($username) . ' is not an active reseller account on this server.'));
            return;
        }
        $ok = ols_allowlist_add($username);
        ols_audit($identity, 'allowlist_add', true, true, $ok ? 'success' : 'failed', 'target=' . $username);
        $flash = $ok
            ? ols_notice('success', ols_h($username) . ' can now reload OpenLiteSpeed.')
            : ols_notice('error', 'The allowlist could not be updated. Check its ownership and permissions.');
        ols_render_status_page($url, $identity, ols_admin_sections($url, $identity), $flash);
        return;
    }
    $ok = ols_allowlist_remove($username);
    ols_audit($identity, 'allowlist_remove', true, true, $ok ? 'success' : 'failed', 'target=' . $username);
    $flash = $ok
        ? ols_notice('success', ols_h($username) . ' can no longer reload OpenLiteSpeed.')
        : ols_notice('error', 'The allowlist could not be updated. Check its ownership and permissions.');
    ols_render_status_page($url, $identity, ols_admin_sections($url, $identity), $flash);
}

function ols_event_for_action(string $action): string
{
    switch ($action) {
        case 'reload':
            return 'reload_request';
        case 'add_reseller':
            return 'allowlist_add_request';
        case 'remove_reseller':
            return 'allowlist_remove_request';
        default:
            return 'unrecognized_request';
    }
}

function ols_handle_post(array $identity, bool $authorized, string $url, bool $isAdmin): void
{
    $post = ols_post_params();
    $action = ols_param($post, 'action');
    if (!$authorized) {
        ols_audit($identity, ols_event_for_action($action), false, false, 'denied_not_authorized');
        ols_render($url, ols_notice('error', 'You are not authorized to use this plugin.'));
        return;
    }
    if ($action === 'add_reseller' || $action === 'remove_reseller') {
        if (!$isAdmin) {
            ols_audit($identity, ols_event_for_action($action), false, false, 'denied_not_admin');
            ols_render($url, ols_notice('error', 'Only administrators can change the reseller allowlist.'));
            return;
        }
        ols_handle_allowlist_change($identity, $action, $post, $url);
        return;
    }
    $sections = $isAdmin ? ols_admin_sections($url, $identity) : '';
    if ($action !== 'reload') {
        ols_audit($identity, 'reload_request', true, false, 'denied_invalid_action');
        ols_render_result_page($url, 'error', 'The request was not recognized.', $sections);
        return;
    }
    if (!ols_csrf_valid($identity, ols_param($post, 'csrf_token'))) {
        ols_audit($identity, 'reload_request', true, false, 'denied_invalid_token');
        ols_render_result_page($url, 'error', 'The request could not be validated. Reopen this page and try again.', $sections);
        return;
    }
    if (ols_param($post, 'confirm') !== 'yes') {
        ols_render($url, ols_confirm_form($url, (string)ols_csrf_token($identity)));
        return;
    }
    $result = ols_perform_reload($identity);
    switch ($result['outcome']) {
        case 'success':
            ols_audit($identity, 'reload', true, true, 'success', $result['detail']);
            ols_render_result_page($url, 'success', 'OpenLiteSpeed was reloaded successfully.', $isAdmin ? ols_admin_sections($url, $identity) : '');
            return;
        case 'failed':
            ols_audit($identity, 'reload', true, true, 'failed', $result['detail']);
            ols_render_result_page($url, 'error', 'OpenLiteSpeed could not be reloaded. Ask a server administrator to check the audit log.', $isAdmin ? ols_admin_sections($url, $identity) : '');
            return;
        case 'busy':
            ols_audit($identity, 'reload', true, false, 'locked');
            ols_render_result_page($url, 'warning', 'Another reload is already in progress. Please try again shortly.', $sections);
            return;
        case 'cooldown':
            ols_audit($identity, 'reload', true, false, 'cooldown', $result['detail']);
            ols_render_result_page($url, 'warning', 'OpenLiteSpeed was reloaded moments ago. Please wait a few seconds and try again.', $sections);
            return;
        case 'not_installed':
        default:
            ols_audit($identity, 'reload', true, false, 'service_unavailable');
            ols_render_result_page($url, 'warning', 'The OpenLiteSpeed service (lsws) was not found on this server.', $sections);
            return;
    }
}

function ols_handle_request(string $level): void
{
    $url = $level === 'admin' ? OLS_ADMIN_URL : OLS_RESELLER_URL;
    if (!ols_running_as_root()) {
        ols_render($url, ols_notice('error', 'This plugin requires DirectAdmin 1.689 or newer with reseller_run_as=root support. Please update DirectAdmin.'));
        return;
    }
    $identity = ols_identity();
    if ($identity === null) {
        ols_audit(ols_anonymous_identity(), 'request', false, false, 'invalid_identity');
        ols_render($url, ols_notice('error', 'The request could not be validated.'));
        return;
    }
    $authorized = $level === 'admin' ? ols_admin_authorized($identity) : ols_reseller_authorized($identity);
    $isAdmin = $level === 'admin' && $authorized;
    if (ols_is_post()) {
        ols_handle_post($identity, $authorized, $url, $isAdmin);
        return;
    }
    if (!$authorized) {
        ols_audit($identity, 'page_view', false, false, 'denied');
        ols_render($url, ols_notice('error', 'You are not authorized to use this plugin.'));
        return;
    }
    ols_render_status_page($url, $identity, ols_sections($level, $url, $identity, $authorized));
}
