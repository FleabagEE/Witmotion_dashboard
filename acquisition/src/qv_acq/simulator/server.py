"""Serial simulator: serves simulated Modbus devices on a virtual serial port.

A pseudo-terminal pair stands in for the USB-RS485 bridge. The acquisition
engine opens the slave side with pyserial exactly as it would open
``/dev/ttyUSB0``, so nothing above the transport knows it is talking to a
simulation. This is what allows the engine, spool, discovery, and soak tests to
be built and validated before hardware is available.
"""

from __future__ import annotations

import os
import pty
import random
import select
import threading
import time
import tty
from types import TracebackType

from .device import SimulatedDevice
from .rtu import FaultInjection, handle_request

#: A function 0x03/0x04/0x06 request is always exactly this long.
REQUEST_LENGTH = 8


class SimulatorServer:
    """Background thread serving one or more simulated slaves on a pty."""

    def __init__(
        self,
        devices: dict[int, SimulatedDevice],
        *,
        faults: FaultInjection | None = None,
        response_delay_ms: float = 0.0,
        seed: int = 0,
        time_source=time.monotonic,
    ) -> None:
        if not devices:
            raise ValueError("at least one simulated device is required")
        self.devices = devices
        self.faults = faults or FaultInjection()
        self.response_delay_ms = response_delay_ms
        self._rng = random.Random(seed)
        self._time = time_source
        self._master_fd: int | None = None
        self._slave_fd: int | None = None
        self._port: str | None = None
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()
        self._started_at = 0.0
        self.requests_served = 0
        self.requests_dropped = 0
        #: Every Modbus function code this server has been asked for. Lets a test
        #: assert that discovery is read-only by observation rather than by
        #: reading the discovery code and trusting it.
        self.function_codes_seen: set[int] = set()

    @property
    def port(self) -> str:
        if self._port is None:
            raise RuntimeError("simulator is not running")
        return self._port

    def start(self) -> str:
        """Open the pty and begin serving. Returns the client-side device path."""
        master_fd, slave_fd = pty.openpty()
        tty.setraw(master_fd)
        tty.setraw(slave_fd)
        self._master_fd = master_fd
        self._slave_fd = slave_fd
        self._port = os.ttyname(slave_fd)
        self._started_at = self._time()
        self._stop.clear()
        self._thread = threading.Thread(target=self._serve, name="modbus-simulator", daemon=True)
        self._thread.start()
        return self._port

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=2.0)
            self._thread = None
        for fd in (self._master_fd, self._slave_fd):
            if fd is not None:
                try:
                    os.close(fd)
                except OSError:
                    pass
        self._master_fd = self._slave_fd = None
        self._port = None

    def __enter__(self) -> "SimulatorServer":
        self.start()
        return self

    def __exit__(self, exc_type: type[BaseException] | None, exc: BaseException | None, tb: TracebackType | None) -> None:
        self.stop()

    def _serve(self) -> None:
        assert self._master_fd is not None
        buffer = bytearray()

        while not self._stop.is_set():
            try:
                readable, _, _ = select.select([self._master_fd], [], [], 0.05)
            except (OSError, ValueError):
                break
            if not readable:
                continue

            try:
                chunk = os.read(self._master_fd, 512)
            except OSError:
                break
            if not chunk:
                continue
            buffer += chunk

            while len(buffer) >= REQUEST_LENGTH:
                frame = bytes(buffer[:REQUEST_LENGTH])
                if len(frame) >= 2 and frame[0] in self.devices:
                    self.function_codes_seen.add(frame[1])
                response = handle_request(
                    frame,
                    self.devices,
                    self._time() - self._started_at,
                    faults=self.faults,
                    rng=self._rng,
                )

                if response is None and not _looks_addressed(frame, self.devices):
                    # Not ours, or line noise: drop one byte and resynchronise
                    # rather than discarding a whole frame's worth of data.
                    del buffer[0]
                    continue

                del buffer[:REQUEST_LENGTH]

                if response is None:
                    self.requests_dropped += 1
                    continue

                if self.response_delay_ms:
                    time.sleep(self.response_delay_ms / 1000.0)

                try:
                    os.write(self._master_fd, response)
                except OSError:
                    return
                self.requests_served += 1


def _looks_addressed(frame: bytes, devices: dict[int, SimulatedDevice]) -> bool:
    """Whether a frame plausibly targets one of our slaves."""
    return bool(frame) and frame[0] in devices
