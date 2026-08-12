# DirectAdmin OpenLiteSpeed Reload Plugin

A deliberately narrow DirectAdmin (Evolution skin) plugin that lets explicitly allowlisted reseller accounts perform exactly one privileged operation: a graceful reload/restart of OpenLiteSpeed via `systemctl restart lsws`.

Resellers get no SSH access, no sudo, no arbitrary command execution, and no other service management. The single capability is gated server-side by a root-controlled allowlist, a per-session request token, a lock, a cooldown, and audit logging.

## Purpose

On DirectAdmin servers running OpenLiteSpeed, some `.htaccess`-style and vhost configuration changes only take effect after a graceful OpenLiteSpeed restart. This plugin lets trusted resellers trigger that restart themselves from the DirectAdmin panel, without giving them any broader privileges.

## Supported DirectAdmin versions

- **DirectAdmin 1.689 or newer** with the Evolution skin. This is a hard requirement: the plugin relies on `reseller_run_as=root` in `plugin.conf`, introduced in DirectAdmin 1.689, so no setuid helper and no sudoers changes are needed.
- On older DirectAdmin versions the installer refuses to install and the plugin page shows an incompatibility message instead of the reload action. Update DirectAdmin rather than working around this.

## Requirements

- DirectAdmin 1.689+ (Evolution skin)
- OpenLiteSpeed installed as the web server, with a systemd `lsws` service (`/usr/local/lsws/bin/openlitespeed` present)
- systemd (`systemctl` at `/usr/bin/systemctl` or `/bin/systemctl`)
- PHP CLI 7.4+ at `/usr/local/bin/php` (standard on CustomBuild servers)

This plugin is only for existing OpenLiteSpeed servers. It does not install OpenLiteSpeed and does not support Apache, nginx, or LiteSpeed Enterprise.

## Installation

### Option A: DirectAdmin Plugin Manager (easiest)

1. Download `openlitespeed_reload.tar.gz` from the [releases page](https://github.com/sjoerdvanb/directadmin-ols-reload/releases).
2. In DirectAdmin, go to **Admin » Plugin Manager » Add Plugin**, choose the downloaded `.tar.gz`, enter the admin password, and install.
3. DirectAdmin extracts the plugin and runs its install script, which validates the server (DirectAdmin >= 1.689, systemd, OpenLiteSpeed, PHP >= 7.4) and refuses cleanly if a requirement is missing.
4. Authorize resellers via the allowlist (see below).

Do not rename or re-pack the `.tar.gz`. DirectAdmin requires `plugin.conf` and the level directories at the **root** of the archive (no wrapping folder), and derives the plugin identity from it; re-packing with a wrapping directory produces `The following file is missing: plugin.conf`.

Note: DirectAdmin's Plugin Manager only accepts `.tar.gz` archives. The `.zip` on the releases page is a convenience copy of the same files for inspection or manual installs; it cannot be uploaded to Plugin Manager.

### Option B: from a git checkout

As root on the server:

```sh
cd /root
git clone https://github.com/<you>/directadmin-ols-reload.git
cd directadmin-ols-reload
sh installer/install.sh
```

The installer:

1. Copies the plugin to `/usr/local/directadmin/plugins/openlitespeed_reload/` (preserving any existing config)
2. Validates DirectAdmin >= 1.689, systemd, the `lsws` unit, the OpenLiteSpeed binary, and PHP >= 7.4
3. Sets root ownership, executable bits, and restrictive permissions on config and log files
4. Creates an empty allowlist at `config/allowed_resellers` if none exists (empty allowlist = nobody authorized)
5. Generates a root-only request-validation secret
6. Creates `/var/log/directadmin-openlitespeed-reload.log` (0600 root) and a logrotate policy
7. Marks the plugin active and installed

The installer is idempotent; re-running it never overwrites the allowlist, the secret, or the log.

To bypass the environment checks on a lab machine (not recommended in production):

```sh
OLS_RELOAD_FORCE=1 sh installer/install.sh
```

You can also build the Plugin Manager package yourself with `sh installer/package.sh`; it writes `dist/openlitespeed_reload.tar.gz` (and a convenience `.zip` when `zip` or `python3` is available) and verifies the archive layout before finishing.

If the menu entry does not appear immediately, reload the Evolution interface (log out and back in).

## Upgrade procedure

```sh
cd /root/directadmin-ols-reload
git pull
sh installer/install.sh
```

Ordinary upgrades replace the plugin code but preserve `config/allowed_resellers`, `config/secret`, and the audit log.

## Uninstallation

```sh
sh installer/uninstall.sh
```

This backs up a non-empty allowlist to `/root/openlitespeed_reload-allowed_resellers-<timestamp>.bak`, removes the logrotate policy and the plugin directory, and leaves OpenLiteSpeed, DirectAdmin configuration, and the audit log untouched. Remove `/var/log/directadmin-openlitespeed-reload.log` manually if you no longer want it.

Uninstalling through **Admin » Plugin Manager** works too; DirectAdmin runs `scripts/uninstall.sh` before deleting the directory.

## Adding a reseller to the allowlist

### From the DirectAdmin GUI (admin only)

Open **Admin » Reload OpenLiteSpeed**. The **Authorized resellers** section lists everyone currently allowed, with a **Remove** button per entry, and a dropdown of the server's remaining reseller accounts with an **Add reseller** button. Changes are written to the allowlist immediately and audited.

Only admin accounts can reach this section, and the dropdown is populated from actual reseller accounts on the server; the backend independently re-verifies that a submitted username is an active reseller before adding it, so a forged POST cannot authorize an arbitrary or non-existent name.

### From the command line

As root, add the DirectAdmin reseller username on its own line:

```sh
echo "resellername" >> /usr/local/directadmin/plugins/openlitespeed_reload/config/allowed_resellers
```

Rules:

- One exact username per line; no wildcards
- Empty lines and lines starting with `#` are ignored
- Malformed usernames (anything not matching `^[a-z][a-z0-9_]{0,31}$`) are ignored
- An empty file means nobody is authorized
- The file must stay owned by root and not group/world writable; if its permissions are loosened, the plugin treats the allowlist as empty and denies everyone

Changes take effect on the next request; no restart is needed.

## Removing a reseller from the allowlist

Use the **Remove** button in **Admin » Reload OpenLiteSpeed**, or edit the file as root and delete the line:

```sh
vi /usr/local/directadmin/plugins/openlitespeed_reload/config/allowed_resellers
```

Both routes take effect on the next request. Comment lines in the file are preserved when the GUI edits it.

## Usage

An allowlisted reseller logs into DirectAdmin Evolution and opens **Reload OpenLiteSpeed** from the menu (or `/CMD_PLUGINS_RESELLER/openlitespeed_reload/index.html`). The page shows the current service status (Running / Stopped / Not installed / Unknown) and a **Reload OpenLiteSpeed** button. Submitting it shows an explicit confirmation step warning that the operation affects all websites on the server. After confirmation the server performs one `systemctl restart lsws`, verifies the service is active again, and reports success or a sanitized failure message.

Admins have an equivalent page at **Admin » Reload OpenLiteSpeed** (`/CMD_PLUGINS_ADMIN/openlitespeed_reload/index.html`) that additionally manages the reseller allowlist (add/remove) and shows the most recent audit entries.

Both pages carry Beuningen IT branding: the company logo in the header and `#FFA900` as the accent colour for primary actions and focus states. The logo ships with the plugin at `images/logo-beuningenit.svg` and is served as a static asset, with its light `#f5f5f5` fill converted to `#000000` for legibility on the white panel background.

## Login-as behavior

Authorization is always evaluated against the **effective account** of the session, never the master identity behind a `login-as`:

- If an admin uses login-as into an allowlisted reseller, the action is permitted and the audit log records both identities (`user=reseller master=admin`).
- If an admin uses login-as into a reseller that is **not** allowlisted, the action is denied; being admin at the master level grants nothing on the reseller page. Admins who want to reload should use the admin page under their own identity.
- Privilege never flows from the impersonated account back to an unauthorized master, because the allowlist and usertype checks apply to the effective account only, immediately before execution.

Identities of the form `master|user` in DirectAdmin's `USERNAME` value are parsed accordingly, and both parts must match the strict username pattern or the request is rejected; a malformed value with an empty component is rejected outright.

**Verification note.** The `master|user` syntax is documented by DirectAdmin for API impersonation, and `login_as_master_name` is documented for pre/post *hook* scripts; neither is explicitly documented for plugin GUI scripts. The plugin therefore treats the **last** component as the effective account and re-derives authorization from that account's own `user.conf` on every request. If DirectAdmin's ordering ever differed, the consequence would be audit misattribution rather than privilege escalation, because impersonation only flows downward (an admin can already act as any account it impersonates). To confirm the audit trail on your server, perform one admin→reseller login-as and check that the resulting log line reads `user=<reseller> master=<admin>`.

## Security model

- **Single fixed operation.** The only privileged commands the plugin can run are `systemctl restart lsws.service`, `systemctl is-active lsws.service`, and `systemctl show lsws.service --property=LoadState --value`, always with a fixed argument array, an absolute `systemctl` path, a minimal environment, and no shell. No request value is ever part of a command.
- **Root execution via DirectAdmin.** Privileged execution uses `reseller_run_as=root` / `admin_run_as=root` (DirectAdmin 1.689+). There is no setuid helper, no sudoers entry, and no generic privileged helper. If the script is not running as root (older DirectAdmin), it renders an incompatibility notice and never attempts privileged work.
- **Server-side authorization on every request.** Before any action: the effective account must exist, not be suspended, and have `usertype=reseller` (allowlisted) or `usertype=admin`; hiding the menu is cosmetic only. Forged URLs or POSTs from other accounts are denied and logged. The plugin re-derives this itself rather than trusting DirectAdmin's URL-level separation, so a user-level account reaching a reseller URL would still be denied.
- **Trusted account metadata.** The `user.conf` that supplies `usertype` is only believed when it is a regular file, not group/world writable, and owned by root or by the same account that owns DirectAdmin's `data/users` directory. A tampered or loosened `user.conf` yields no usertype and therefore no access, so the admin/reseller decision cannot be forged by writing to that file.
- **Bounded resource use.** Service state is cached for a few seconds, so repeated page loads cannot drive unbounded `systemctl` invocations as root. The audit log stops growing at 32 MB and falls back to syslog, and logrotate is size- as well as time-based, so request floods cannot fill the filesystem.
- **No referer bypass.** DirectAdmin's own referer and CSRF protections are left intact; the plugin ships no `referer_check.allow`, and the installer refuses to run if one is present.
- **Allowlist integrity.** The allowlist is only trusted when owned by root and not group/world writable; otherwise it is treated as empty (fail closed). The same check guards writes, so the GUI refuses to edit a file with unsafe ownership or permissions. Each edit takes an exclusive lock for the whole read-modify-write sequence, so two admins acting at once cannot overwrite each other's change. The new contents are written to a 0600 temporary file in the root-only `config/` directory, verified to be complete and flushed, then moved into place with `rename()`, so a concurrent read never sees a partial allowlist and a short write never replaces a good file.
- **Allowlist editing is admin-only.** Add and remove actions require `usertype=admin` on the effective account, a valid POST token, a username matching the strict pattern, and — for additions — an existing, unsuspended account whose `usertype` is `reseller`. Resellers never see or reach these actions, and the reseller page exposes no allowlist controls at all.
- **POST-only with confirmation.** GET never triggers a reload; the action is only read from the POST body, must exactly equal `reload`, and requires `confirm=yes` from the server-rendered confirmation step.
- **CSRF.** DirectAdmin's own session and referer checking protect plugin requests; in addition every state-changing POST must carry an HMAC token bound to the DirectAdmin session ID and effective username, derived from a root-only secret generated at install time. Tokens are compared with constant-time comparison. The session ID itself is never logged or echoed.
- **Concurrency and rate limiting.** An exclusive `flock` on `/run/directadmin-openlitespeed-reload/reload.lock` guarantees at most one restart at a time, and a 10-second server-side cooldown absorbs repeated clicks and browser retries. The lock lives in a root-owned 0700 directory under `/run` (not the world-writable `/run/lock`) and the plugin verifies ownership and refuses symlinks before using it. The UI also disables the button after submission, but only the server-side lock is relied upon.
- **Sanitized output.** Resellers only ever see fixed status strings and fixed result messages. Command stderr, exit codes, environment values, and file contents never reach the reseller UI; failure detail goes to the root-only audit log (control characters stripped, truncated).
- **Audit logging.** Every privileged attempt (allowed or denied), including every allowlist addition and removal with its target username, is appended to `/var/log/directadmin-openlitespeed-reload.log` (0600 root) with timestamp, effective user, login-as master, request IP when DirectAdmin provides one, authorization outcome, whether a reload was attempted, and the result. No passwords, session IDs, cookies, or secrets are logged. If the log file cannot be written, entries fall back to syslog.

## Testing

See [TESTING.md](TESTING.md) for the full test plan covering authorization, HTTP behavior, service handling, concurrency, and injection resistance.

Quick smoke test after installation:

1. `systemctl is-active lsws` shows `active`.
2. Add a test reseller to the allowlist and open the plugin page as that reseller: status shows **Running**.
3. Click **Reload OpenLiteSpeed**, confirm, and verify the success message.
4. `tail /var/log/directadmin-openlitespeed-reload.log` shows the audit entry.
5. Open the page as a second, non-allowlisted reseller: the page reports not authorized, and a denied entry is logged.

## Troubleshooting

- **Page says the plugin requires DirectAdmin 1.689+.** The script is not running as root. Three causes, in order of likelihood: DirectAdmin is older than 1.689; `plugins_allowed_run_as=0` is set in `/usr/local/directadmin/conf/directadmin.conf`, which disables plugin `run_as` on *any* version (the installer warns about this); or the `run_as` lines were removed from `plugin.conf`. Check the DirectAdmin version and that setting before upgrading anything.
- **No menu entry for a reseller.** The reseller menu is intentionally hidden for accounts that are not allowlisted. Verify the username is in `config/allowed_resellers` (exact, lowercase, one per line) and that the file is root-owned with mode 600. Then reload the Evolution UI.
- **"The request could not be validated" on reload.** The session token expired or `config/secret` is missing or has unsafe permissions. Reopen the page from the panel; if it persists, re-run the installer and check `ls -l config/secret` (must be root, 0600).
- **Reload fails.** Check the audit log for the `detail="exit=... stderr=..."` entry, then `journalctl -u lsws` as root. The reseller UI intentionally shows no diagnostic output.
- **Status shows Not installed.** The `lsws` systemd unit is missing. This plugin only manages an existing OpenLiteSpeed installation.
- **Nothing is being logged to the file.** If the log file is unwritable the plugin logs to syslog (`journalctl -t openlitespeed_reload`). Re-run the installer to fix ownership.

## Repository layout

```text
README.md
TESTING.md
plugin/            files installed to /usr/local/directadmin/plugins/openlitespeed_reload/
  plugin.conf
  admin/index.html
  reseller/index.html
  reseller/menu.json.raw
  lib/common.php
  images/
  config/allowed_resellers.example
  scripts/install.sh
  scripts/uninstall.sh
installer/
  install.sh       standalone root installer (recommended)
  uninstall.sh
  package.sh       builds a Plugin Manager tar.gz into dist/
```
