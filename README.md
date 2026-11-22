# NOC Center

Repository for the NOC monitoring stack.

This stage contains **one deliverable**: the NOC monitoring **agent** under
[`agent/`](agent/README.md) — a lightweight Bash collector that runs from a
systemd timer, posts a schema-v1 JSON payload over HTTPS to a dashboard ingest
endpoint, and ships with an installer, an uninstaller, systemd units, and a
local mock endpoint for testing.

The dashboard, central database, alerting, and history storage are **out of
scope** for this stage.

## Quick start (operator)

```bash
# on the monitored server, as root
install -m 600 /dev/null /root/noc-token         # paste the per-server token
agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token
```

See [`agent/README.md`](agent/README.md) for prerequisites, configuration,
timers, troubleshooting, token rotation, and uninstall.

## Quick start (developer)

```bash
bash tools/test-agent.sh        # every suite + static checks + end-to-end
```

## Repository layout

| Path | Purpose |
| --- | --- |
| `agent/` | The agent: `collector.sh` core, `lib/`, `panels/`, `modules/`, `systemd/`, `config/`, installer and uninstaller. |
| `tools/tests/` | Unit and contract test suites for every part of the agent. |
| `tools/test-agent.sh` | One command that runs every suite, `bash -n`, ShellCheck (if available), the non-panel acceptance dry run, and the mock end-to-end check. |
| `tools/mock-ingest.py` | Local, loopback-only mock of `POST /api/v1/ingest` (Python 3 stdlib). Never used in production. |
| `docs/superpowers/specs/` | Approved design document and plan this implementation follows. |

## Legacy collector — do not confuse with the agent

The root-level [`collector-cpanel.sh`](collector-cpanel.sh) is the **legacy,
standalone cPanel collector**. It is installed manually as a root cron script,
writes local snapshots/caches for a local PHP page, and has **nothing to do with
the new agent**: it does not use `agent/`, is not installed by
`agent/install.sh`, and is not covered by `tools/test-agent.sh`. It is kept
unchanged for existing deployments; the new agent supersedes it functionally
(and can run side by side with it until migration is finished).