"""Jeedom internal API client."""

import logging
from typing import Any

import requests

logger = logging.getLogger(__name__)


class JeedomError(Exception):
    """Raised when the Jeedom API returns an error."""


class JeedomClient:
    """Thin wrapper around the Jeedom JSON-RPC internal API."""

    def __init__(self, url: str, apikey: str):
        self.url = url
        self.apikey = apikey
        self.session = requests.Session()
        # Disable SSL verification for local loopback calls
        self.session.verify = False

    def _call(self, params: dict) -> Any:
        params["apikey"] = self.apikey
        try:
            resp = self.session.post(self.url, json=params, timeout=10)
            resp.raise_for_status()
            data = resp.json()
            if isinstance(data, dict) and data.get("state") == "error":
                raise JeedomError(f"Jeedom API error: {data.get('result')}")
            return data.get("result", data)
        except requests.RequestException as exc:
            raise JeedomError(f"Jeedom API unreachable: {exc}") from exc

    # ------------------------------------------------------------------
    # Equipment
    # ------------------------------------------------------------------

    def get_all_equipment(self) -> list[dict]:
        """Return all equipment (eqLogic) from Jeedom."""
        result = self._call({"type": "eqLogic", "action": "getAll"})
        return result if isinstance(result, list) else []

    def get_equipment(self, equipment_id: int) -> dict | None:
        """Return a single equipment by ID."""
        return self._call({"type": "eqLogic", "action": "get", "id": equipment_id})

    def get_commands(self, equipment_id: int) -> list[dict]:
        """Return all commands for a given equipment."""
        result = self._call({"type": "cmd", "action": "getAll", "eqLogic_id": equipment_id})
        return result if isinstance(result, list) else []

    def exec_command(self, command_id: int, value: str | None = None) -> Any:
        """Execute an action command."""
        params: dict = {"type": "cmd", "action": "execCmd", "id": command_id}
        if value is not None:
            params["value"] = value
        return self._call(params)

    # ------------------------------------------------------------------
    # Scenarios
    # ------------------------------------------------------------------

    def get_all_scenarios(self) -> list[dict]:
        """Return all scenarios from Jeedom."""
        result = self._call({"type": "scenario", "action": "getAll"})
        return result if isinstance(result, list) else []

    def run_scenario(self, scenario_id: int) -> Any:
        """Trigger a scenario."""
        return self._call({
            "type": "scenario",
            "action": "changeState",
            "id": scenario_id,
            "state": "start",
        })
