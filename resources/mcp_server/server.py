"""JeedomMCP - MCP server exposing Jeedom home automation to AI agents."""

import argparse
import logging
import os

import fastmcp as _fastmcp
from fastmcp import FastMCP
from starlette.middleware import Middleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

from jeedom_client import JeedomClient, JeedomError

logger = logging.getLogger(__name__)

# Paths that do not require API key authentication.
# OAuth discovery endpoints must be public so MCP clients can reach them.
_PUBLIC_PATHS = {
    "/health",
    "/.well-known/oauth-protected-resource",
    "/.well-known/oauth-authorization-server",
    "/.well-known/openid-configuration",
    "/register",
}


# ---------------------------------------------------------------------------
# Authentication middleware
# ---------------------------------------------------------------------------

class APIKeyMiddleware:
    """Pure ASGI middleware — validates X-API-Key on every request except public paths.

    BaseHTTPMiddleware buffers responses and breaks SSE streaming, so we use
    the raw ASGI interface instead.
    """

    def __init__(self, app, api_key: str):
        self.app = app
        self.api_key = api_key

    async def __call__(self, scope, receive, send):
        if scope["type"] == "http":
            path = scope.get("path", "")
            if path not in _PUBLIC_PATHS:
                headers = dict(scope.get("headers", []))
                key = headers.get(b"x-api-key", b"").decode()
                if key != self.api_key:
                    logger.warning("Unauthorized request to %s", path)
                    response = JSONResponse({"error": "Unauthorized"}, status_code=401)
                    await response(scope, receive, send)
                    return
        await self.app(scope, receive, send)


# ---------------------------------------------------------------------------
# MCP server
# ---------------------------------------------------------------------------

def build_mcp(jeedom: JeedomClient, base_url: str) -> FastMCP:
    mcp = FastMCP(
        name="JeedomMCP",
        instructions=(
            "Control a Jeedom home automation system. "
            "Use devices_list to discover available equipment, "
            "device_state to read current values, "
            "command_execute to trigger actions, "
            "and scenarios_list / scenario_run for automation scenarios."
        ),
    )

    # ------------------------------------------------------------------
    # Public endpoints (no auth required)
    # ------------------------------------------------------------------

    @mcp.custom_route("/health", methods=["GET"], include_in_schema=False)
    async def health(request: Request) -> Response:
        return JSONResponse({"status": "ok"})

    @mcp.custom_route(
        "/.well-known/oauth-protected-resource", methods=["GET"], include_in_schema=False
    )
    async def oauth_protected_resource(request: Request) -> Response:
        # Return minimal resource metadata with no authorization_servers so
        # MCP clients that support pre-configured API keys can skip OAuth.
        return JSONResponse({"resource": base_url})

    @mcp.custom_route(
        "/.well-known/oauth-authorization-server", methods=["GET"], include_in_schema=False
    )
    @mcp.custom_route(
        "/.well-known/openid-configuration", methods=["GET"], include_in_schema=False
    )
    @mcp.custom_route("/register", methods=["GET", "POST"], include_in_schema=False)
    async def oauth_not_supported(request: Request) -> Response:
        return JSONResponse({"error": "OAuth not supported"}, status_code=404)

    def _fmt_scenario(s: dict) -> dict:
        return {
            "id": int(s["id"]),
            "name": s.get("name", ""),
            "group": s.get("group", "") or None,
            "description": s.get("description", "") or None,
            "is_active": s.get("isActive") == "1",
            "state": s.get("state", "") or None,
            "mode": s.get("mode", ""),
            "schedule": s.get("schedule") or None,
            "trigger": [t for t in (s.get("trigger") or []) if t] or None,
            "last_launch": s.get("lastLaunch", "") or None,
        }

    @mcp.tool()
    def devices_list() -> list[dict]:
        """List all enabled Jeedom equipment."""
        equipment = jeedom.get_all_equipment()
        objects = jeedom.get_all_objects()
        return [
            {
                "id": int(eq["id"]),
                "name": eq.get("name", ""),
                "object_id": eq.get("object_id"),
                "object_name": objects.get(str(eq.get("object_id", ""))) or None,
                "category": eq.get("category", ""),
                "is_visible": eq.get("isVisible") == "1",
            }
            for eq in equipment
            if eq.get("isEnable") == "1"
        ]

    @mcp.tool()
    def device_state(equipment_id: int) -> dict:
        """Get the current state of an equipment and all its commands.

        Args:
            equipment_id: Equipment ID obtained from devices_list.
        """
        eq = jeedom.get_equipment(equipment_id)
        if not eq:
            return {"error": f"Equipment {equipment_id} not found"}

        commands = jeedom.get_commands(equipment_id)
        return {
            "equipment_id": equipment_id,
            "name": eq.get("name", ""),
            "commands": [
                {
                    "id": int(cmd["id"]),
                    "name": cmd.get("name", ""),
                    "logicalId": cmd.get("logicalId", ""),
                    "type": cmd.get("type", ""),
                    "subType": cmd.get("subType", ""),
                    "value": cmd.get("currentValue"),
                }
                for cmd in commands
            ],
        }

    @mcp.tool()
    def device_set_description(equipment_id: int, description: str) -> dict:
        """Set the description (comment) of a Jeedom equipment.

        Args:
            equipment_id: Equipment ID obtained from devices_list.
            description: Description text explaining the equipment's purpose or location.
        """
        try:
            jeedom.set_equipment_comment(equipment_id, description)
            return {"success": True, "equipment_id": equipment_id, "description": description}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def devices_states(equipment_ids: list[int] | None = None) -> list[dict]:
        """Get the current state of all equipment and their commands in a single call.

        Args:
            equipment_ids: Optional list of equipment IDs to filter. If omitted, returns all enabled equipment.
        """
        equipment_list = jeedom.get_all_equipment()
        all_commands = jeedom.get_all_commands()
        objects = jeedom.get_all_objects()

        # Group commands by equipment ID
        commands_by_eq: dict[str, list] = {}
        for cmd in all_commands:
            eq_id = str(cmd.get("eqLogic_id", ""))
            commands_by_eq.setdefault(eq_id, []).append(cmd)

        result = []
        for eq in equipment_list:
            if eq.get("isEnable") != "1":
                continue
            if equipment_ids is not None and int(eq["id"]) not in equipment_ids:
                continue
            cmds = commands_by_eq.get(str(eq["id"]), [])
            result.append({
                "id": int(eq["id"]),
                "name": eq.get("name", ""),
                "object_name": objects.get(str(eq.get("object_id", ""))) or None,
                "category": eq.get("category", ""),
                "is_visible": eq.get("isVisible") == "1",
                "commands": [
                    {
                        "id": int(cmd["id"]),
                        "name": cmd.get("name", ""),
                        "logicalId": cmd.get("logicalId", ""),
                        "type": cmd.get("type", ""),
                        "subType": cmd.get("subType", ""),
                        "value": cmd.get("currentValue"),
                    }
                    for cmd in cmds
                ],
            })
        return result

    @mcp.tool()
    def command_execute(command_id: int, value: str | None = None) -> dict:
        """Execute an action command on a Jeedom equipment.

        Args:
            command_id: Command ID obtained from device_state.
            value: Optional value for slider or text commands.
        """
        try:
            jeedom.exec_command(command_id, value)
            return {"success": True, "command_id": command_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenarios_list() -> list[dict]:
        """List all Jeedom scenarios."""
        return [
            {
                "id": int(s["id"]),
                "name": s.get("name", ""),
                "group": s.get("group", "") or None,
                "description": s.get("description", "") or None,
                "is_active": s.get("isActive") == "1",
                "state": s.get("state", "") or None,
                "mode": s.get("mode", ""),
                "schedule": s.get("schedule") or None,
                "trigger": [t for t in (s.get("trigger") or []) if t] or None,
                "last_launch": s.get("lastLaunch", "") or None,
            }
            for s in jeedom.get_all_scenarios()
        ]

    @mcp.tool()
    def scenario_delete(scenario_id: int) -> dict:
        """Delete a Jeedom scenario permanently.

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
        """
        try:
            jeedom.delete_scenario(scenario_id)
            return {"success": True, "scenario_id": scenario_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenario_get_actions(scenario_id: int) -> dict:
        """Get the full action blocks of a Jeedom scenario (elements, sub-elements, expressions).

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
        """
        result = jeedom.get_scenario_actions(scenario_id)
        if result is None:
            return {"error": f"Scenario {scenario_id} not found"}
        return {"scenario_id": scenario_id, "elements": result.get("elements", [])}

    @mcp.tool()
    def scenario_set_actions(scenario_id: int, elements: list[dict]) -> dict:
        """Replace the action blocks of a Jeedom scenario.

        The elements structure mirrors the output of scenario_get_actions.
        Each element has a type, order, and subElements list; each subElement
        has expressions with the actual action commands or conditions.

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
            elements: Full list of action blocks to save (replaces existing blocks).
        """
        try:
            jeedom.set_scenario_actions(scenario_id, elements)
            return {"success": True, "scenario_id": scenario_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenario_set_description(scenario_id: int, description: str) -> dict:
        """Set the description of a Jeedom scenario.

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
            description: Description text explaining the scenario's purpose.
        """
        try:
            result = jeedom.set_scenario_description(scenario_id, description)
            saved_description = result.get("description") if isinstance(result, dict) else description
            return {"success": True, "scenario_id": scenario_id, "description": saved_description}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenario_update(
        scenario_id: int,
        name: str | None = None,
        mode: str | None = None,
        schedule: str | None = None,
        trigger: list[str] | None = None,
        is_active: bool | None = None,
        description: str | None = None,
    ) -> dict:
        """Update fields of an existing Jeedom scenario. Only provided fields are modified.

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
            name: New display name.
            mode: Execution mode — 'schedule', 'provoke', or 'always'.
            schedule: Cron expression (for schedule mode).
            trigger: List of trigger conditions (for provoke mode).
            is_active: Enable or disable the scenario.
            description: Description of the scenario's purpose.
        """
        try:
            jeedom.update_scenario(scenario_id, name, mode, schedule, trigger, is_active, description)
            return {"success": True, "scenario_id": scenario_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenario_create(
        name: str,
        mode: str,
        schedule: str | None = None,
        trigger: list[str] | None = None,
        is_active: bool = True,
        description: str = "",
    ) -> dict:
        """Create a new Jeedom scenario.

        Args:
            name: Display name of the scenario.
            mode: Execution mode — 'schedule' (cron-based), 'provoke' (trigger-based), or 'always'.
            schedule: Cron expression, required when mode is 'schedule'.
            trigger: List of trigger conditions (command IDs or expressions), used when mode is 'provoke'.
            is_active: Whether the scenario is active (default: True).
            description: Optional description of the scenario's purpose.
        """
        try:
            result = jeedom.create_scenario(name, mode, schedule, trigger, is_active, description)
            if not isinstance(result, dict) or "id" not in result:
                return {"error": "Scenario creation failed: no ID returned"}
            return {"success": True, **_fmt_scenario(result)}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def scenario_run(scenario_id: int) -> dict:
        """Trigger a Jeedom scenario.

        Args:
            scenario_id: Scenario ID obtained from scenarios_list.
        """
        try:
            jeedom.run_scenario(scenario_id)
            return {"success": True, "scenario_id": scenario_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    return mcp


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="JeedomMCP server")
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--pid", type=str)
    parser.add_argument("--apikey", type=str, required=True, help="MCP API key for clients")
    parser.add_argument("--base_url", type=str, default="", help="Public base URL of the MCP server (e.g. https://example.com/mcp)")
    parser.add_argument("--jeedom_url", type=str, required=True, help="Jeedom internal API URL")
    parser.add_argument("--jeedom_apikey", type=str, required=True, help="Jeedom API key")
    parser.add_argument("--loglevel", type=str, default="error")
    parser.add_argument("--transport", type=str, default="http", choices=["http", "sse"])
    return parser.parse_args()


def write_pid(path: str) -> None:
    with open(path, "w") as f:
        f.write(str(os.getpid()))


def main() -> None:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, args.loglevel.upper(), logging.ERROR),
        format="[%(asctime)s][%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )
    # Route FastMCP logs through our root logger instead of its own RichHandler
    _fastmcp.settings.log_enabled = False
    _fmcp_logger = logging.getLogger("fastmcp")
    _fmcp_logger.handlers.clear()
    _fmcp_logger.propagate = True

    log = logging.getLogger("Startup")
    if args.pid:
        write_pid(args.pid)

    log.info("=== JeedomMCP starting ===")
    log.info("- pid        : %d", os.getpid())
    log.info("- port       : %d", args.port)
    log.info("- base_url   : %s", args.base_url)
    log.info("- jeedom_url : %s", args.jeedom_url)
    log.info("- apikey     : %s...%s", args.apikey[:4], args.apikey[-4:])
    log.info("- jeedom_key : %s...%s", args.jeedom_apikey[:4], args.jeedom_apikey[-4:])
    log.info("- loglevel   : %s", args.loglevel)
    log.info("- transport  : %s", args.transport)
    log.info("==========================")


    jeedom = JeedomClient(url=args.jeedom_url, apikey=args.jeedom_apikey)

    try:
        equipment = jeedom.get_all_equipment()
        if len(equipment)>0:
            log.info("Jeedom API check complete - %d equipment found", len(equipment))
        else:
            log.warning("Jeedom API check - no equipment found")
    except Exception as exc:
        log.error("Jeedom API check FAILED: %s", exc)

    mcp = build_mcp(jeedom, base_url=args.base_url)

    mcp.run(
        transport=args.transport,
        host="127.0.0.1",
        port=args.port,
        middleware=[Middleware(APIKeyMiddleware, api_key=args.apikey)],
        show_banner=False,
        uvicorn_config={"log_config": None},
    )


if __name__ == "__main__":
    main()
