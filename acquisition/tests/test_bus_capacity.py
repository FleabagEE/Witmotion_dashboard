"""Whether a configuration asks for more than one pair of wires can carry.

`estimate` answers "how fast could this group go with the bus to itself", which
is not the question anybody has. A real appliance polls several groups on
several sensors down one bus, and what any one of them achieves depends on all
the others.

Nothing summed them. The 24-hour acceptance run was configured for 75.2%
utilisation, held 8.4 Hz of a requested 10 for the whole day, and `--check`
reported OK - because well-formedness was the only thing it tested.
"""

from __future__ import annotations

from qv_acq.throughput import MAX_SUSTAINED_UTILISATION, bus_demand

# The configuration the soak actually ran.
SOAK = [
    ("motion", 19, 10.0),
    ("condition_x", 12, 0.33),
    ("condition_y", 12, 0.33),
    ("condition_z", 12, 0.33),
    ("fault_diagnosis", 3, 0.1),
]


def test_the_soak_configuration_is_reported_as_over_capacity() -> None:
    demand = bus_demand(SOAK, 9600)

    # 75.2%, which is the number the config file's own comment predicted and
    # then treated as acceptable.
    assert demand.utilisation == __import__("pytest").approx(0.752, abs=0.01)
    assert not demand.feasible


def test_the_predicted_rate_matches_what_the_soak_measured() -> None:
    """The check against reality, not against itself.

    Over 24 hours the appliance held a median 8.38 Hz. The model has to land
    near that from first principles - transaction sizes and baud rate - or it is
    just a number that happens to be below the configured one.
    """
    demand = bus_demand(SOAK, 9600)
    fixed = sum(ms for _, hz, _, ms in demand.per_group if hz < 1.0)
    motion = next(r for r in demand.per_group if r[0] == "motion")
    achievable = (MAX_SUSTAINED_UTILISATION * 1000.0 - fixed) / motion[2]

    assert 8.0 <= achievable <= 8.8, f"predicted {achievable:.2f} Hz, soak measured 8.38"


def test_the_tilt_only_configuration_fits_with_room_for_the_second_sensor() -> None:
    single = [("motion", 19, 1.0), ("fault_diagnosis", 3, 0.1)]

    assert bus_demand(single, 9600).feasible
    # Both silo sensors share one bus - RS-485 is a bus, not a point-to-point
    # link - so the deployment config is this doubled.
    assert bus_demand(single * 2, 9600).feasible
    assert bus_demand(single * 2, 9600).utilisation < 0.20


def test_demand_scales_with_rate() -> None:
    slow = bus_demand([("motion", 19, 1.0)], 9600)
    fast = bus_demand([("motion", 19, 10.0)], 9600)

    assert fast.milliseconds_per_second == __import__("pytest").approx(
        slow.milliseconds_per_second * 10, rel=0.01
    )


def test_a_faster_bus_carries_more() -> None:
    assert bus_demand(SOAK, 115200).utilisation < bus_demand(SOAK, 9600).utilisation
    # 115200 baud would have made the requested 10 Hz comfortable. The rate was
    # not the problem; the rate at that baud was.
    assert bus_demand(SOAK, 115200).feasible


def test_a_zero_rate_group_costs_nothing() -> None:
    assert bus_demand([("motion", 19, 0.0)], 9600).milliseconds_per_second == 0.0
