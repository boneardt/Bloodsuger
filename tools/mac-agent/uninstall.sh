#!/bin/bash
#
# Removes everything installed by install.sh in this folder.

set -u

if [ "$(uname)" != "Darwin" ]; then
    echo "This is for macOS only." >&2
    exit 1
fi

CONFIG_DIR="$HOME/.config/bloodsuger"
SCRIPT_DIR="$HOME/Library/Application Support/Bloodsuger"
SCRIPT_FILE="$SCRIPT_DIR/sync.sh"
PLIST_FILE="$HOME/Library/LaunchAgents/com.bloodsuger.sync.plist"
AGENT_LABEL="com.bloodsuger.sync"

echo "Removing the Bloodsuger Mac sync agent..."

UID_NUM=$(id -u)
launchctl bootout "gui/$UID_NUM/$AGENT_LABEL" >/dev/null 2>&1 || true
echo "  - stopped and unloaded the agent (if it was running)"

if [ -f "$PLIST_FILE" ]; then
    rm -f "$PLIST_FILE"
    echo "  - removed $PLIST_FILE"
fi

if [ -f "$SCRIPT_FILE" ]; then
    rm -f "$SCRIPT_FILE"
    echo "  - removed $SCRIPT_FILE"
    rmdir "$SCRIPT_DIR" 2>/dev/null || true
fi

if [ -d "$CONFIG_DIR" ]; then
    printf "Also remove the saved URL/token in %s? [y/N] " "$CONFIG_DIR"
    read -r REMOVE_CONFIG
    if [[ "$REMOVE_CONFIG" =~ ^[Yy] ]]; then
        rm -rf "$CONFIG_DIR"
        echo "  - removed $CONFIG_DIR"
    else
        echo "  - left $CONFIG_DIR in place"
    fi
fi

echo
echo "Note: the log at ~/Library/Logs/bloodsuger-sync.log (if it exists) is left"
echo "in place — delete it yourself if you want, it's just a plain text file."
echo
echo "Done. Full Disk Access for /bin/bash is a system-wide setting shared"
echo "with anything else using it, so this doesn't turn it off automatically —"
echo "remove it in System Settings → Privacy & Security → Full Disk Access"
echo "yourself if you want that back too."
