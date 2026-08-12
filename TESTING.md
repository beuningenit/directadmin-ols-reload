# Test plan

Run these tests on a staging DirectAdmin 1.689+ server with OpenLiteSpeed. You need: root SSH access, one admin account (`admin`), two reseller accounts (`resok` allowlisted, `resno` not allowlisted), and one user-level account (`user1`).

Setup:

```sh
sh installer/install.sh
echo "resok" >> /usr/local/directadmin/plugins/openlitespeed_reload/config/allowed_resellers
```

Shorthand used below:

- PAGE = `/CMD_PLUGINS_RESELLER/openlitespeed_reload/index.html`
- ADMIN_PAGE = `/CMD_PLUGINS_ADMIN/openlitespeed_reload/index.html`
- LOG = `/var/log/directadmin-openlitespeed-reload.log`

For curl-based tests, log into Evolution in a browser as the relevant account and copy the session cookie, or use DirectAdmin login keys. Example:

```sh
curl -sk "https://SERVER:2222$PAGE" -H "Cookie: session=..." -X POST --data "action=reload&confirm=yes&csrf_token=0000...00"
```

## Authorization

| # | Test | Steps | Expected |
|---|------|-------|----------|
| A1 | Authorized reseller can open page | Log in as `resok`, open PAGE | Status card with Running badge and reload button |
| A2 | Authorized reseller can reload | As `resok`: click Reload, confirm | "OpenLiteSpeed was reloaded successfully."; LOG gains `user=resok ... result=success` |
| A3 | Unauthorized reseller cannot reload | Log in as `resno`, open PAGE and also POST `action=reload&confirm=yes` directly | "You are not authorized"; LOG gains `authorized=no ... result=denied*`; no restart in `journalctl -u lsws` |
| A4 | User-level account cannot access | As `user1`, request PAGE and `/CMD_PLUGINS/openlitespeed_reload/index.html` | DirectAdmin denies the reseller command; no user-level plugin page exists; no reload occurs |
| A5 | Forged URL does not bypass | As `resno`, request PAGE with `?action=reload&confirm=yes` in the query string | Query string is ignored for actions; denied page; `attempted=no` in LOG |
| A6 | Forged POST does not bypass | As `resno`, POST `action=reload&confirm=yes` with a token copied from a `resok` session | Denied before token check (allowlist), and the token would fail anyway because it is bound to session+username |
| A7 | Menu hidden when unauthorized | Compare Evolution menus of `resok` and `resno` | Entry visible only for `resok`; direct URL still denied for `resno` (A3) |
| A8 | Login-as follows effective identity | As `admin`, login-as `resno`, open PAGE; then login-as `resok` and reload | Denied for `resno` despite admin master; allowed for `resok` with LOG showing `user=resok master=admin` |
| A9 | Admin page restricted to admins | As `resok`, request ADMIN_PAGE | DirectAdmin blocks the admin command level; even if reached, plugin usertype check denies |

## HTTP behavior

| # | Test | Steps | Expected |
|---|------|-------|----------|
| H1 | GET cannot reload | As `resok`, GET PAGE (also with `?action=reload&confirm=yes`) | Only the status page renders; no LOG `attempted=yes` entry; no restart |
| H2 | Expected POST reloads | As `resok`, POST `action=reload` (step 1), then submit rendered confirmation form | Confirmation shown first; reload runs only after `confirm=yes` with valid token |
| H3 | Invalid action rejected | As `resok`, POST `action=restart_everything` | "The request was not recognized."; LOG `result=denied_invalid_action`; no restart |
| H4 | Missing/garbage token rejected | As `resok`, POST `action=reload&confirm=yes` with `csrf_token` absent, malformed, or wrong | LOG `result=denied_invalid_token`; no restart |
| H5 | Repeated POST rate-limited | As `resok`, complete one reload, immediately replay the confirmed POST | Second response: cooldown warning; LOG `result=cooldown`; exactly one restart in `journalctl -u lsws` |

## Service handling

| # | Test | Steps | Expected |
|---|------|-------|----------|
| S1 | Running before reload | `systemctl is-active lsws` → `active`; PAGE shows Running | Matches |
| S2 | Reload succeeds | Perform A2; watch `journalctl -u lsws -f` | One graceful restart; success message |
| S3 | Running after reload | `systemctl is-active lsws` after A2 | `active`; PAGE shows Running |
| S4 | Missing service fails safely | `systemctl mask lsws && systemctl stop lsws` on a throwaway box, reload PAGE | Status "Not installed"; no reload button; POST answers "service was not found"; unmask afterwards |
| S5 | Failed restart sanitized | Temporarily break the unit (e.g. invalid `ExecStart` override), attempt reload | UI shows only "OpenLiteSpeed could not be reloaded..."; stderr/exit detail only in LOG; remove override afterwards |

## Security

| # | Test | Steps | Expected |
|---|------|-------|----------|
| X1 | Query-string metacharacters | GET/POST PAGE with `?action=reload;reboot&x=$(id)&y=%3B%7C%26` | Rendered output escapes everything; no command other than the fixed systemctl calls runs (verify with `journalctl` and shell audit if enabled) |
| X2 | POST metacharacters | POST `action=reload%3Breboot`, `confirm=yes%0Aid`, backticks, `|`, `&&` variants | Exact-match comparison fails; LOG `denied_invalid_action`; nothing executed |
| X3 | Tampered allowlist perms fail closed | `chmod 666 config/allowed_resellers`, reload PAGE as `resok` | Treated as empty allowlist: denied; restore with `chmod 600` |
| X4 | Concurrent requests → one reload | As `resok`, fire two confirmed POSTs simultaneously (`curl ... & curl ... &`) | One `result=success`, the other `result=locked` or `result=cooldown`; exactly one restart |
| X5 | No secrets in logs | `grep -Ei "session|token|passwd|cookie" $LOG` after the full run | No session IDs, tokens, or passwords present |
| X6 | No privileged output in UI | Inspect HTML of success/failure pages | No stderr, exit codes, paths beyond fixed text, or environment values |
| X7 | Secret file tamper fails closed | `chmod 666 config/secret`, open PAGE as `resok` | Reload action reports unavailable/invalid session; no reload possible; restore 600 |
| X8 | Old DirectAdmin behaves safely | On a DA < 1.689 box (or simulate by removing run_as lines) open PAGE | Page reports the 1.689 requirement; no privileged attempt; installer refuses without `OLS_RELOAD_FORCE=1` |

## Installer

| # | Test | Steps | Expected |
|---|------|-------|----------|
| I1 | Fresh install | `sh installer/install.sh` on a clean server | All `OK:` lines; correct perms: plugin root-owned, config 700/600, log 600 |
| I2 | Idempotent re-run | Run installer twice, add `resok` between runs | Second run preserves allowlist and secret; no errors |
| I3 | Upgrade preserves config | Modify allowlist, `git pull` (or re-copy), reinstall | Allowlist and secret unchanged; code files updated |
| I4 | Refuses wrong environment | Run on a non-OLS or old-DA box | Clear `ERROR:` naming the failed check; exit non-zero; nothing half-installed that grants privileges |
| I5 | Uninstall clean | `sh installer/uninstall.sh` | Plugin directory and logrotate policy removed; allowlist backed up to /root; OLS and DirectAdmin untouched |
| I6 | Package layout valid | `sh installer/package.sh`; `tar -tzf dist/openlitespeed_reload.tar.gz` | `plugin.conf` listed at archive root with no wrapping directory; script's own layout assertions pass |
| I7 | Plugin Manager upload | Upload `dist/openlitespeed_reload.tar.gz` in **Admin » Plugin Manager » Add Plugin** | Plugin installs without "The following file is missing: plugin.conf"; appears in the plugin list; admin and reseller pages load |

## Allowlist management (admin GUI)

| # | Test | Steps | Expected |
|---|------|-------|----------|
| M1 | Add via GUI | As `admin` on ADMIN_PAGE, pick `resno` in the dropdown, click **Add reseller** | Success notice; `resno` appears in the list; LOG gains `event=allowlist_add ... result=success detail="target=resno"`; `resno` can now reload |
| M2 | Remove via GUI | Click **Remove** next to `resno` | Success notice; entry disappears; LOG gains `event=allowlist_remove ... result=success`; `resno` is denied again |
| M3 | Reseller cannot manage | As `resok`, POST `action=add_reseller&username=resno` with a valid token to PAGE | Denied with "Only administrators can change the reseller allowlist."; LOG `result=denied_not_admin`; allowlist unchanged |
| M4 | Cannot add a non-reseller | As `admin`, POST `action=add_reseller&username=user1` (and a non-existent name) to ADMIN_PAGE | Rejected as "not an active reseller account"; LOG `result=denied_not_a_reseller`; allowlist unchanged |
| M5 | Malformed username rejected | POST `username=../../etc/passwd`, `username=RES OK`, `username=a;b` | "That username is not valid."; LOG `result=denied_invalid_username`; no file written |
| M6 | Token required | POST a valid add/remove without or with a wrong `csrf_token` | `result=denied_invalid_token`; allowlist unchanged |
| M7 | GET cannot modify | GET ADMIN_PAGE with `?action=add_reseller&username=resno` | Ignored; allowlist unchanged |
| M8 | Comments preserved | Add `# managed by ops` to the allowlist, then add and remove a reseller via GUI | Comment line still present afterwards |
| M9 | Unsafe permissions fail closed | `chmod 666` the allowlist, try to add via GUI | Edit refused with an error notice; restore `chmod 600` |
| M10 | Duplicate add is safe | Add `resok` twice | Second add succeeds without duplicating the entry |
| M11 | Concurrent edits serialize | Fire a simultaneous add of `resA` and remove of `resB` (`curl ... & curl ... &`) | Both changes survive; neither request resurrects or drops the other's entry |
| M12 | Short write does not clobber | Simulate a full filesystem for `config/` (e.g. a small tmpfs mount) and attempt an add | Error notice; original allowlist intact and unchanged; no `.tmp` file left behind |

## Branding

| # | Test | Steps | Expected |
|---|------|-------|----------|
| B1 | Logo renders | Open PAGE as `resok` | Beuningen IT logo visible in the header, dark text legible on white, not a broken image |
| B2 | Brand colour | Inspect the reload button | Background `#FFA900` with dark text; focus ring on inputs uses the same colour |
| B3 | Static asset served | Request `/CMD_PLUGINS_RESELLER/openlitespeed_reload/images/logo-beuningenit.svg` | SVG served as-is, not executed |

## Audit verification

After the full run, `cat $LOG` and verify each line has `timestamp user= master= ip= event= authorized= attempted= result=`, that denied attempts from `resno` are present, and that every executed reload produced a `result=started` line immediately followed by a `result=success` line with `detail="exit=0 post_state=active"` (or `result=failed` with sanitized detail).
