"""Jeedom internal API client (JSON-RPC 2.0)."""

import logging
import time
from typing import Any

import requests

logger = logging.getLogger(__name__)


class JeedomError(Exception):
    """Raised when the Jeedom API returns an error."""


class JeedomClient:
    """Thin wrapper around the Jeedom JSON-RPC 2.0 internal API."""

    def __init__(self, url: str, apikey: str):
        self.url = url
        self.apikey = apikey
        self._req_id = 0
        self.session = requests.Session()
        # Disable SSL verification for local loopback calls
        self.session.verify = False

    def _call(self, method: str, params: dict | None = None) -> Any:
        self._req_id += 1
        payload = {
            "jsonrpc": "2.0",
            "method": method,
            "params": {"apikey": self.apikey, **(params or {})},
            "id": self._req_id,
        }
        logger.debug("POST %s method=%s params=%s", self.url, method, params)
        resp = None
        start_time = time.monotonic()
        try:
            resp = self.session.post(self.url, json=payload, timeout=10)
            logger.debug("Jeedom API response: status=%d body=%s", resp.status_code, resp.text[:500])
            resp.raise_for_status()
            data = resp.json()
            if "error" in data:
                msg = f"Jeedom API error: {data['error']}"
                logger.error(msg)
                raise JeedomError(msg)
            result = data.get("result")
            count = len(result) if isinstance(result, list) else None
            elapsed = (time.monotonic() - start_time) * 1000
            logger.info(f"{method} completed in {elapsed:.0f}ms" + (f" ({count} items)" if count is not None else ""))
            return result
        except requests.exceptions.JSONDecodeError as exc:
            body = resp.text[:500] if resp is not None else "<no response>"
            msg = f"Jeedom API returned non-JSON (status={resp.status_code if resp else '?'}): {body!r}"
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
        result = self._call("eqLogic::all")
        return result if isinstance(result, list) else []

    def get_all_objects(self) -> dict[str, str]:
        """Return a mapping of object ID → name (rooms/zones in Jeedom)."""
        result = self._call("jeeObject::all")
        if not isinstance(result, list):
            return {}
        return {str(obj["id"]): obj.get("name", "") for obj in result if "id" in obj}

    def get_equipment(self, equipment_id: int) -> dict | None:
        """Return a single equipment by ID."""
        return self._call("eqLogic::byId", {"id": equipment_id})

    def get_commands(self, equipment_id: int) -> list[dict]:
        """Return all commands for a given equipment."""
        result = self._call("cmd::byEqLogicId", {"eqLogic_id": equipment_id})
        return result if isinstance(result, list) else []

    def get_all_commands(self) -> list[dict]:
        """Return all commands from all equipment in a single API call."""
        result = self._call("cmd::all")
        return result if isinstance(result, list) else []

    def exec_command(self, command_id: int, value: str | None = None) -> Any:
        """Execute an action command."""
        params: dict = {"id": command_id}
        if value is not None:
            params["options"] = {"slider": value}
        return self._call("cmd::execCmd", params)

    # ------------------------------------------------------------------
    # Scenarios
    # ------------------------------------------------------------------

    def get_all_scenarios(self) -> list[dict]:
        """Return all scenarios from Jeedom."""
        result = self._call("scenario::all")
        return result if isinstance(result, list) else []

    def run_scenario(self, scenario_id: int) -> Any:
        """Trigger a scenario."""
        return self._call("scenario::changeState", {"id": scenario_id, "state": "run"})
