#!/bin/bash
# Dependency installer for JeedomMCP plugin.
# Called by Jeedom with the progress file path as first argument.

set -e

PROGRESS_FILE=$1
PLUGIN_DIR=$(dirname "$0")
LOG_PREFIX="[JeedomMCP install]"

# Write progress percentage to the Jeedom progress file
progress() {
    echo "$1" > "${PROGRESS_FILE}"
    echo "${LOG_PREFIX} ${2} (${1}%)"
}

# Called on any error (via set -e + trap)
on_error() {
    echo "${LOG_PREFIX} ERROR: installation failed at step: ${CURRENT_STEP}" >&2
    # Leave the progress file in place so Jeedom detects the failure
    exit 1
}

trap on_error ERR

CURRENT_STEP="detecting pip3"
progress 0 "Starting dependency installation"

# Detect pip3: prefer pyenv shim, fall back to system pip3
if [ -x "/home/pi/.pyenv/shims/pip3" ]; then
    PIP3="/home/pi/.pyenv/shims/pip3"
elif command -v pip3 &>/dev/null; then
    PIP3="pip3"
else
    echo "${LOG_PREFIX} ERROR: pip3 not found" >&2
    exit 1
fi

echo "${LOG_PREFIX} Using pip3: ${PIP3}"

# ---- Step 1: upgrade pip ------------------------------------------------
CURRENT_STEP="upgrading pip"
progress 10 "Installing pip (upgrade)"
${PIP3} install --upgrade pip --quiet

# ---- Step 2: install requests -------------------------------------------
CURRENT_STEP="installing requests"
progress 30 "Installing requests>=2.31.0"
${PIP3} install "requests>=2.31.0" --quiet

# ---- Step 3: install fastmcp --------------------------------------------
CURRENT_STEP="installing fastmcp"
progress 60 "Installing fastmcp>=2.0.0"
${PIP3} install "fastmcp>=2.0.0" --quiet

# ---- Step 4: verify imports ---------------------------------------------
CURRENT_STEP="verifying imports"
progress 90 "Verifying installation"
PYTHON3=$(dirname "${PIP3}")/python3
${PYTHON3} -c "import fastmcp, requests" || {
    echo "${LOG_PREFIX} ERROR: import verification failed" >&2
    exit 1
}

# ---- Done ---------------------------------------------------------------
progress 100 "Installation complete"
rm -f "${PROGRESS_FILE}"
echo "${LOG_PREFIX} All dependencies installed successfully"
