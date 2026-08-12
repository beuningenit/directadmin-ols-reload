<?php

declare(strict_types=1);

const OLS_PLUGIN_DIR = '/usr/local/directadmin/plugins/openlitespeed_reload';
const OLS_ALLOWLIST_FILE = OLS_PLUGIN_DIR . '/config/allowed_resellers';
const OLS_SECRET_FILE = OLS_PLUGIN_DIR . '/config/secret';
const OLS_LOG_FILE = '/var/log/directadmin-openlitespeed-reload.log';
const OLS_LOCK_DIR = '/run/directadmin-openlitespeed-reload';
const OLS_LOCK_FILE = OLS_LOCK_DIR . '/reload.lock';
const OLS_COOLDOWN_SECONDS = 10;
const OLS_SERVICE = 'lsws.service';
const OLS_USERNAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
const OLS_DA_USERS_DIR = '/usr/local/directadmin/data/users';
const OLS_RESELLER_URL = '/CMD_PLUGINS_RESELLER/openlitespeed_reload/index.html';
const OLS_ADMIN_URL = '/CMD_PLUGINS_ADMIN/openlitespeed_reload/index.html';

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
    parse_str(html_entity_decode($raw), $pairs);
    $params = [];
    foreach ($pairs as $key => $value) {
        if (is_string($value)) {
            $params[urldecode((string)$key)] = urldecode($value);
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

function ols_account_usertype(string $username): string
{
    if (!preg_match(OLS_USERNAME_PATTERN, $username)) {
        return '';
    }
    $path = OLS_DA_USERS_DIR . '/' . $username . '/user.conf';
    if (!is_file($path)) {
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

function ols_allowlist_entries(): array
{
    if (!ols_file_is_root_protected(OLS_ALLOWLIST_FILE)) {
        return [];
    }
    $lines = @file(OLS_ALLOWLIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $entries = [];
    foreach ($lines as $line) {
        $entry = strtolower(trim($line));
        if ($entry === '' || $entry[0] === '#') {
            continue;
        }
        if (!preg_match(OLS_USERNAME_PATTERN, $entry)) {
            continue;
        }
        $entries[$entry] = true;
    }
    return array_keys($entries);
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

function ols_service_state(): string
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
    foreach (['REMOTE_ADDR', 'SESSION_IP', 'IP'] as $key) {
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
    $handle = @fopen(OLS_LOG_FILE, 'a');
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

function ols_page_open(): string
{
    return '<style>'
        . '.ols-wrap{max-width:640px;margin:16px auto;padding:24px;background:#fff;border:1px solid #e0e4e8;border-radius:8px;font-family:inherit;color:#2c3345}'
        . '.ols-title{margin:0 0 16px;font-size:20px;font-weight:600}'
        . '.ols-status{margin:0 0 20px;font-size:15px}'
        . '.ols-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:13px;font-weight:600}'
        . '.ols-state-running{background:#e6f6ec;color:#1e7c45}'
        . '.ols-state-stopped{background:#fdecea;color:#b3261e}'
        . '.ols-state-unavailable{background:#f1f2f4;color:#5f6673}'
        . '.ols-state-unknown{background:#fff4e5;color:#9a6700}'
        . '.ols-notice{margin:0 0 16px;padding:12px 16px;border-radius:6px;font-size:14px;line-height:1.5}'
        . '.ols-notice-info{background:#eef4fd;color:#1a4f9c}'
        . '.ols-notice-success{background:#e6f6ec;color:#1e7c45}'
        . '.ols-notice-error{background:#fdecea;color:#b3261e}'
        . '.ols-notice-warning{background:#fff4e5;color:#9a6700}'
        . '.ols-button{display:inline-block;padding:9px 18px;border:0;border-radius:6px;background:#2f6fed;color:#fff;font-size:14px;font-weight:600;cursor:pointer}'
        . '.ols-button:hover{background:#2456bd}'
        . '.ols-button-danger{background:#b3261e}'
        . '.ols-button-danger:hover{background:#8c1d17}'
        . '.ols-cancel{margin-left:12px;font-size:14px}'
        . '.ols-muted{color:#5f6673;font-size:13px}'
        . '.ols-section{margin-top:28px;padding-top:16px;border-top:1px solid #e0e4e8}'
        . '.ols-section h3{margin:0 0 10px;font-size:15px;font-weight:600}'
        . '.ols-log{margin:0;padding:12px;background:#f6f7f9;border-radius:6px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre}'
        . '.ols-list{margin:0;padding-left:20px;font-size:14px}'
        . '</style>'
        . '<div class="ols-wrap"><h2 class="ols-title">OpenLiteSpeed</h2>';
}

function ols_page_close(): string
{
    return '</div>';
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
    return '<p class="ols-status">Status: <span class="ols-badge ' . $entry[1] . '">' . ols_h($entry[0]) . '</span></p>';
}

function ols_reload_form(string $url, string $token): string
{
    return '<form method="post" action="' . ols_h($url) . '" onsubmit="var b=this.querySelector(\'button\');if(b){b.disabled=true;b.textContent=\'Working...\';}">'
        . '<input type="hidden" name="action" value="reload">'
        . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
        . '<button type="submit" class="ols-button">Reload OpenLiteSpeed</button>'
        . '</form>'
        . '<p class="ols-muted">A reload gracefully restarts OpenLiteSpeed to apply configuration changes. It affects all websites hosted on this server.</p>';
}

function ols_confirm_form(string $url, string $token): string
{
    return ols_notice('warning', '<strong>Are you sure you want to gracefully reload OpenLiteSpeed?</strong><br>This operation affects all websites hosted on this server.')
        . '<form method="post" action="' . ols_h($url) . '" onsubmit="var b=this.querySelector(\'button\');if(b){b.disabled=true;b.textContent=\'Reloading...\';}">'
        . '<input type="hidden" name="action" value="reload">'
        . '<input type="hidden" name="confirm" value="yes">'
        . '<input type="hidden" name="csrf_token" value="' . ols_h($token) . '">'
        . '<button type="submit" class="ols-button ols-button-danger">Yes, reload OpenLiteSpeed</button>'
        . '<a class="ols-cancel" href="' . ols_h($url) . '">Cancel</a>'
        . '</form>';
}

function ols_back_link(string $url): string
{
    return '<p><a href="' . ols_h($url) . '">Back to status</a></p>';
}

function ols_admin_sections(): string
{
    $entries = ols_allowlist_entries();
    $html = '<div class="ols-section"><h3>Allowlisted resellers</h3>';
    if ($entries === []) {
        $html .= '<p class="ols-muted">The allowlist is empty. No resellers are authorized to reload OpenLiteSpeed.</p>';
    } else {
        $html .= '<ul class="ols-list">';
        foreach ($entries as $entry) {
            $html .= '<li>' . ols_h($entry) . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '<p class="ols-muted">Edit ' . ols_h(OLS_ALLOWLIST_FILE) . ' as root to change this list.</p></div>';
    $html .= '<div class="ols-section"><h3>Recent audit entries</h3>';
    $lines = ols_log_tail(20);
    if ($lines === []) {
        $html .= '<p class="ols-muted">No audit entries yet.</p>';
    } else {
        $html .= '<pre class="ols-log">' . ols_h(implode("\n", $lines)) . '</pre>';
    }
    $html .= '</div>';
    return $html;
}

function ols_render_simple(string $inner): void
{
    echo ols_page_open() . $inner . ols_page_close();
}

function ols_render_status_page(string $url, ?string $token, bool $isAdmin): void
{
    $state = ols_service_state();
    $inner = ols_state_badge($state);
    if ($state === 'unavailable') {
        $inner .= ols_notice('warning', 'The OpenLiteSpeed service (lsws) was not found on this server. Reloading is not available.');
    } elseif ($token === null) {
        $inner .= ols_notice('error', 'The reload action is unavailable because the request session could not be validated. Reopen this page from the DirectAdmin panel.');
    } else {
        $inner .= ols_reload_form($url, $token);
    }
    ols_render_simple($inner . ($isAdmin ? ols_admin_sections() : ''));
}

function ols_render_result_page(string $url, string $kind, string $message, bool $isAdmin): void
{
    $inner = ols_state_badge(ols_service_state())
        . ols_notice($kind, ols_h($message))
        . ols_back_link($url);
    ols_render_simple($inner . ($isAdmin ? ols_admin_sections() : ''));
}

function ols_handle_post(array $identity, bool $authorized, string $url, bool $isAdmin): void
{
    $post = ols_post_params();
    $action = ols_param($post, 'action');
    if (!$authorized) {
        ols_audit($identity, 'reload_request', false, false, 'denied_not_authorized');
        ols_render_simple(ols_notice('error', 'You are not authorized to use this plugin.'));
        return;
    }
    if ($action !== 'reload') {
        ols_audit($identity, 'reload_request', true, false, 'denied_invalid_action');
        ols_render_result_page($url, 'error', 'The request was not recognized.', $isAdmin);
        return;
    }
    if (!ols_csrf_valid($identity, ols_param($post, 'csrf_token'))) {
        ols_audit($identity, 'reload_request', true, false, 'denied_invalid_token');
        ols_render_result_page($url, 'error', 'The request could not be validated. Reopen this page and try again.', $isAdmin);
        return;
    }
    $token = ols_csrf_token($identity);
    if (ols_param($post, 'confirm') !== 'yes') {
        ols_render_simple(ols_confirm_form($url, (string)$token));
        return;
    }
    $result = ols_perform_reload($identity);
    switch ($result['outcome']) {
        case 'success':
            ols_audit($identity, 'reload', true, true, 'success', $result['detail']);
            ols_render_result_page($url, 'success', 'OpenLiteSpeed was reloaded successfully.', $isAdmin);
            return;
        case 'failed':
            ols_audit($identity, 'reload', true, true, 'failed', $result['detail']);
            ols_render_result_page($url, 'error', 'OpenLiteSpeed could not be reloaded. Ask a server administrator to check the audit log.', $isAdmin);
            return;
        case 'busy':
            ols_audit($identity, 'reload', true, false, 'locked');
            ols_render_result_page($url, 'warning', 'Another reload is already in progress. Please try again shortly.', $isAdmin);
            return;
        case 'cooldown':
            ols_audit($identity, 'reload', true, false, 'cooldown', $result['detail']);
            ols_render_result_page($url, 'warning', 'OpenLiteSpeed was reloaded moments ago. Please wait a few seconds and try again.', $isAdmin);
            return;
        case 'not_installed':
        default:
            ols_audit($identity, 'reload', true, false, 'service_unavailable');
            ols_render_result_page($url, 'warning', 'The OpenLiteSpeed service (lsws) was not found on this server.', $isAdmin);
            return;
    }
}

function ols_handle_request(string $level): void
{
    $url = $level === 'admin' ? OLS_ADMIN_URL : OLS_RESELLER_URL;
    if (!ols_php_supported()) {
        ols_render_simple(ols_notice('error', 'This plugin requires PHP 7.4 or newer at /usr/local/bin/php.'));
        return;
    }
    if (!ols_running_as_root()) {
        ols_render_simple(ols_notice('error', 'This plugin requires DirectAdmin 1.689 or newer with reseller_run_as=root support. Please update DirectAdmin.'));
        return;
    }
    $identity = ols_identity();
    if ($identity === null) {
        ols_audit(ols_anonymous_identity(), 'request', false, false, 'invalid_identity');
        ols_render_simple(ols_notice('error', 'The request could not be validated.'));
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
        ols_render_simple(ols_notice('error', 'You are not authorized to use this plugin.'));
        return;
    }
    ols_render_status_page($url, ols_csrf_token($identity), $isAdmin);
}
