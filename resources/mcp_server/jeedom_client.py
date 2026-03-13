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
        # Derive the plugin ajax URL from the jeeApi URL
        base = url.rsplit('/core/api/', 1)[0]
        self.ajax_url = base + '/plugins/JeedomMCP/core/ajax/JeedomMCP.ajax.php'
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

    def set_equipment_comment(self, equipment_id: int, comment: str) -> None:
        """Set the comment field of an equipment via the plugin ajax endpoint."""
        start_time = time.monotonic()
        try:
            resp = self.session.post(
                self.ajax_url,
                data={"action": "setDeviceComment", "equipment_id": equipment_id, "comment": comment, "apikey": self.apikey},
                timeout=10,
            )
            resp.raise_for_status()
            data = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if data.get("state") != "ok":
                msg = f"setDeviceComment error: {data.get('result', 'unknown error')}"
                logger.error(msg)
                raise JeedomError(msg)
            logger.info(f"setDeviceComment completed in {elapsed:.0f}ms")
        except requests.RequestException as exc:
            msg = f"Jeedom API unreachable: {exc}"
            logger.error(msg)
            raise JeedomError(msg) from exc

    def get_all_equipment(self) -> list[dict]:
        """Return all equipment (eqLogic) from Jeedom."""
        result = self._call("eqLogic::all")
        return result if isinstance(result, list) else []

    def get_all_objects(self) -> list[dict]:
        """Return all rooms/zones (jeeObjects) from Jeedom."""
        result = self._call("jeeObject::all")
        if not isinstance(result, list):
            return []
        for obj in result:
            cfg = obj.get("configuration") or {}
            if isinstance(cfg, str):
                import json
                try:
                    cfg = json.loads(cfg)
                except Exception:
                    cfg = {}
            obj["_description"] = cfg.get("description", "") or None
            obj["_surface"] = cfg.get("info::space", "") or None
            obj["_orientation"] = cfg.get("info::orientation", "") or None
        return result

    def get_object_name_map(self) -> dict[str, str]:
        """Return a mapping of object ID → name."""
        return {str(obj["id"]): obj.get("name", "") for obj in self.get_all_objects() if "id" in obj}

    def create_room(self, name: str, description: str | None = None, surface: str | None = None, orientation: str | None = None, parent_id: int | None = None) -> dict:
        """Create a new room via the plugin ajax endpoint."""
        data: dict = {"action": "createRoom", "name": name, "apikey": self.apikey}
        if description is not None:
            data["description"] = description
        if surface is not None:
            data["surface"] = surface
        if orientation is not None:
            data["orientation"] = orientation
        if parent_id is not None:
            data["parent_id"] = parent_id
        start_time = time.monotonic()
        try:
            resp = self.session.post(self.ajax_url, data=data, timeout=10)
            resp.raise_for_status()
            result = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if result.get("state") != "ok":
                raise JeedomError(f"createRoom error: {result.get('result', 'unknown error')}")
            logger.info(f"createRoom completed in {elapsed:.0f}ms")
            return result.get("result", {})
        except requests.RequestException as exc:
            raise JeedomError(f"Jeedom API unreachable: {exc}") from exc

    def update_room(self, room_id: int, name: str | None = None, description: str | None = None, surface: str | None = None, orientation: str | None = None, parent_id: int | str | None = None) -> dict:
        """Update a room's fields via the plugin ajax endpoint."""
        data: dict = {"action": "updateRoom", "room_id": room_id, "apikey": self.apikey}
        if name is not None:
            data["name"] = name
        if description is not None:
            data["description"] = description
        if surface is not None:
            data["surface"] = surface
        if orientation is not None:
            data["orientation"] = orientation
        if parent_id is not None:
            data["parent_id"] = parent_id  # pass "null" string to unset parent
        start_time = time.monotonic()
        try:
            resp = self.session.post(self.ajax_url, data=data, timeout=10)
            resp.raise_for_status()
            result = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if result.get("state") != "ok":
                raise JeedomError(f"updateRoom error: {result.get('result', 'unknown error')}")
            logger.info(f"updateRoom completed in {elapsed:.0f}ms")
            return result.get("result", {})
        except requests.RequestException as exc:
            raise JeedomError(f"Jeedom API unreachable: {exc}") from exc

    def delete_room(self, room_id: int) -> None:
        """Delete a room via the plugin ajax endpoint."""
        start_time = time.monotonic()
        try:
            resp = self.session.post(
                self.ajax_url,
                data={"action": "deleteRoom", "room_id": room_id, "apikey": self.apikey},
                timeout=10,
            )
            resp.raise_for_status()
            result = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if result.get("state") != "ok":
                raise JeedomError(f"deleteRoom error: {result.get('result', 'unknown error')}")
            logger.info(f"deleteRoom completed in {elapsed:.0f}ms")
        except requests.RequestException as exc:
            raise JeedomError(f"Jeedom API unreachable: {exc}") from exc

    def set_room_description(self, room_id: int, description: str) -> None:
        """Set the description of a room via the plugin ajax endpoint."""
        start_time = time.monotonic()
        try:
            resp = self.session.post(
                self.ajax_url,
                data={"action": "setRoomDescription", "room_id": room_id, "description": description, "apikey": self.apikey},
                timeout=10,
            )
            resp.raise_for_status()
            data = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if data.get("state") != "ok":
                msg = f"setRoomDescription error: {data.get('result', 'unknown error')}"
                logger.error(msg)
                raise JeedomError(msg)
            logger.info(f"setRoomDescription completed in {elapsed:.0f}ms")
        except requests.RequestException as exc:
            msg = f"Jeedom API unreachable: {exc}"
            logger.error(msg)
            raise JeedomError(msg) from exc

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

    def get_scenario_actions(self, scenario_id: int) -> dict | None:
        """Return the full exported scenario including its elements and actions."""
        result = self._call("scenario::export", {"id": scenario_id})
        if not isinstance(result, dict):
            return None
        return result.get("export")

    def set_scenario_actions(self, scenario_id: int, elements: list) -> Any:
        """Replace the action elements of an existing scenario."""
        return self._call("scenario::import", {"id": scenario_id, "import": {"elements": elements}})

    def delete_scenario(self, scenario_id: int) -> None:
        """Delete a scenario via the plugin ajax endpoint."""
        start_time = time.monotonic()
        try:
            resp = self.session.post(
                self.ajax_url,
                data={"action": "deleteScenario", "scenario_id": scenario_id, "apikey": self.apikey},
                timeout=10,
            )
            resp.raise_for_status()
            data = resp.json()
            elapsed = (time.monotonic() - start_time) * 1000
            if data.get("state") != "ok":
                msg = f"deleteScenario error: {data.get('result', 'unknown error')}"
                logger.error(msg)
                raise JeedomError(msg)
            logger.info(f"deleteScenario completed in {elapsed:.0f}ms")
        except requests.RequestException as exc:
            msg = f"Jeedom API unreachable: {exc}"
            logger.error(msg)
            raise JeedomError(msg) from exc

    def get_all_scenarios(self) -> list[dict]:
        """Return all scenarios from Jeedom."""
        result = self._call("scenario::all")
        return result if isinstance(result, list) else []

    def run_scenario(self, scenario_id: int) -> Any:
        """Trigger a scenario."""
        return self._call("scenario::changeState", {"id": scenario_id, "state": "run"})

    def _get_scenario_name(self, scenario_id: int) -> str:
        """Fetch the current name of a scenario (required by scenario::save for partial updates)."""
        existing = self._call("scenario::byId", {"id": scenario_id})
        if isinstance(existing, dict):
            return existing.get("name", "")
        return ""

    def set_scenario_description(self, scenario_id: int, description: str) -> Any:
        """Update the description field of a scenario."""
        name = self._get_scenario_name(scenario_id)
        return self._call("scenario::save", {"id": scenario_id, "name": name, "description": description})

    def update_scenario(
        self,
        scenario_id: int,
        name: str | None = None,
        mode: str | None = None,
        schedule: str | None = None,
        trigger: list[str] | None = None,
        is_active: bool | None = None,
        description: str | None = None,
    ) -> Any:
        """Update fields of an existing scenario."""
        params: dict = {"id": scenario_id}
        # scenario::save always requires name — fetch existing if not provided
        if name is None:
            name = self._get_scenario_name(scenario_id)
        params["name"] = name
        if mode is not None:
            params["mode"] = mode
        if schedule is not None:
            params["schedule"] = schedule
        if trigger is not None:
            params["trigger"] = trigger
        if is_active is not None:
            params["isActive"] = "1" if is_active else "0"
        if description is not None:
            params["description"] = description
        return self._call("scenario::save", params)

    def create_scenario(
        self,
        name: str,
        mode: str,
        schedule: str | None = None,
        trigger: list[str] | None = None,
        is_active: bool = True,
        description: str = "",
    ) -> Any:
        """Create a new scenario."""
        params: dict = {
            "name": name,
            "mode": mode,
            "isActive": "1" if is_active else "0",
            "description": description,
        }
        if schedule is not None:
            params["schedule"] = schedule
        if trigger is not None:
            params["trigger"] = trigger
        return self._call("scenario::save", params)
