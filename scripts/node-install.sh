#!/usr/bin/env bash
set -euo pipefail

# Check for root privileges
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Run this script with root privileges (e.g. sudo bash $0)" >&2
  exit 1
fi

NODE_USER="node"
NODE_GROUP="node"
NODE_HOME="/opt/node"
TARGET_BIN_DIR="$NODE_HOME/20-lts"
REAL_USER="${SUDO_USER:-$USER}"

# Core Node runtime executables
ESSENTIAL_BINS=("node" "npm" "npx" "corepack")

echo "==> 1. Installing system dependencies..."
apt-get update -qq
apt-get install -y -qq curl tar acl >/dev/null

echo "==> 2. Creating system user and group '$NODE_USER'..."
if ! getent group "$NODE_GROUP" >/dev/null; then
  groupadd -r "$NODE_GROUP"
fi

if ! id -u "$NODE_USER" >/dev/null 2>&1; then
  useradd -r -m -d "$NODE_HOME" -s /bin/bash -g "$NODE_GROUP" "$NODE_USER"
else
  usermod -d "$NODE_HOME" -m "$NODE_USER" 2>/dev/null || true
  mkdir -p "$NODE_HOME"
fi

echo "==> 3. Adding users to the '$NODE_GROUP' group..."
if [ -n "$REAL_USER" ] && id -u "$REAL_USER" >/dev/null 2>&1; then
  usermod -aG "$NODE_GROUP" "$REAL_USER"
  echo " - Added $REAL_USER"
fi

usermod -aG "$NODE_GROUP" root
echo " - Added root"

if id -u www-data >/dev/null 2>&1; then
  usermod -aG "$NODE_GROUP" www-data
  echo " - Added www-data"
fi

echo "==> 4. Installing NVM and Node 20 LTS..."
sudo -u "$NODE_USER" -H bash -c '
  export HOME="/opt/node"

  if [ ! -d "$HOME/.nvm" ]; then
    curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
  fi

  export NVM_DIR="$HOME/.nvm"
  [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

  nvm install --lts=iron
  nvm alias default "lts/iron"
'

echo "==> 5. Detecting the Node binary path..."
NODE_BIN_DIR=$(sudo -u "$NODE_USER" -H bash -c '
  export HOME="/opt/node"
  export NVM_DIR="$HOME/.nvm"
  [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
  nvm which default | sed "s/\/node$//"
')

if [ -z "$NODE_BIN_DIR" ] || [ ! -d "$NODE_BIN_DIR" ]; then
  echo "[ERROR] Could not find the Node bin directory in NVM." >&2
  exit 1
fi
echo " - Binary source: $NODE_BIN_DIR"

echo ""
read -r -p "Install PM2 globally as the node user? [y/N] " install_pm2_resp
case "$install_pm2_resp" in
  [yY][eE][sS]|[yY])
    echo "==> Installing PM2..."
    sudo -u "$NODE_USER" -H bash -c '
      export HOME="/opt/node"
      export NVM_DIR="$HOME/.nvm"
      [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
      npm install -g pm2
    '
    ESSENTIAL_BINS+=("pm2")
    echo " - PM2 installed successfully."
    ;;
  *)
    echo " - Skipped PM2 installation."
    ;;
esac

echo ""
echo "==> 6. Creating symlinks in $TARGET_BIN_DIR..."
mkdir -p "$TARGET_BIN_DIR"

for bin in "${ESSENTIAL_BINS[@]}"; do
  src_path="$NODE_BIN_DIR/$bin"
  if [ -x "$src_path" ]; then
    ln -sfn "$src_path" "$TARGET_BIN_DIR/$bin"
    echo " - Linked: $bin -> $TARGET_BIN_DIR/$bin"
  fi
done

echo "==> 7. Setting permissions, setgid and default ACLs on $NODE_HOME..."
# Recursive ownership to the node user and group
chown -R "$NODE_USER:$NODE_GROUP" "$NODE_HOME"

# Standard Linux permissions
chmod -R u=rwX,g=rwX,o=rX "$NODE_HOME"

# Setgid (g+s) on all directories to enforce the owning group
find "$NODE_HOME" -type d -exec chmod g+s {} +

# POSIX ACLs: apply rwX to existing entries and set the default rules for new files/directories
setfacl -R -m u::rwx,g::rwx,o::rx "$NODE_HOME"
setfacl -R -d -m u::rwx,g::rwx,o::rx "$NODE_HOME"

echo ""
echo "==> Setup completed in $TARGET_BIN_DIR."
echo ""

# Interactive prompt for /usr/local/bin
read -r -p "Also create the symlinks in /usr/local/bin to make them available globally on the PATH? [y/N] " response
case "$response" in
  [yY][eE][sS]|[yY])
    echo "==> Creating symlinks in /usr/local/bin..."
    for bin in "${ESSENTIAL_BINS[@]}"; do
      if [ -e "$TARGET_BIN_DIR/$bin" ]; then
        ln -sfn "$TARGET_BIN_DIR/$bin" "/usr/local/bin/$bin"
        echo " - Linked: /usr/local/bin/$bin -> $TARGET_BIN_DIR/$bin"
      fi
    done
    echo "The commands are now available everywhere without changing the PATH."
    ;;
  *)
    echo "Skipped creating symlinks in /usr/local/bin."
    echo "To use the commands, point directly to '$TARGET_BIN_DIR' or add it to your PATH."
    ;;
esac

echo ""
echo "Setup finished. Remember to run 'newgrp $NODE_GROUP' to refresh the current session."