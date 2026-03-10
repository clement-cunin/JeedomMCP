"""JeedomMCP - MCP server exposing Jeedom home automation to AI agents."""

import argparse
import logging
import os

from fastmcp import FastMCP
from starlette.middleware import Middleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

from jeedom_client import JeedomClient, JeedomError

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Authentication middleware
# ---------------------------------------------------------------------------

class APIKeyMiddleware(BaseHTTPMiddleware):
    """Validate X-API-Key header on every request except /health."""

    def __init__(self, app, api_key: str):
        super().__init__(app)
        self.api_key = api_key

    async def dispatch(self, request: Request, call_next) -> Response:
        if request.url.path == "/health":
            return JSONResponse({"status": "ok"})
        key = request.headers.get("X-API-Key", "")
        if key != self.api_key:
            logger.warning("Unauthorized request from %s", request.client)
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        return await call_next(request)


# ---------------------------------------------------------------------------
# MCP server
# ---------------------------------------------------------------------------

def build_mcp(jeedom: JeedomClient) -> FastMCP:
    mcp = FastMCP(
        name="JeedomMCP",
        instructions=(
            "Control a Jeedom home automation system. "
            "Use list_devices to discover available equipment, "
            "get_device_state to read current values, "
            "execute_command to trigger actions, "
            "and list_scenarios / run_scenario for automation scenarios."
        ),
    )

    @mcp.tool()
    def list_devices() -> list[dict]:
        """List all enabled Jeedom equipment."""
        equipment = jeedom.get_all_equipment()
        return [
            {
                "id": int(eq["id"]),
                "name": eq.get("name", ""),
                "object_id": eq.get("object_id"),
                "object_name": (eq.get("object") or {}).get("name"),
                "category": eq.get("category", ""),
                "is_visible": eq.get("isVisible") == "1",
            }
            for eq in equipment
            if eq.get("isEnable") == "1"
        ]

    @mcp.tool()
    def get_device_state(equipment_id: int) -> dict:
        """Get the current state of an equipment and all its commands.

        Args:
            equipment_id: Equipment ID obtained from list_devices.
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
    def execute_command(command_id: int, value: str | None = None) -> dict:
        """Execute an action command on a Jeedom equipment.

        Args:
            command_id: Command ID obtained from get_device_state.
            value: Optional value for slider or text commands.
        """
        try:
            jeedom.exec_command(command_id, value)
            return {"success": True, "command_id": command_id}
        except JeedomError as exc:
            return {"error": str(exc)}

    @mcp.tool()
    def list_scenarios() -> list[dict]:
        """List all Jeedom scenarios."""
        return [
            {
                "id": int(s["id"]),
                "name": s.get("name", ""),
                "group": s.get("group", ""),
                "state": s.get("state", ""),
                "is_active": s.get("isActive") == "1",
            }
            for s in jeedom.get_all_scenarios()
        ]

    @mcp.tool()
    def run_scenario(scenario_id: int) -> dict:
        """Trigger a Jeedom scenario.

        Args:
            scenario_id: Scenario ID obtained from list_scenarios.
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
    parser.add_argument("--jeedom_url", type=str, required=True, help="Jeedom internal API URL")
    parser.add_argument("--jeedom_apikey", type=str, required=True, help="Jeedom API key")
    parser.add_argument("--loglevel", type=str, default="error")
    return parser.parse_args()


def write_pid(path: str) -> None:
    with open(path, "w") as f:
        f.write(str(os.getpid()))


def main() -> None:
    args = parse_args()
    logging.basicConfig(level=getattr(logging, args.loglevel.upper(), logging.ERROR))

    if args.pid:
        write_pid(args.pid)

    jeedom = JeedomClient(url=args.jeedom_url, apikey=args.jeedom_apikey)
    mcp = build_mcp(jeedom)

    logger.info("JeedomMCP starting on port %d", args.port)

    mcp.run(
        transport="sse",
        host="127.0.0.1",
        port=args.port,
        middleware=[Middleware(APIKeyMiddleware, api_key=args.apikey)],
    )


if __name__ == "__main__":
    main()
