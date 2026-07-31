"""Discovery and loading of versioned YAML sensor profiles."""

from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

import yaml

from .schema import SensorProfile

#: Repository-relative default; overridable for packaged deployments.
DEFAULT_PROFILE_DIR = Path(__file__).resolve().parents[4] / "profiles"


def profile_dir() -> Path:
    return Path(os.environ.get("QV_PROFILE_DIR", DEFAULT_PROFILE_DIR))


def load_file(path: Path) -> SensorProfile:
    """Load and validate a single profile file."""
    with path.open("r", encoding="utf-8") as handle:
        raw = yaml.safe_load(handle)
    if not isinstance(raw, dict):
        raise ValueError(f"{path}: profile must be a YAML mapping")
    return SensorProfile.model_validate(raw)


@lru_cache(maxsize=1)
def load_all() -> dict[str, SensorProfile]:
    """Load every profile, keyed by model identifier.

    Cached because profiles are immutable at runtime; a profile change is a
    deployment, not a hot reload, so the acquisition service restarts cleanly and
    the configuration revision is auditable.
    """
    directory = profile_dir()
    if not directory.is_dir():
        raise FileNotFoundError(f"profile directory not found: {directory}")

    profiles: dict[str, SensorProfile] = {}
    for path in sorted(directory.glob("*.yaml")):
        profile = load_file(path)
        if profile.model in profiles:
            raise ValueError(f"duplicate profile for model {profile.model!r}")
        profiles[profile.model] = profile

    if not profiles:
        raise FileNotFoundError(f"no profiles found in {directory}")
    return profiles


def get(model: str) -> SensorProfile:
    try:
        return load_all()[model]
    except KeyError as exc:
        known = ", ".join(sorted(load_all())) or "none"
        raise KeyError(f"unknown sensor model {model!r} (known: {known})") from exc
