#!/bin/bash

PROGRESS_FILE=$1
PLUGIN_DIR=$(dirname "$0")

# Update progress file
touch "${PROGRESS_FILE}"

echo "Installing JeedomMCP Python dependencies..."

# Detect pip3 (support pyenv)
PIP3=$(which pip3 2>/dev/null || echo "/home/pi/.pyenv/shims/pip3")

${PIP3} install --upgrade pip --quiet
${PIP3} install -r "${PLUGIN_DIR}/mcp_server/requirements.txt" --quiet

echo "Done."
rm -f "${PROGRESS_FILE}"
