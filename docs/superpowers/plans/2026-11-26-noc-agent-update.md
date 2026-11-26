# NOC Agent Update Script Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `agent/update.sh`, a safe wrapper around the existing idempotent installer that upgrades the installed collector while preserving config/token, backing up the previous install, and rolling back on failure.

**Architecture:** `update.sh` resolves the same install paths as `install.sh`, verifies the source tree and existing install, takes an update lock, snapshots `LIBEXEC`, units, config, and token, then runs the new source's `install.sh` without `--force`. A failed install restores the snapshot and reactivates the previous timers; a successful install prunes old snapshots but keeps the most recent ones.

**Tech Stack:** Bash 4+, systemd, existing `agent/lib/install.sh` helpers, `flock`, `cp -a`, `rsync`-free POSIX-ish filesystem operations, `tools/test-agent.sh` shell suites, PHP feature test for the dashboard bundle.

**Spec:** `docs/superpowers/specs/2026-11-22-noc-agent-foundation-design.md` (agent install/update contract; §13 install and uninstall, §16 limitations)

## Global Constraints

- No automatic update and no dashboard push. `update.sh` is run manually from a newer source tree on the monitored server.
- Never overwrite `/etc/noc-agent/agent.conf` or `/etc/noc-agent/token` during an update.
- Never use `--force` implicitly.
- The installed collector is the source of truth only until the new source's `install.sh` succeeds; a failed run must restore the old installed files.
- Backups live under `STATE_DIR/updates/` with mode `0700`; keep the five newest snapshots.
- Real-system runs require root; prefixed runs are for tests and must use `--prefix`.
- `--dry-run`, `--no-systemctl`, and `--no-prime` must pass through to `install.sh`.
- `--no-systemctl` must skip every systemd call, including rollback reactivation.
- All paths must be derived from `agent/lib/install.sh`; do not hardcode `/usr/local/libexec/noc-agent`, `/etc/noc-agent`, or `/etc/systemd/system` in `update.sh`.
- The update script must not read, print, or copy the token into logs.
- Keep `agent/install.sh` idempotent; `update.sh` is a wrapper, not a second installer.
- Agent version is informational. Do not skip an update solely because `AGENT_VERSION` is unchanged; a source tree can contain fixes without a version bump.
- ShellCheck must stay clean for every `agent/**/*.sh` and `tools/tests/*.sh` file.

## Review Focus

1. A failed new `install.sh` leaves the previously working collector and units in place, and the timers still run.
2. Config and token bytes and modes survive a successful update unchanged.
3. `--dry-run` makes no backup, lock, install, or systemd changes.
4. A prefixed install cannot escape its prefix through a symlink, `..`, or backup path.
5. A source tree missing `install.sh`, `collector.sh`, or `lib/install.sh` is rejected before any installed file changes.

---

## File Structure

### New files

- `agent/update.sh` — manual update wrapper.
- `tools/tests/update_test.sh` — update behavior, backup, dry-run, and rollback tests.

### Modified files

- `tools/test-agent.sh` — add `update_test.sh` to `SUITES` and ShellCheck input.
- `tests/Feature/AgentDownloadTest.php` — assert the bundle contains `noc-agent/update.sh`.
- `agent/README.md` — document update procedure, rollback, and backup retention.
- Possibly `agent/lib/install.sh` — only if a shared path/helper is missing; prefer reusing it unchanged.

---

## Task 1: Implement the update wrapper and its tests

**Files:**
- Create: `agent/update.sh`
- Create: `tools/tests/update_test.sh`
- Test: `tools/tests/update_test.sh`
- Test: `tools/test-agent.sh`

**Interfaces:**
- `agent/update.sh [--source DIR] [--prefix DIR] [--dry-run] [--no-systemctl] [--no-prime] [--test] [--check] [--help]`
- Default source: the directory containing `update.sh`.
- `--check` prints installed and source `AGENT_VERSION` values and exits without changing anything.
- Exit codes: `0` success or check, `1` usage/precondition failure, `2` install failed and rollback completed.

- [ ] **Step 1: Write the failing update tests**

Add `'noc-agent/update.sh'` to the expected file list in `tests/Feature/AgentDownloadTest.php` and run `./tools/test.sh --filter=AgentDownloadTest`; it must fail because the update script does not exist yet.

Create `tools/tests/update_test.sh` with the same starting pattern as `tools/tests/install_test.sh`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
printf 'abcdefghijklmnop\n' > "$tmp/token"
chmod 600 "$tmp/token"

prefix="$tmp/prefix"
agent/install.sh --prefix "$prefix" \
  --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --interval 30 --no-systemctl --no-prime >/dev/null
```

Test each behavior:

1. `--check` prints installed/source versions and exits 0.
2. A source copy with a marker appended to `collector.sh` updates the installed collector and preserves config/token checksums.
3. `--dry-run` leaves the installed collector and snapshot directory untouched.
4. A source whose `install.sh` exits 1 causes exit 2 and restores the old installed collector.
5. A source missing `collector.sh` exits 1 before creating a backup.
6. A source path outside the prefix does not allow the installed paths to escape the prefix.
7. `--no-systemctl` output contains no `systemctl` invocation.

Use `cp -a agent "$tmp/source"` for the update source, then append a comment to `$tmp/source/collector.sh`. For the failure case, replace `$tmp/source/install.sh` with a script that exits 1 only after the update wrapper has made its snapshot.

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash tools/tests/update_test.sh`
Expected: FAIL because `agent/update.sh` does not exist.

- [ ] **Step 3: Implement `agent/update.sh`**

Use the existing shared helpers:

```bash
source "$SELF_DIR/lib/install.sh"
```

Resolve paths with `resolve_paths`, enforce root/prefix with `require_root_or_prefix`, and parse `--source` separately from the common `--prefix/--dry-run/--no-systemctl` options. The script must:

1. Resolve `SOURCE` to a canonical directory; require `install.sh`, `collector.sh`, and `lib/install.sh`.
2. Require an existing `CONFIG_FILE`, `TOKEN_FILE`, and `LIBEXEC/collector.sh`; otherwise print “run install.sh first” and exit 1.
3. Print installed and source `AGENT_VERSION` values by extracting the first `AGENT_VERSION="..."` from each `collector.sh`.
4. For `--check`, stop after step 3.
5. For `--dry-run`, call the source `install.sh --dry-run` with the same `--prefix/--no-systemctl/--no-prime/--test` flags and stop.
6. Take `flock -n` on `$STATE_DIR/update.lock`.
7. Create `$STATE_DIR/updates/<UTC timestamp>/` with mode 700; copy `LIBEXEC`, `CONFIG_FILE`, `TOKEN_FILE`, and all six unit files (`noc-agent.service`, `noc-agent@.service`, and the four timers) into it.
8. Run `bash "$SOURCE/install.sh"` without `--force`, forwarding `--prefix`, `--no-systemctl`, `--no-prime`, and `--test`.
9. On success, prune snapshots so only the five newest remain.
10. On failure, restore `LIBEXEC`, config, token, and units from the snapshot; run `systemctl_do daemon-reload` and `systemctl_do enable --now` for the timers unless `--no-systemctl`; exit 2.

Do not print the token. Do not copy a token into a world-readable path.

- [ ] **Step 4: Add the suite to the agent test runner**

In `tools/test-agent.sh`:
- add `update_test.sh` to `SUITES` after `install_test.sh`;
- add `agent/update.sh` to the ShellCheck file list.

- [ ] **Step 5: Run the focused shell tests**

Run:

```bash
bash tools/tests/update_test.sh
bash tools/tests/install_test.sh
bash -n agent/update.sh tools/tests/update_test.sh
```

Expected: all PASS; no syntax errors.

- [ ] **Step 6: Run the full agent suite**

Run: `bash tools/test-agent.sh`
Expected: `test-agent: ALL PASS`.

- [ ] **Step 7: Commit**

```bash
git add agent/update.sh tools/tests/update_test.sh tools/test-agent.sh
git commit -m "feat(agent): add safe self-update wrapper"
```

---

## Task 2: Ship the update script in the dashboard bundle and document it

**Files:**
- Modify: `agent/README.md`

**Interfaces:**
- The dashboard tarball includes `noc-agent/update.sh` because Task 1 added it to `agent/`.
- README documents the manual upgrade flow and rollback/backup location.

- [ ] **Step 1: Document the update flow**

Add a README section after installation:

```bash
# download the newer bundle
sudo curl -fsSL https://monitor.example.com/agent/download -o /root/noc-agent.tar.gz
sudo rm -rf /root/noc-agent-new
sudo mkdir -p /root/noc-agent-new
sudo tar -xzpf /root/noc-agent.tar.gz -C /root/noc-agent-new --strip-components=1

# check versions and preview
sudo /root/noc-agent-new/update.sh --check
sudo /root/noc-agent-new/update.sh --dry-run

# apply
sudo /root/noc-agent-new/update.sh
```

Document:
- config/token are preserved;
- backups are under `/var/lib/noc-agent/updates/` and the five newest are kept;
- a failed update rolls back and exits 2;
- `--test` runs the installer's post-install collection test;
- `--no-systemctl`/`--no-prime` exist for containers/tests;
- there is still no automatic update from the dashboard.

- [ ] **Step 2: Run the focused tests**

Run:

```bash
./tools/test.sh --filter=AgentDownloadTest
bash tools/test-agent.sh
```

Expected: PASS; the tarball contains `update.sh` (asserted in Task 1).

- [ ] **Step 3: Commit**

```bash
git add agent/README.md
git commit -m "docs: document agent update flow"
```

---

## Task 3: Final verification

**Files:**
- No expected production changes.

- [ ] **Step 1: Run the full PHP and agent suites**

Run:

```bash
./tools/test.sh
bash tools/test-agent.sh
```

Expected: PHP suite passes with Pint clean; `test-agent: ALL PASS`.

- [ ] **Step 2: Verify the update script in a scratch prefix**

Run the `update_test.sh` cases manually once more, including:
- successful update;
- `--check`;
- `--dry-run`;
- forced installer failure and rollback.

- [ ] **Step 3: Report**

Report commit SHAs, test counts, backup/rollback behavior, and any deferred items. Do not push or tag without the user's approval.

---

## Self-Review

- [ ] Update wrapper uses `lib/install.sh` paths and does not duplicate installer logic.
- [ ] Config/token are backed up and preserved; no secret is printed.
- [ ] Failed install restores `LIBEXEC`, units, config, and token.
- [ ] Dry-run makes no filesystem or systemd changes.
- [ ] Source validation runs before any backup or install.
- [ ] Prefixed tests cannot escape the prefix.
- [ ] The dashboard tarball includes `update.sh`.
- [ ] README explains manual update, check, dry-run, rollback, and backup retention.
