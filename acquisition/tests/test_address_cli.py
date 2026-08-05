"""Readdressing a sensor, and the refusals that make it safe.

Every WTVB01-485 ships as 0x50. Two on one bus corrupt each other's replies,
which reads as noise rather than as a fault, so each has to be readdressed alone
before any of them share wires.

The dangerous version of this tool writes an address and reports success. What
is tested here is mostly the refusals, and the read-back - a device that landed
somewhere unexpected is recoverable, but only if somebody is told.
"""

from __future__ import annotations

import pytest

from qv_acq import address_cli


class FakeResponse:
    def __init__(self, error: bool = False) -> None:
        self._error = error

    def isError(self) -> bool:  # noqa: N802 - pymodbus spelling
        return self._error


class FakeBus:
    """A bus with a known set of devices on it."""

    def __init__(self, present: set[int], *, write_moves: bool = True) -> None:
        self.present = set(present)
        self.writes: list[tuple[int, int, int]] = []
        self.write_moves = write_moves

    def connect(self) -> bool:
        return True

    def close(self) -> None:
        pass

    def read_holding_registers(self, address, count=1, slave=None):
        if slave in self.present:
            return FakeResponse()
        return FakeResponse(error=True)

    def write_register(self, address, value, slave=None):
        self.writes.append((address, value, slave))

        if address == address_cli.ADDRESS_REGISTER and self.write_moves:
            self.present.discard(slave)
            self.present.add(value)

        return FakeResponse()


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(address_cli.time, "sleep", lambda _: None)


def run(bus: FakeBus, monkeypatch, argv: list[str]) -> int:
    monkeypatch.setattr(address_cli, "ModbusSerialClient", lambda **_: bus)
    return address_cli.main(argv)


def test_it_refuses_when_two_devices_are_on_the_bus(monkeypatch, capsys):
    """The case this tool exists to prevent somebody stumbling into.

    With two devices at the same address there is no way to speak to one of
    them, and a write would reach both or neither.
    """
    bus = FakeBus({0x50, 0x51})

    assert run(bus, monkeypatch, ["--to", "0x52", "--yes"]) == 1
    assert bus.writes == [], "it wrote to a bus it could not address safely"
    assert "one at a time" in capsys.readouterr().err


def test_it_refuses_when_nothing_answers(monkeypatch, capsys):
    bus = FakeBus(set())

    assert run(bus, monkeypatch, ["--to", "0x51", "--yes"]) == 1
    assert bus.writes == []
    assert "Nothing answered" in capsys.readouterr().err


def test_it_refuses_to_create_a_collision(monkeypatch, capsys):
    # Moving 0x50 onto an occupied 0x51 would produce exactly the fault the
    # whole procedure is meant to avoid.
    bus = FakeBus({0x50, 0x51})

    assert run(bus, monkeypatch, ["--from", "0x50", "--to", "0x51", "--yes"]) == 1
    assert bus.writes == []


def test_it_refuses_the_broadcast_address(monkeypatch):
    bus = FakeBus({0x50})

    assert run(bus, monkeypatch, ["--to", "0", "--yes"]) == 2
    assert bus.writes == []


def test_it_refuses_an_address_outside_the_modbus_range(monkeypatch):
    bus = FakeBus({0x50})

    assert run(bus, monkeypatch, ["--to", "0xFF", "--yes"]) == 2
    assert bus.writes == []


def test_it_writes_the_address_and_then_saves(monkeypatch):
    bus = FakeBus({0x50})

    assert run(bus, monkeypatch, ["--to", "0x51", "--yes"]) == 0

    # Order matters: the address is written, then persisted. Saving first would
    # store the old value.
    assert bus.writes[0] == (address_cli.ADDRESS_REGISTER, 0x51, 0x50)
    assert bus.writes[1] == (address_cli.SAVE_REGISTER, address_cli.SAVE_VALUE, 0x50)


def test_it_confirms_the_device_answers_at_the_new_address(monkeypatch, capsys):
    bus = FakeBus({0x50})

    assert run(bus, monkeypatch, ["--to", "0x51", "--yes"]) == 0
    assert "confirmed at 0x51" in capsys.readouterr().out


def test_it_reports_where_the_device_went_when_the_write_did_not_take(monkeypatch, capsys):
    """The read-back is the point of the whole procedure.

    Reporting success without checking is how a sensor that simply moved becomes
    a sensor somebody believes is faulty.
    """
    bus = FakeBus({0x50}, write_moves=False)

    assert run(bus, monkeypatch, ["--to", "0x51", "--yes"]) == 1

    err = capsys.readouterr().err
    assert "No response at 0x51" in err
    assert "0x50" in err, "it did not say where the device actually is"
    assert "not lost" in err


def test_it_does_nothing_when_already_at_the_target(monkeypatch, capsys):
    bus = FakeBus({0x51})

    assert run(bus, monkeypatch, ["--to", "0x51", "--yes"]) == 0
    assert bus.writes == []
    assert "Nothing to do" in capsys.readouterr().out


def test_it_refuses_when_the_stated_current_address_is_wrong(monkeypatch):
    # Guards against readdressing whichever unit happens to be plugged in.
    bus = FakeBus({0x50})

    assert run(bus, monkeypatch, ["--from", "0x55", "--to", "0x51", "--yes"]) == 1
    assert bus.writes == []
