#!/bin/bash
#
# Bloodsuger Mac sync agent installer.
#
# Sets up a launchd agent that watches a folder (one you keep synced via
# iCloud Drive to your iPhone) and automatically uploads the newest Contour
# CSV export to your Bloodsuger site the moment that folder changes — no
# iOS Shortcut involved at all. See the "Automated sync (iPhone) > Option C"
# section of the project's README for the full explanation of why this
# exists and how it works.
#
# Safe to re-run: it replaces any previous install cleanly (same effect as
# running uninstall.sh first).

set -u

if [ "$(uname)" != "Darwin" ]; then
    echo "This installer is for macOS only (it uses launchd and macOS's Full Disk Access)." >&2
    exit 1
fi

CONFIG_DIR="$HOME/.config/bloodsuger"
TOKEN_FILE="$CONFIG_DIR/token"
CONFIG_FILE="$CONFIG_DIR/config"
SCRIPT_DIR="$HOME/Library/Application Support/Bloodsuger"
SCRIPT_FILE="$SCRIPT_DIR/sync.sh"
PLIST_FILE="$HOME/Library/LaunchAgents/com.bloodsuger.sync.plist"
LOG_FILE="$HOME/Library/Logs/bloodsuger-sync.log"
AGENT_LABEL="com.bloodsuger.sync"

echo "Bloodsuger Mac sync agent — installer"
echo "======================================"
echo
echo "This sets up a background agent that watches a folder on this Mac and"
echo "automatically uploads the newest Contour CSV export to your Bloodsuger"
echo "site whenever that folder changes. Nothing runs unless the folder changes."
echo
echo "One requirement up front: macOS will need you to grant Full Disk Access"
echo "to /bin/bash in System Settings, since a background agent (unlike an"
echo "interactive Terminal session) doesn't automatically get access to your"
echo "Documents folder. This installer will prompt you for that at the end."
echo

# --- 1. Prompt for the CSV import URL ---
echo "Open your Bloodsuger site, log in as an administrator, and go to"
echo "Settings → \"Automated sync\"."
echo
SYNC_URL=""
while [ -z "$SYNC_URL" ]; do
    printf "Paste the CSV import URL shown there: "
    read -r SYNC_URL
    if [ -z "$SYNC_URL" ]; then
        echo "  (can't be empty)"
    elif [[ "$SYNC_URL" != http://* && "$SYNC_URL" != https://* ]]; then
        echo "  That doesn't look like a URL (should start with http:// or https://) — try again."
        SYNC_URL=""
    fi
done

# --- 2. Prompt for the sync token (hidden input) ---
TOKEN=""
while [ -z "$TOKEN" ]; do
    printf "Paste the sync token from the same page (input hidden): "
    read -rs TOKEN
    echo
    if [ -z "$TOKEN" ]; then
        echo "  (can't be empty)"
    fi
done

# --- 3. Prompt for the folder to watch ---
echo
echo "Now the folder to watch. This should be a folder that syncs via iCloud"
echo "Drive between this Mac and your iPhone — save a test file from your"
echo "phone's Files app and confirm it appears here before continuing, if"
echo "you haven't already verified that."
echo
echo "Tip: you can drag the folder from Finder straight into this window"
echo "instead of typing the path."
echo
WATCH_FOLDER=""
while [ -z "$WATCH_FOLDER" ]; do
    printf "Folder to watch: "
    read -r RAW_FOLDER
    # Dragging a folder into Terminal inserts the path with backslash-escaped
    # spaces (or sometimes wrapped in quotes) rather than a plain path — undo
    # both so the rest of the script can treat it as a normal string.
    CANDIDATE="${RAW_FOLDER//\\ / }"
    CANDIDATE="${CANDIDATE%\"}"
    CANDIDATE="${CANDIDATE#\"}"
    CANDIDATE="${CANDIDATE%\'}"
    CANDIDATE="${CANDIDATE#\'}"
    if [ -z "$CANDIDATE" ]; then
        echo "  (can't be empty)"
    elif [ ! -d "$CANDIDATE" ]; then
        echo "  \"$CANDIDATE\" doesn't exist (or isn't a folder) — check the path and try again."
    else
        WATCH_FOLDER="$CANDIDATE"
    fi
done

echo
echo "Setting up..."

# --- 4. Store config ---
# Single-quote the values ourselves rather than relying on bash's `%q`: %q's
# escaping of non-ASCII bytes (an "å"/"ø" in a folder name, say) depends on
# the shell's locale, and can mangle them unpredictably — single-quoting is
# a plain byte-for-byte wrap with no locale sensitivity at all, as long as
# the value has no embedded single quote (vanishingly unlikely in a URL or
# folder path).
quote_for_config() {
    printf "'%s'" "${1//\'/\'\\\'\'}"
}

mkdir -p "$CONFIG_DIR"
printf '%s' "$TOKEN" > "$TOKEN_FILE"
chmod 600 "$TOKEN_FILE"
{
    printf 'SYNC_URL=%s\n' "$(quote_for_config "$SYNC_URL")"
    printf 'WATCH_FOLDER=%s\n' "$(quote_for_config "$WATCH_FOLDER")"
} > "$CONFIG_FILE"
chmod 600 "$CONFIG_FILE"
echo "  - saved config to $CONFIG_DIR"

# --- 5. Write the generic sync script ---
mkdir -p "$SCRIPT_DIR"
cat > "$SCRIPT_FILE" <<'SCRIPT_EOF'
#!/bin/bash
#
# Finds the newest Contour CSV export in the configured folder and uploads
# it to Bloodsuger. Triggered automatically by launchd's WatchPaths whenever
# that folder changes — see com.bloodsuger.sync.plist. The server-side
# import is idempotent (matched by date/time), so re-sending an
# already-synced file is harmless, not just a performance nicety — no
# "have I seen this file before" tracking needed here.
#
# Retries on failure: WatchPaths can fire the instant iCloud starts writing
# a file, before it's fully downloaded to this Mac — a transient failure
# here usually just means "try again in a few seconds", not a real problem.

CONFIG_FILE="$HOME/.config/bloodsuger/config"
TOKEN_FILE="$HOME/.config/bloodsuger/token"
LOG="$HOME/Library/Logs/bloodsuger-sync.log"
MAX_ATTEMPTS=4
RETRY_DELAY=5

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG"
}

if [ ! -f "$CONFIG_FILE" ]; then
    log "ERROR: config file not found at $CONFIG_FILE"
    exit 1
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

if [ ! -f "$TOKEN_FILE" ]; then
    log "ERROR: token file not found at $TOKEN_FILE"
    exit 1
fi
TOKEN=$(cat "$TOKEN_FILE")

# A brief head start before even the first attempt — the common case where
# iCloud finishes writing well within this window needs no retry at all.
sleep 3

ATTEMPT=1
while [ "$ATTEMPT" -le "$MAX_ATTEMPTS" ]; do
    # Only Contour's own export naming — the watched folder may also hold
    # other apps' exports (e.g. a MedM CSV) that must never be picked up by
    # mistake. Re-checked each attempt in case a newer file lands meanwhile.
    NEWEST=$(ls -t "$WATCH_FOLDER"/ContourCSVReport*.csv 2>/dev/null | head -1)

    if [ -z "$NEWEST" ]; then
        log "no ContourCSVReport*.csv file found in $WATCH_FOLDER, nothing to do"
        exit 0
    fi

    RESPONSE=$(curl -s -w $'\n''HTTP_STATUS:%{http_code}' -X POST "$SYNC_URL" \
        -H "X-Sync-Token: $TOKEN" \
        -F "csv=@${NEWEST}")
    HTTP_STATUS="${RESPONSE##*HTTP_STATUS:}"

    if [ "$HTTP_STATUS" = "200" ]; then
        log "synced $(basename "$NEWEST") -> ${RESPONSE//$'\n'/ | } (attempt $ATTEMPT)"
        exit 0
    fi

    log "attempt $ATTEMPT/$MAX_ATTEMPTS failed for $(basename "$NEWEST") -> ${RESPONSE//$'\n'/ | }"
    ATTEMPT=$((ATTEMPT + 1))
    [ "$ATTEMPT" -le "$MAX_ATTEMPTS" ] && sleep "$RETRY_DELAY"
done

log "giving up on $(basename "$NEWEST") after $MAX_ATTEMPTS attempts"
SCRIPT_EOF
chmod 700 "$SCRIPT_FILE"
echo "  - wrote sync script to $SCRIPT_FILE"

# --- 6. Write the LaunchAgent plist (folder has to be baked in — plist XML
#        can't read a shell variable the way sync.sh reads its config file) ---
cat > "$PLIST_FILE" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$AGENT_LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>$SCRIPT_FILE</string>
    </array>
    <key>WatchPaths</key>
    <array>
        <string>$WATCH_FOLDER</string>
    </array>
    <key>StandardOutPath</key>
    <string>$LOG_FILE</string>
    <key>StandardErrorPath</key>
    <string>$LOG_FILE</string>
    <key>ThrottleInterval</key>
    <integer>10</integer>
</dict>
</plist>
PLIST_EOF
chmod 644 "$PLIST_FILE"
echo "  - wrote launch agent to $PLIST_FILE"

# --- 7. Load it (bootout first, in case this is a re-run) ---
UID_NUM=$(id -u)
launchctl bootout "gui/$UID_NUM/$AGENT_LABEL" >/dev/null 2>&1 || true
if launchctl bootstrap "gui/$UID_NUM" "$PLIST_FILE" 2>/tmp/bloodsuger-bootstrap-err; then
    echo "  - loaded the agent"
else
    echo "  - WARNING: failed to load the agent:"
    cat /tmp/bloodsuger-bootstrap-err >&2
fi
rm -f /tmp/bloodsuger-bootstrap-err
touch "$LOG_FILE"

# --- 8. Full Disk Access ---
echo
echo "Last step — Full Disk Access for /bin/bash"
echo "-------------------------------------------"
echo "Without this, the agent will run but silently find nothing (no error"
echo "dialog, it just won't see your files) — this is the one step that"
echo "can't be automated, macOS requires it to be done by hand."
echo
printf "Open System Settings to the right page now? [Y/n] "
read -r OPEN_SETTINGS
if [[ ! "$OPEN_SETTINGS" =~ ^[Nn] ]]; then
    open "x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles"
fi
echo
echo "In System Settings → Privacy & Security → Full Disk Access:"
echo "  1. Click the + button."
echo "  2. Press ⌘⇧G (Go to Folder), type /bin/bash, press Enter, then Add."
echo "  3. Make sure the toggle next to it is ON."
echo
printf "Press Enter once that's done: "
read -r _

echo
echo "Done! Summary:"
echo "  - Config:       $CONFIG_DIR"
echo "  - Sync script:  $SCRIPT_FILE"
echo "  - Launch agent: $PLIST_FILE"
echo "  - Log:          $LOG_FILE"
echo
echo "From now on: in the Contour app, Reports → Raw Data (csv) → Share →"
echo "\"Gem i Arkiver\" (Save to Files), saving into:"
echo "  $WATCH_FOLDER"
echo "That's the only step you need on your phone — this Mac picks it up and"
echo "syncs it automatically within a few seconds, as long as it's on and"
echo "signed in."
echo
echo "To remove this later, run uninstall.sh in this same folder."
