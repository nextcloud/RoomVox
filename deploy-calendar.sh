#!/bin/bash

# RoomVox Calendar Patch Deployment Script
# Patches the Nextcloud Calendar app with improved room picker UI
#
# This script:
# 1. Clones the official NC Calendar (if not cached)
# 2. Applies our patched files over the source
# 3. Builds the Calendar app with webpack
# 4. Deploys the rebuilt JS to the target server
#
# Usage: ./deploy-calendar.sh [1dev|3dev]  (default: 3dev)

set -e

# Load deploy configuration
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ ! -f "$SCRIPT_DIR/deploy.conf" ]; then
    echo "Missing deploy.conf — copy deploy.conf.example and fill in your values"
    exit 1
fi
source "$SCRIPT_DIR/deploy.conf"

# Configuration
REMOTE_PATH="/var/www/nextcloud"
LOCAL_PATH="$SCRIPT_DIR"
PATCH_DIR="$LOCAL_PATH/nc-calendar-patch"
CALENDAR_VERSION="v6.2.0"
BUILD_DIR="/tmp/nc-calendar-build"

# Server selection (same as deploy.sh)
case "${1:-3dev}" in
    1dev|1)
        REMOTE_HOST="$SERVERS_1DEV"
        SERVER_NAME="1dev"
        ;;
    3dev|3|"")
        REMOTE_HOST="$SERVERS_3DEV"
        SERVER_NAME="3dev"
        ;;
    *)
        echo "Unknown server: $1"
        echo "Usage: ./deploy-calendar.sh [1dev|3dev]  (default: 3dev)"
        exit 1
        ;;
esac

echo "RoomVox Calendar Patch Deployment"
echo "================================="
echo "Server: $SERVER_NAME ($REMOTE_HOST)"
echo "Calendar version: $CALENDAR_VERSION"
echo ""

# Step 1: Clone or update NC Calendar
echo "Step 1: Preparing NC Calendar source..."
if [ -d "$BUILD_DIR/.git" ]; then
    echo "  Using cached clone at $BUILD_DIR"
    cd "$BUILD_DIR"
    git checkout . 2>/dev/null
    git checkout main 2>/dev/null || true
else
    echo "  Cloning nextcloud/calendar..."
    rm -rf "$BUILD_DIR"
    git clone --depth 1 --branch "$CALENDAR_VERSION" https://github.com/nextcloud/calendar.git "$BUILD_DIR" 2>/dev/null || \
    git clone --depth 1 https://github.com/nextcloud/calendar.git "$BUILD_DIR"
fi

# Step 2: Apply patches
echo ""
echo "Step 2: Applying RoomVox patches..."
cp -r "$PATCH_DIR"/src/* "$BUILD_DIR/src/"
echo "  Patched files:"
echo "    - src/models/principal.js"
echo "    - src/components/Editor/Resources/ResourceList.vue"
echo "    - src/components/Editor/Resources/ResourceRoomCard.vue (new)"
echo "    - src/components/Editor/Resources/ResourceListSearch.vue"

# Step 3: Build
echo ""
echo "Step 3: Building Calendar app..."
cd "$BUILD_DIR"
if [ ! -d "node_modules" ]; then
    echo "  Installing dependencies..."
    npm ci --silent
fi
echo "  Running webpack..."
npm run build 2>&1 | tail -3

if [ $? -ne 0 ]; then
    echo "  Build failed!"
    exit 1
fi
echo "  Build completed"

# Step 4: Deploy JS to server
echo ""
echo "Step 4: Deploying to $SERVER_NAME..."

CALENDAR_JS_DIR="$REMOTE_PATH/apps/calendar/js"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Upload via temp dir
echo "  Uploading JS files..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p /tmp/nc-calendar-js"
scp -i "$SSH_KEY" -q "$BUILD_DIR"/js/* "${REMOTE_USER}@${REMOTE_HOST}:/tmp/nc-calendar-js/"

# Backup, deploy, fix permissions, remove signature
echo "  Backing up and deploying..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
    set -e
    sudo cp -r $CALENDAR_JS_DIR ${CALENDAR_JS_DIR}.bak.${TIMESTAMP}
    sudo cp /tmp/nc-calendar-js/* $CALENDAR_JS_DIR/
    sudo chown -R www-data:www-data $CALENDAR_JS_DIR/
    sudo rm -f $REMOTE_PATH/apps/calendar/appinfo/signature.json
    rm -rf /tmp/nc-calendar-js

    # Clean old backups, keep 2 most recent
    ls -d ${CALENDAR_JS_DIR}.bak.* 2>/dev/null | sort -r | tail -n +3 | xargs -r sudo rm -rf

    echo "  Deployed successfully"
EOF

echo ""
echo "Deployment completed!"
echo ""
echo "  Backup: ${CALENDAR_JS_DIR}.bak.${TIMESTAMP}"
echo "  Clear browser cache (Ctrl+Shift+R) to see changes."
echo ""
echo "Rollback (if needed):"
echo "  ssh -i $SSH_KEY ${REMOTE_USER}@${REMOTE_HOST} 'sudo rm -rf $CALENDAR_JS_DIR && sudo mv ${CALENDAR_JS_DIR}.bak.${TIMESTAMP} $CALENDAR_JS_DIR'"
