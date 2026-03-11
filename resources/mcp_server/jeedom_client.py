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
        logger.debug("POST %s %s", self.url, params)
        params["apikey"] = self.apikey
        resp = None
        try:
            # Send as form-encoded data — Jeedom's jeeApi.php reads individual POST fields,
            # not a JSON-RPC 2.0 body.
            resp = self.session.post(self.url, data=params, timeout=10)
            logger.debug("Jeedom API response: status=%d body=%s", resp.status_code, resp.text[:500])
            resp.raise_for_status()
            data = resp.json()
            if isinstance(data, dict) and data.get("state") == "error":
                msg = f"Jeedom API error: {data.get('result')}"
                logger.error(msg)
                raise JeedomError(msg)
            result = data.get("result", data) if isinstance(data, dict) else data
            logger.debug("Jeedom API result type=%s len=%s", type(result).__name__, len(result) if isinstance(result, list) else "n/a")
            return result
        except requests.exceptions.JSONDecodeError as exc:
            body = resp.text[:500] if resp is not None else "<no response>"
            msg = f"Jeedom API returned non-JSON (status={resp.status_code}): {body!r}"
            logger.error(msg)
            raise JeedomError(msg) from exc
        except requests.RequestException as exc:
            msg = f"Jeedom API unreachable: {exc}"
            logger.error(msg)
            raise JeedomError(msg) from exc

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
