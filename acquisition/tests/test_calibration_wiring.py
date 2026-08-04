"""That a calibration file actually reaches the readings.

The calibration subsystem was complete and unreachable. A file format, an apply
step, a PROCESSED value class, an ellipsoid solver and a CLI all existed and
were tested; `SensorBinding.calibration` existed and defaulted to identity; and
nothing anywhere called `calibration.load()`. A calibration could be fitted,
written, validated and installed, and every reading passed through untouched.

These tests are about the wire, not the arithmetic.
"""

from __future__ import annotations

import textwrap

from qv_acq.calibration import IDENTITY, load
from qv_acq.config import ApplianceConfig


CONFIG = """
appliance_id: QV-TEST
spool: {path: /tmp/qv-test-spool.db}
buses:
  - bus_id: BUS-001
    adapter_id: A1
    port: /dev/null
    baud: 9600
    sensors:
      - sensor_id: SENSOR-001
        model: WTVB01-485
        slave_id: 0x50
        groups: [motion]
        poll_hz: {motion: 1}
"""


def test_a_binding_takes_the_calibration_for_its_own_sensor(tmp_path):
    path = tmp_path / "calibration.yaml"
    path.write_text(textwrap.dedent("""
        sensors:
          SENSOR-001:
            axes:
              accel_z: {gain: 0.9624, offset: 0.0}
          SENSOR-002:
            axes:
              accel_z: {gain: 1.5, offset: 0.0}
    """))

    config_path = tmp_path / "acquisition.yaml"
    config_path.write_text(CONFIG)
    config = ApplianceConfig.load(config_path)

    binding = config.buses[0].sensors[0].to_binding(load(path))

    assert binding.calibration.axes["accel_z"].gain == 0.9624, "took another sensor's numbers"


def test_a_sensor_with_no_entry_is_left_uncalibrated(tmp_path):
    path = tmp_path / "calibration.yaml"
    path.write_text("sensors:\n  SOMEBODY-ELSE:\n    axes:\n      accel_z: {gain: 2.0}\n")

    config_path = tmp_path / "acquisition.yaml"
    config_path.write_text(CONFIG)
    config = ApplianceConfig.load(config_path)

    # An appliance must never apply a correction nobody chose for it.
    assert config.buses[0].sensors[0].to_binding(load(path)).calibration is IDENTITY


def test_no_calibration_argument_means_identity(tmp_path):
    config_path = tmp_path / "acquisition.yaml"
    config_path.write_text(CONFIG)
    config = ApplianceConfig.load(config_path)

    assert config.buses[0].sensors[0].to_binding().calibration is IDENTITY


def test_the_measured_bench_calibration_brings_gravity_to_one(tmp_path):
    """The three gains measured on the unit, checked end to end.

    Each axis was placed along gravity and its reading taken as its gain, which
    is valid on this device precisely because it reports unsigned magnitudes:
    up and down are indistinguishable, so three placements cover what six
    normally would.
    """
    path = tmp_path / "calibration.yaml"
    path.write_text(textwrap.dedent("""
        sensors:
          SENSOR-001:
            axes:
              accel_x: {gain: 0.99756}
              accel_y: {gain: 1.00586}
              accel_z: {gain: 0.96240}
    """))

    axes = load(path)["SENSOR-001"].axes

    # What the sensor read with each axis vertical must come back as 1 g.
    assert abs(axes["accel_x"].apply(0.99756) - 1.0) < 1e-6
    assert abs(axes["accel_y"].apply(1.00586) - 1.0) < 1e-6
    assert abs(axes["accel_z"].apply(0.96240) - 1.0) < 1e-6
