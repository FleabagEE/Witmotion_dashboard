"""Exclusive ownership of a serial port.

Two processes polling one RS-485 bus interleave their frames. The symptom is a
storm of CRC errors and timeouts that looks exactly like a wiring fault, and
operators lose days to it. An advisory lock makes the second process fail
immediately with a clear message instead.

The lock is held on a lockfile keyed to the resolved device path, not on the
device node itself, so it works with the udev aliases the appliance uses and
survives the alias and the real node being different inodes.
"""

from __future__ import annotations

import errno
import fcntl
import os
from pathlib import Path
from types import TracebackType

DEFAULT_LOCK_DIR = Path(os.environ.get("QV_LOCK_DIR", "/var/lock"))


class PortBusyError(RuntimeError):
    """Raised when another process already owns the port."""


def lock_path(device: str, lock_dir: Path | None = None) -> Path:
    """Lockfile for *device*, keyed on its fully resolved path."""
    resolved = Path(device).resolve()
    slug = str(resolved).strip("/").replace("/", "-")
    return (lock_dir or DEFAULT_LOCK_DIR) / f"qv-acq-{slug}.lock"


class PortLock:
    """Advisory exclusive lock over one serial device."""

    def __init__(self, device: str, *, lock_dir: Path | None = None) -> None:
        self.device = device
        self.path = lock_path(device, lock_dir)
        self._fd: int | None = None

    def acquire(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        fd = os.open(self.path, os.O_RDWR | os.O_CREAT, 0o644)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            os.close(fd)
            if exc.errno in (errno.EACCES, errno.EAGAIN):
                raise PortBusyError(
                    f"{self.device} is already owned by another acquisition process "
                    f"(lock: {self.path}). Exactly one process may own an RS-485 bus."
                ) from exc
            raise
        os.truncate(fd, 0)
        os.write(fd, f"{os.getpid()}\n".encode())
        self._fd = fd

    def release(self) -> None:
        if self._fd is None:
            return
        try:
            fcntl.flock(self._fd, fcntl.LOCK_UN)
        finally:
            os.close(self._fd)
            self._fd = None

    @property
    def held(self) -> bool:
        return self._fd is not None

    def __enter__(self) -> "PortLock":
        self.acquire()
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        self.release()
