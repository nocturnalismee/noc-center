#!/usr/bin/env python3
"""Local mock of the NOC ingest endpoint for agent tests.

Python 3 standard library only. Never used in production.

    python3 tools/mock-ingest.py --token <token> [--port 8787] \
        [--log requests.log] [--dump-dir dumps] [--fail-first N] [--delay S]

Endpoints:
    GET  /health          -> 200 {"ok":true} (no auth)
    POST /api/v1/ingest   -> 202 {"ok":true} for a valid token and JSON body
                             401 bad/missing token, 400 invalid JSON,
                             413 body over 65536 bytes, 405 anything else

Every request appends "METHOD PATH STATUS BYTES" to --log. The token is
compared in memory and never written to the log or to a dump file.
"""

import argparse
import json
import os
import sys
import threading
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

INGEST_PATH = "/api/v1/ingest"
HEALTH_PATH = "/health"
MAX_BODY_BYTES = 65536


def _parse_args(argv):
    parser = argparse.ArgumentParser(description="Mock NOC ingest endpoint")
    parser.add_argument("--host", default="127.0.0.1", help="bind address (default 127.0.0.1)")
    parser.add_argument("--port", type=int, default=8787, help="listen port (default 8787)")
    parser.add_argument(
        "--token",
        default=os.environ.get("NOC_MOCK_TOKEN", ""),
        help="expected bearer token (default $NOC_MOCK_TOKEN)",
    )
    parser.add_argument("--fail-first", type=int, default=0, metavar="N",
                        help="return --fail-status (default 500) for the first N valid requests")
    parser.add_argument("--fail-status", type=int, default=500, metavar="CODE",
                        help="status returned while --fail-first is active (default 500)")
    parser.add_argument("--delay", type=float, default=0.0, metavar="SECONDS",
                        help="sleep before responding")
    parser.add_argument("--log", default=None, metavar="FILE",
                        help="append 'METHOD PATH STATUS BYTES' per request")
    parser.add_argument("--dump-dir", default=None, metavar="DIR",
                        help="write accepted bodies as payload-<ts>-<pid>.json")
    parser.add_argument("--max-bytes", type=int, default=MAX_BODY_BYTES, metavar="N",
                        help="reject bodies larger than N bytes with 413 (default 65536)")
    parser.add_argument("--status-400", action="store_true",
                        help="always reject valid requests with 400 (test no-retry path)")
    return parser.parse_args(argv)


class State:
    def __init__(self, args):
        self.token = args.token or ""
        self.fail_first = max(0, args.fail_first)
        self.fail_status = args.fail_status if 400 <= args.fail_status <= 599 else 500
        self.delay = max(0.0, args.delay)
        self.log = args.log
        self.dump_dir = args.dump_dir
        self.max_bytes = max(0, args.max_bytes)
        self.status_400 = args.status_400
        self.lock = threading.Lock()
        self.valid_requests = 0


class Handler(BaseHTTPRequestHandler):
    server_version = "noc-mock-ingest/0.1.0"
    sys_version = ""
    protocol_version = "HTTP/1.1"

    # -- helpers -----------------------------------------------------------
    @property
    def state(self):
        # One State object per server; --fail-first is per process, not per
        # connection, so the mock order is deterministic for a test run.
        return self.server.state  # type: ignore[attr-defined]

    def log_message(self, fmt, *args):  # silence default stderr logging
        pass

    def _record(self, status, size):
        if not self.state.log:
            return
        try:
            with open(self.state.log, "a", encoding="utf-8") as fh:
                fh.write("%s %s %d %d\n" % (self.command, self.path, status, size))
        except OSError:
            pass

    def _respond(self, status, body):
        payload = json.dumps(body).encode("utf-8") + b"\n"
        try:
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            pass
        self._record(status, len(payload))
        self.close_connection = True

    def _drain(self, size):
        remaining = size
        while remaining > 0:
            chunk = self.rfile.read(min(remaining, 65536))
            if not chunk:
                break
            remaining -= len(chunk)

    def _read_body(self):
        """Returns (body, status) where status is None for a readable body."""
        length = self.headers.get("Content-Length")
        if length is None:
            return b"", None
        try:
            size = int(length)
        except ValueError:
            return b"", HTTPStatus.BAD_REQUEST
        if size < 0:
            return b"", HTTPStatus.BAD_REQUEST
        if size > self.state.max_bytes:
            self._drain(size)
            return b"", HTTPStatus.REQUEST_ENTITY_TOO_LARGE
        return self.rfile.read(size), None

    def _auth_ok(self):
        header = self.headers.get("Authorization", "")
        if not header.startswith("Bearer "):
            return False
        supplied = header[len("Bearer "):].strip()
        expected = self.state.token
        if not expected:
            return False
        # Constant-time-ish comparison without importing extra modules.
        if len(supplied) != len(expected):
            return False
        diff = 0
        for a, b in zip(supplied, expected):
            diff |= ord(a) ^ ord(b)
        return diff == 0

    def _dump(self, body):
        if not self.state.dump_dir:
            return
        try:
            os.makedirs(self.state.dump_dir, exist_ok=True)
            name = "payload-%d-%d.json" % (time.time_ns(), os.getpid())
            path = os.path.join(self.state.dump_dir, name)
            with open(path, "wb") as fh:
                fh.write(body)
        except OSError as exc:
            print("mock-ingest: dump failed: %s" % exc, file=sys.stderr)

    # -- verbs -------------------------------------------------------------
    def do_GET(self):
        if self.path == HEALTH_PATH:
            self._respond(HTTPStatus.OK, {"ok": True})
        else:
            self._respond(HTTPStatus.METHOD_NOT_ALLOWED, {"ok": False, "error": "method not allowed"})

    def do_POST(self):
        if self.path != INGEST_PATH:
            body, err = self._read_body()
            if err is not None:
                self._respond(err, {"ok": False, "error": "request rejected"})
            else:
                self._respond(HTTPStatus.METHOD_NOT_ALLOWED, {"ok": False, "error": "method not allowed"})
            return

        body, err = self._read_body()
        if err is not None:
            self._respond(err, {"ok": False, "error": "request rejected"})
            return

        if not self._auth_ok():
            self._respond(HTTPStatus.UNAUTHORIZED, {"ok": False, "error": "unauthorized"})
            return

        try:
            doc = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            self._respond(HTTPStatus.BAD_REQUEST, {"ok": False, "error": "invalid json"})
            return
        if not isinstance(doc, dict):
            self._respond(HTTPStatus.BAD_REQUEST, {"ok": False, "error": "invalid json"})
            return

        with self.state.lock:
            self.state.valid_requests += 1
            index = self.state.valid_requests

        if self.state.delay:
            time.sleep(self.state.delay)

        if self.state.status_400:
            self._respond(HTTPStatus.BAD_REQUEST, {"ok": False, "error": "rejected by --status-400"})
            return

        if index <= self.state.fail_first:
            try:
                status = HTTPStatus(self.state.fail_status)
            except ValueError:
                status = HTTPStatus.INTERNAL_SERVER_ERROR
            self._respond(status, {"ok": False, "error": "injected failure"})
            return

        summary = {
            "schema_version": doc.get("schema_version"),
            "agent_id": (doc.get("agent") or {}).get("id") if isinstance(doc.get("agent"), dict) else None,
            "hostname": (doc.get("agent") or {}).get("hostname") if isinstance(doc.get("agent"), dict) else None,
            "panel": (doc.get("agent") or {}).get("panel") if isinstance(doc.get("agent"), dict) else None,
        }
        print("mock-ingest: accepted %s" % json.dumps(summary, sort_keys=True), file=sys.stderr)
        self._dump(body)
        self._respond(HTTPStatus.ACCEPTED, {"ok": True})

    def _method_not_allowed(self):
        self._respond(HTTPStatus.METHOD_NOT_ALLOWED, {"ok": False, "error": "method not allowed"})

    do_PUT = _method_not_allowed
    do_DELETE = _method_not_allowed
    do_PATCH = _method_not_allowed
    do_HEAD = _method_not_allowed
    do_OPTIONS = _method_not_allowed


def main(argv=None):
    args = _parse_args(sys.argv[1:] if argv is None else argv)
    if not args.token:
        print("mock-ingest: --token (or NOC_MOCK_TOKEN) is required", file=sys.stderr)
        return 2
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    server.daemon_threads = True
    server.state = State(args)  # type: ignore[attr-defined]
    print("mock-ingest: listening on http://%s:%d%s" % (args.host, server.server_address[1], INGEST_PATH),
          file=sys.stderr)
    try:
        server.serve_forever(poll_interval=0.1)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())