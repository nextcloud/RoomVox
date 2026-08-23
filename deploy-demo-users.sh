#!/bin/bash
#
# Deploy Demo Users with Avatars to Nextcloud Server
#
# Reuses the IntraVox demo user data (demo-users.json, portrait images,
# create-demo-users-occ.sh, set-avatar.php) to create test accounts
# with profile photos on the RoomVox test servers.
#
# Usage:
#   ./deploy-demo-users.sh [1dev|3dev|tst]    # default: tst
#   ./deploy-demo-users.sh tst --delete       # remove demo users
#

set -e

# Load deploy configuration
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ ! -f "$SCRIPT_DIR/deploy.conf" ]; then
    echo "Missing deploy.conf — copy deploy.conf.example and fill in your values"
    exit 1
fi
source "$SCRIPT_DIR/deploy.conf"

# IntraVox testdata directory (shared source)
INTRAVOX_PEOPLE="$SCRIPT_DIR/../IntraVox/testdata/people"

if [ ! -d "$INTRAVOX_PEOPLE" ]; then
    echo "Error: IntraVox testdata not found at $INTRAVOX_PEOPLE"
    echo "Expected: ../IntraVox/testdata/people/ with demo-users.json and portraits"
    exit 1
fi

# Server selection
case "${1:-tst}" in
    1dev|1)
        REMOTE_HOST="$SERVERS_1DEV"
        SERVER_NAME="1dev"
        ;;
    3dev|3)
        REMOTE_HOST="$SERVERS_3DEV"
        SERVER_NAME="3dev"
        ;;
    tst)
        REMOTE_HOST="145.38.189.69"
        SERVER_NAME="tst"
        ;;
    *)
        echo "Unknown server: $1"
        echo "Usage: ./deploy-demo-users.sh [1dev|3dev|tst] [--delete]"
        exit 1
        ;;
esac

# Check for delete flag
DELETE_MODE=false
if [[ "$2" == "--delete" ]] || [[ "$1" == "--delete" ]]; then
    DELETE_MODE=true
fi

echo ""
echo "RoomVox Demo Users Deployment"
echo "============================="
echo "Server: $SERVER_NAME ($REMOTE_HOST)"
echo "Mode: $([ "$DELETE_MODE" = true ] && echo "DELETE" || echo "CREATE")"
echo "Source: $INTRAVOX_PEOPLE"
echo ""

# Test SSH connection
echo "Step 1: Testing SSH connection..."
if ! ssh -i "$SSH_KEY" -o ConnectTimeout=5 "${REMOTE_USER}@${REMOTE_HOST}" "echo 'Connected'" 2>/dev/null; then
    echo "Error: Cannot connect to $REMOTE_HOST"
    exit 1
fi
echo "  Connected!"
echo ""

REMOTE_DIR="/tmp/demo-users-$$"

if [ "$DELETE_MODE" = true ]; then
    echo "Step 2: Deleting demo users on $SERVER_NAME..."
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << 'EOF'
        set -e
        NC_PATH="/var/www/nextcloud"
        echo "  Deleting demo users (demo001-demo100)..."
        for i in $(seq -w 1 100); do
            userid="demo${i}"
            if sudo -u www-data php ${NC_PATH}/occ user:info "$userid" &>/dev/null; then
                sudo -u www-data php ${NC_PATH}/occ user:delete "$userid" && echo "  Deleted: $userid"
            fi
        done
        echo "  Done!"
EOF
else
    # Upload files
    echo "Step 2: Uploading demo user data..."
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p $REMOTE_DIR"

    scp -i "$SSH_KEY" -q \
        "$INTRAVOX_PEOPLE/demo-users.json" \
        "$INTRAVOX_PEOPLE/create-demo-users-occ.sh" \
        "$INTRAVOX_PEOPLE/set-avatar.php" \
        "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}/"

    echo "  Uploading portrait images..."
    scp -i "$SSH_KEY" -q "$INTRAVOX_PEOPLE"/portrait_*.jpg "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}/" 2>/dev/null || echo "  No portrait images found, skipping..."

    echo "  Files uploaded!"
    echo ""

    # Ensure jq is installed
    echo "Step 3: Checking prerequisites..."
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << 'EOF'
        if ! command -v jq &> /dev/null; then
            echo "  Installing jq..."
            sudo apt-get install -y -qq jq > /dev/null 2>&1
        fi
        echo "  jq: OK"
EOF
    echo ""

    # Create users
    echo "Step 4: Creating demo users..."
    ssh -t -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "cd $REMOTE_DIR && chmod +x create-demo-users-occ.sh && ./create-demo-users-occ.sh"

    echo ""
    echo "Step 5: Importing avatars..."
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "cd /var/www/nextcloud && sudo -u www-data php $REMOTE_DIR/set-avatar.php --bulk $REMOTE_DIR"

    # Cleanup
    echo ""
    echo "Step 6: Cleaning up..."
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "rm -rf $REMOTE_DIR"
fi

echo ""
echo "Deployment complete!"
echo ""
if [ "$DELETE_MODE" = true ]; then
    echo "  Demo users deleted from $SERVER_NAME"
else
    echo "  100 demo users created on $SERVER_NAME with avatars"
    echo "  Default password: DemoUser123!"
fi
echo ""
