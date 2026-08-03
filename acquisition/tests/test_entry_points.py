"""Every documented command must actually resolve.

Written after `python -m qv_acq.probe` was documented and shipped broken: the
package had never been installed into the venv, so only the systemd units - which
carry their own PYTHONPATH - could run anything. The tests passed throughout,
because pytest sets `pythonpath` in pyproject.toml and papers over exactly this.

So these tests read the entry points out of pyproject.toml rather than naming
them, and check each one resolves to something callable. A new tool that is
declared but misspelled, or renamed without updating the declaration, fails here
instead of in front of an operator with the service stopped and the sensor in
their hand.
"""

from __future__ import annotations

import importlib
import tomllib
from pathlib import Path

import pytest

PYPROJECT = Path(__file__).resolve().parents[1] / "pyproject.toml"


def declared_scripts() -> dict[str, str]:
    return tomllib.loads(PYPROJECT.read_text()).get("project", {}).get("scripts", {})


def test_scripts_are_declared() -> None:
    scripts = declared_scripts()

    # The operator-facing tools. Losing one silently is how the probe shipped
    # unrunnable.
    assert "qv-probe" in scripts
    assert "qv-calibrate" in scripts
    assert "qv-acq" in scripts


@pytest.mark.parametrize("name", sorted(declared_scripts()))
def test_each_entry_point_resolves_to_a_callable(name: str) -> None:
    target = declared_scripts()[name]
    module_name, _, attribute = target.partition(":")

    module = importlib.import_module(module_name)

    assert hasattr(module, attribute), f"{name} points at {target}, which does not exist"
    assert callable(getattr(module, attribute))


@pytest.mark.parametrize("name", sorted(declared_scripts()))
def test_each_tool_builds_its_parser_without_touching_hardware(name: str) -> None:
    # --help must work on a machine with no sensor attached, which is where
    # somebody reads it first.
    module_name, _, _ = declared_scripts()[name].partition(":")
    module = importlib.import_module(module_name)

    if not hasattr(module, "build_parser"):
        pytest.skip(f"{name} has no build_parser")

    parser = module.build_parser()

    assert parser.format_help()
