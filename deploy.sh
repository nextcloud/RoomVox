#!/bin/bash

# RoomVox Deployment Script
# Deploys to Nextcloud test server

set -e

# Configuration
APP_NAME="roomvox"
REMOTE_USER="rdekker"
REMOTE_PATH="/var/www/nextcloud/apps"
SSH_KEY="~/.ssh/sur"
LOCAL_PATH="$(pwd)"

# Server selection based on argument (default: 3dev)
case "${1:-3dev}" in
    1dev|1)
        REMOTE_HOST="145.38.193.235"
        SERVER_NAME="1dev"
        ;;
    3dev|3|"")
        REMOTE_HOST="145.38.188.218"
        SERVER_NAME="3dev"
        ;;
    *)
        echo "Unknown server: $1"
        echo "Usage: ./deploy.sh [1dev|3dev]  (default: 3dev)"
        exit 1
        ;;
esac

# Extract version from package.json
VERSION=$(grep '"version"' package.json | head -1 | sed 's/.*"version": "\([^"]*\)".*/\1/')

echo "RoomVox Deployment Script"
echo "=============================="
echo "Version: $VERSION"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"

# Files and folders to include in deployment
INCLUDE_ITEMS=(
    "appinfo"
    "lib"
    "l10n"
    "templates"
    "css"
    "img"
    "js"
    "vendor"
    "composer.json"
    "LICENSE"
    "README.md"
)

echo ""
echo "Step 1: Building frontend..."

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "  Installing dependencies..."
    npm install
fi

# Build
npm run build

if [ $? -ne 0 ]; then
    echo "Build failed!"
    exit 1
fi

echo "Build completed"

echo ""
echo "Step 2: Creating deployment package..."

# Create temporary directory
TEMP_DIR=$(mktemp -d)
DEPLOY_DIR="$TEMP_DIR/$APP_NAME"
mkdir -p "$DEPLOY_DIR"

# Copy files
for item in "${INCLUDE_ITEMS[@]}"; do
    if [ -e "$LOCAL_PATH/$item" ]; then
        echo "  Copying $item..."
        cp -r "$LOCAL_PATH/$item" "$DEPLOY_DIR/"
    else
        echo "  Warning: $item not found, skipping..."
    fi
done

# Create tarball
TARBALL="$TEMP_DIR/${APP_NAME}.tar.gz"
echo "  Creating tarball..."
cd "$TEMP_DIR"
tar -czf "$TARBALL" "$APP_NAME"

echo "Deployment package created"

echo ""
echo "Step 3: Deploying to server..."
echo "  Server: $REMOTE_HOST"
echo "  Path: $REMOTE_PATH/$APP_NAME"

# Upload tarball
echo "  Uploading package..."
scp -i "$SSH_KEY" "$TARBALL" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/${APP_NAME}.tar.gz"

# Extract and setup on server
echo "  Extracting on server..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
    set -e

    # Navigate to apps directory
    cd $REMOTE_PATH

    # Backup existing installation if present
    if [ -d "$APP_NAME" ]; then
        echo "  Backing up existing installation..."
        BACKUP_NAME="${APP_NAME}.backup.\$(date +%Y%m%d_%H%M%S)"
        sudo mv $APP_NAME "/tmp/\$BACKUP_NAME" || true
        echo "  Backup saved to /tmp/\$BACKUP_NAME"
    fi

    # Extract new version
    echo "  Extracting new version..."
    sudo tar -xzf /tmp/${APP_NAME}.tar.gz -C $REMOTE_PATH

    # Set permissions
    echo "  Setting permissions..."
    sudo chown -R www-data:www-data $REMOTE_PATH/$APP_NAME
    sudo chmod -R 755 $REMOTE_PATH/$APP_NAME

    # Clean up
    rm /tmp/${APP_NAME}.tar.gz

    # Remove old backups, keep only the 2 most recent
    echo "  Cleaning up old backups..."
    ls -d /tmp/${APP_NAME}.backup.* 2>/dev/null | sort -r | tail -n +3 | xargs -r sudo rm -rf

    echo "  Files deployed"
EOF

echo ""
echo "Step 4: Enabling app and clearing cache..."
ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
    set -e
    cd /var/www/nextcloud

    # Disable and re-enable app to refresh routes
    echo "  Disabling app..."
    sudo -u www-data php occ app:disable $APP_NAME || true

    echo "  Enabling app..."
    sudo -u www-data php occ app:enable $APP_NAME || true

    # Clear cache to ensure routes are refreshed
    echo "  Clearing cache..."
    sudo -u www-data php occ maintenance:repair --include-expensive || true

    echo "  App enabled and cache cleared"
EOF

echo ""
echo "Step 5: Health check..."
HEALTH_CHECK=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "curl -s -o /dev/null -w '%{http_code}' http://localhost/apps/roomvox/ 2>/dev/null || echo '000'")

if [ "$HEALTH_CHECK" = "200" ] || [ "$HEALTH_CHECK" = "302" ] || [ "$HEALTH_CHECK" = "303" ]; then
    echo "  Health check passed (HTTP $HEALTH_CHECK)"
else
    echo "  Health check returned HTTP $HEALTH_CHECK (may require login)"
fi

# Verify deployed version
echo ""
echo "Step 6: Verifying deployed version..."
DEPLOYED_VERSION=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "grep '<version>' $REMOTE_PATH/$APP_NAME/appinfo/info.xml | sed 's/.*<version>\([^<]*\)<\/version>.*/\1/'")
echo "  Deployed version: $DEPLOYED_VERSION"

if [ "$VERSION" = "$DEPLOYED_VERSION" ]; then
    echo "  Version matches!"
else
    echo "  Version mismatch! Local: $VERSION, Deployed: $DEPLOYED_VERSION"
fi

# Cleanup local temp files
rm -rf "$TEMP_DIR"

echo ""
echo "Deployment completed successfully!"
echo ""
echo "Summary:"
echo "  App Name: $APP_NAME"
echo "  Version: $DEPLOYED_VERSION"
echo "  Server: $REMOTE_HOST"
echo "  Status: Deployed and enabled"
echo ""
echo "Access RoomVox at:"
echo "  https://$REMOTE_HOST"
echo ""
echo "Rollback (if needed):"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'ls -la /tmp/${APP_NAME}.backup.*'"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo rm -rf $REMOTE_PATH/$APP_NAME && sudo mv /tmp/${APP_NAME}.backup.YYYYMMDD_HHMMSS $REMOTE_PATH/$APP_NAME'"
echo ""
echo "View logs:"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo tail -f /var/www/nextcloud/data/nextcloud.log'"
echo ""
