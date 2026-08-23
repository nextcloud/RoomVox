#!/bin/bash

# Fix room address fields and roomNumber on tst server after MS365 import
# The MS365 export had empty Building/Floor fields, causing address parts to shift.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ ! -f "$SCRIPT_DIR/deploy.conf" ]; then
    echo "Missing deploy.conf"
    exit 1
fi
source "$SCRIPT_DIR/deploy.conf"

REMOTE_HOST="145.38.189.69"
SERVER_NAME="tst"

echo "Fix Room Addresses — $SERVER_NAME ($REMOTE_HOST)"
echo "================================================="
echo ""

# Step 1: Fetch all room data
echo "Fetching room data..."
ROOM_DATA=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" 'sudo -u www-data php /var/www/nextcloud/occ config:list roomvox 2>/dev/null')

# Step 2: Generate fix commands using Python
echo "Analyzing rooms and generating fixes..."
echo "$ROOM_DATA" | python3 > /tmp/roomvox-fix-script.sh << 'PYEOF'
import json, sys, re

data = json.load(sys.stdin)
apps = data.get("apps", {}).get("roomvox", {})

rooms = {}
for k, v in apps.items():
    if k.startswith("room/"):
        rooms[k] = json.loads(v)

print("#!/bin/bash", flush=True)
print("set -e", flush=True)
print("cd /var/www/nextcloud", flush=True)
print("", flush=True)

fixed = 0
total = len(rooms)

for key, room in rooms.items():
    name = room.get("name", "")
    old_address = room.get("address", "")
    old_rn = room.get("roomNumber", "")
    changed = False

    # Determine location from name prefix
    clean = re.sub(r"^_FORBIDDEN TO BOOK - ", "", name)
    upper = clean.upper()

    if upper.startswith("AMS"):
        building, street, postal, city = "SURF Amsterdam", "Science Park 140", "1098 XG", "Amsterdam"
    elif upper.startswith("UTR") or upper.startswith("DE STUDIO"):
        building, street, postal, city = "SURF Utrecht", "Moreelsepark 48", "3511 EP", "Utrecht"
    elif upper.startswith("TEST"):
        building, street, postal, city = "SURF Utrecht", "Moreelsepark 48", "3511 EP", "Utrecht"
    else:
        print(f"# SKIP: {name} — unknown prefix", file=sys.stderr)
        continue

    new_address = f"{building}, {street}, {postal}, {city}"
    if old_address != new_address:
        room["address"] = new_address
        changed = True

    # Extract roomNumber from name: "AMS 1.05 Online..." -> "1.05"
    match = re.match(r"[A-Z]{3}\s+(\d+(?:\.\d+)?)\s", clean)
    if match and not old_rn:
        room["roomNumber"] = match.group(1)
        changed = True

    if changed:
        fixed += 1
        room_json = json.dumps(room, ensure_ascii=False)
        # Escape for shell single quotes
        escaped = room_json.replace("'", "'\"'\"'")
        print(f"sudo -u www-data php occ config:app:set roomvox '{key}' --value '{escaped}'")
        changes = []
        if old_address != room["address"]:
            changes.append(f"address")
        if old_rn != room.get("roomNumber", ""):
            changes.append(f"roomNumber={room['roomNumber']}")
        print(f"# ^ {name}: {', '.join(changes)}", file=sys.stderr)

print("", file=sys.stderr)
print(f"Total: {total} rooms, fixing: {fixed}", file=sys.stderr)
PYEOF

LINES=$(grep -c "^sudo" /tmp/roomvox-fix-script.sh || echo "0")
echo "Generated $LINES fix commands"
echo ""

if [ "$LINES" = "0" ]; then
    echo "Nothing to fix!"
    rm -f /tmp/roomvox-fix-script.sh
    exit 0
fi

# Step 3: Upload and execute
echo "Uploading and running fix script on server..."
scp -i "$SSH_KEY" /tmp/roomvox-fix-script.sh "${REMOTE_USER}@${REMOTE_HOST}:/tmp/roomvox-fix-script.sh"
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" 'chmod +x /tmp/roomvox-fix-script.sh && bash /tmp/roomvox-fix-script.sh && rm /tmp/roomvox-fix-script.sh'

rm -f /tmp/roomvox-fix-script.sh

echo ""
echo "Verifying..."
echo ""

# Verify 3 sample rooms
for ROOM_KEY in "room/ams-105-online-meetingspace-6p" "room/ams-001-meetingroom-50p-vc" "room/utr-36-meetingroom-8p-vc"; do
    RESULT=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "sudo -u www-data php /var/www/nextcloud/occ config:app:get roomvox '$ROOM_KEY' 2>/dev/null" || echo '{}')
    echo "$RESULT" | python3 -c "
import json, sys
try:
    r = json.loads(sys.stdin.read())
    print(f\"  {r.get('name','?'):45s} address='{r.get('address','')}' roomNumber='{r.get('roomNumber','')}'\"  )
except:
    print(f'  Could not read $ROOM_KEY')
"
done

echo ""
echo "Done!"
