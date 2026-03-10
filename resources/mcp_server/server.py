"""JeedomMCP - MCP server exposing Jeedom home automation to AI agents."""

import argparse
import logging

logger = logging.getLogger(__name__)


def parse_args():
    parser = argparse.ArgumentParser(description="JeedomMCP server")
    parser.add_argument("--port", type=int, default=8765, help="HTTP port to listen on")
    parser.add_argument("--pid", type=str, help="PID file path")
    parser.add_argument("--apikey", type=str, required=True, help="MCP API key")
    parser.add_argument("--jeedom_url", type=str, required=True, help="Jeedom API URL")
    parser.add_argument("--jeedom_apikey", type=str, required=True, help="Jeedom API key")
    parser.add_argument("--loglevel", type=str, default="error", help="Log level")
    return parser.parse_args()


def write_pid(pid_file: str):
    import os
    with open(pid_file, "w") as f:
        f.write(str(os.getpid()))


def main():
    args = parse_args()
    logging.basicConfig(level=getattr(logging, args.loglevel.upper(), logging.ERROR))

    if args.pid:
        write_pid(args.pid)

    logger.info("JeedomMCP server starting on port %d", args.port)
    # Server implementation goes here


if __name__ == "__main__":
    main()
