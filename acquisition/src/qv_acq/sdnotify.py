"""Minimal sd_notify client.

systemd's readiness and watchdog protocol is a few datagrams to a Unix socket, so
it is implemented here rather than taking a dependency. A no-op when the service
is run outside systemd, which is what makes the same entrypoint usable in tests
and on a developer machine.
"""

from __future__ import annotations

import os
import socket


class SystemdNotifier:
    def __init__(self) -> None:
        self.address = os.environ.get("NOTIFY_SOCKET", "")
        self._socket: socket.socket | None = None
        if self.address:
            self._socket = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM | socket.SOCK_CLOEXEC)

    @property
    def enabled(self) -> bool:
        return self._socket is not None

    def _send(self, message: str) -> None:
        if self._socket is None:
            return
        address = self.address
        # A leading '@' denotes the abstract namespace.
        if address.startswith("@"):
            address = "\0" + address[1:]
        try:
            self._socket.sendto(message.encode("utf-8"), address)
        except OSError:
            # Never let telemetry take the service down.
            pass

    def ready(self, status: str = "") -> None:
        self._send("READY=1" + (f"\nSTATUS={status}" if status else ""))

    def status(self, text: str) -> None:
        self._send(f"STATUS={text}")

    def watchdog(self) -> None:
        self._send("WATCHDOG=1")

    def stopping(self, status: str = "") -> None:
        self._send("STOPPING=1" + (f"\nSTATUS={status}" if status else ""))

    @staticmethod
    def watchdog_interval() -> float | None:
        """Half the configured WatchdogSec, which is where systemd expects pings."""
        raw = os.environ.get("WATCHDOG_USEC")
        if not raw:
            return None
        try:
            microseconds = int(raw)
        except ValueError:
            return None
        return (microseconds / 1_000_000.0) / 2.0 if microseconds > 0 else None
