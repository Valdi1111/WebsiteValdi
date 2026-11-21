#!/usr/bin/env bash
set -euo pipefail

# ==========================================================
#              MAIN CONFIGURATION (CONSTANTS)
# ==========================================================
SHARED_GROUP="condivisi"
DEFAULT_BASE_PATH="/media"
# ==========================================================

# 1. Check for root privileges
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Run this script with sudo or as root." >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-$USER}"

echo "=========================================================="
echo "             Shared Folder Setup ($SHARED_GROUP)              "
echo "=========================================================="

# 2. Get the folder path (from the CLI argument or interactively)
TARGET_DIR="${1:-}"
if [ -z "$TARGET_DIR" ]; then
  read -r -p "Enter the path of the folder to share (e.g. $DEFAULT_BASE_PATH/xyz): " INPUT_DIR
  TARGET_DIR="${INPUT_DIR:-}"
fi

if [ -z "$TARGET_DIR" ]; then
  echo "[ERROR] No path specified. Operation cancelled." >&2
  exit 1
fi

# Strip any trailing slash (e.g. /media/xyz/ -> /media/xyz)
TARGET_DIR="${TARGET_DIR%/}"

if [ ! -d "$TARGET_DIR" ]; then
  read -r -p "The folder '$TARGET_DIR' does not exist. Create it now? [Y/n]: " CREATE_DIR
  CREATE_DIR="${CREATE_DIR:-Y}"
  if [[ "$CREATE_DIR" =~ ^[Yy]([Ee][Ss])?$ ]]; then
    mkdir -p "$TARGET_DIR"
    echo " - Created directory: $TARGET_DIR"
  else
    echo "[ERROR] Directory does not exist. Operation cancelled." >&2
    exit 1
  fi
fi

# 3. Install the acl package if missing
if ! command -v setfacl >/dev/null 2>&1; then
  echo "==> Installing the 'acl' package..."
  apt-get update -qq
  apt-get install -y -qq acl >/dev/null
fi

# 4. Create the 'condivisi' group and add users to it
echo ""
echo "==> Configuring the '$SHARED_GROUP' group and users..."
if ! getent group "$SHARED_GROUP" >/dev/null 2>&1; then
  groupadd "$SHARED_GROUP"
  echo " - Created system group: $SHARED_GROUP"
else
  echo " - Group '$SHARED_GROUP' already exists"
fi

# Users to add to the group (avoids duplicates when REAL_USER is root)
USERS_TO_ADD=("root" "www-data" "plex")
if [ -n "$REAL_USER" ] && [ "$REAL_USER" != "root" ]; then
  USERS_TO_ADD+=("$REAL_USER")
fi

for usr in "${USERS_TO_ADD[@]}"; do
  if id -u "$usr" >/dev/null 2>&1; then
    usermod -aG "$SHARED_GROUP" "$usr"
    echo " - Added user '$usr' to group '$SHARED_GROUP'"
  else
    echo " - User '$usr' not found on this system (skipped)"
  fi
done

# 5. Apply ownership, permissions and POSIX ACLs
echo ""
echo "==> Applying permissions and inheritance on $TARGET_DIR..."
TARGET_OWNER="${REAL_USER:-root}"

# Owners: real user + condivisi group
chown -R "$TARGET_OWNER:$SHARED_GROUP" "$TARGET_DIR"

# Set rw-rw-r-- on regular files, but KEEP (+x) on directories and already executable files (.sh)
chmod -R u=rwX,g=rwX,o=rX "$TARGET_DIR"

# Set the setgid bit (2775) on all directories so they inherit the condivisi group
find "$TARGET_DIR" -type d -exec chmod g+s {} +

# Make sure any .sh files are always executable by owner and group
find "$TARGET_DIR" -type f -name "*.sh" -exec chmod ug+x {} +

# Default POSIX ACLs: new files/directories created by PHP/Symfony, Plex or Samba
# always keep read/write access for the owner and the condivisi group
setfacl -R -d -m u::rwx,g::rwx,o::rx "$TARGET_DIR" 2>/dev/null || true
setfacl -R -m u::rwX,g::rwX,o::rX "$TARGET_DIR" 2>/dev/null || true

# Restart services running as www-data / plex so the new group membership applies right away
for svc in php*-fpm plexmediaserver; do
  if systemctl is-active --quiet "$svc" 2>/dev/null; then
    systemctl restart "$svc" 2>/dev/null || true
  fi
done

# 6. Optional Samba configuration (/etc/samba/smb.conf)
SHARE_NAME="$(basename "$TARGET_DIR")"
SAMBA_CONFIGURED=false

if command -v smbd >/dev/null 2>&1 && [ -f /etc/samba/smb.conf ]; then
  echo ""
  read -r -p "Samba detected. Add/check the [$SHARE_NAME] share in /etc/samba/smb.conf? [y/N]: " ADD_SAMBA
  ADD_SAMBA="${ADD_SAMBA:-N}"
  if [[ "$ADD_SAMBA" =~ ^[Yy]([Ee][Ss])?$ ]]; then
    if grep -q "^\[$SHARE_NAME\]" /etc/samba/smb.conf; then
      echo " - [WARNING] The [$SHARE_NAME] section already exists in /etc/samba/smb.conf. No changes made."
    else
      cat << EOF >> /etc/samba/smb.conf

[$SHARE_NAME]
   comment = $SHARE_NAME
   path = $TARGET_DIR
   browsable = yes
   writable = yes
   guest ok = no
   force group = $SHARED_GROUP
   create mask = 0664
   directory mask = 2775
   inherit permissions = yes
EOF
      echo " - Added section [$SHARE_NAME] to /etc/samba/smb.conf"
      if testparm -s >/dev/null 2>&1; then
        systemctl restart smbd nmbd 2>/dev/null || systemctl restart smbd 2>/dev/null || true
        echo " - Samba service restarted successfully"
        SAMBA_CONFIGURED=true
      else
        echo " [ERROR] testparm found a syntax error in /etc/samba/smb.conf! Check the file." >&2
      fi
    fi
  fi
fi

# 7. Final report
echo ""
echo "=========================================================="
echo "          SHARED FOLDER CONFIGURED SUCCESSFULLY!          "
echo "=========================================================="
echo "Path:                    $TARGET_DIR"
echo "Ownership:               $TARGET_OWNER:$SHARED_GROUP"
echo "Permissions (dir/file):  2775 (setgid) / 0664 + POSIX ACL"
echo "Group members:           $(getent group "$SHARED_GROUP" | cut -d: -f4)"

if [ "$SAMBA_CONFIGURED" = false ]; then
  echo ""
  echo "Samba configuration block (/etc/samba/smb.conf):"
  echo "----------------------------------------------------------"
  cat << EOF
[$SHARE_NAME]
   comment = $SHARE_NAME
   path = $TARGET_DIR
   browsable = yes
   writable = yes
   guest ok = no

   force group = $SHARED_GROUP
   create mask = 0664
   directory mask = 2775
   inherit permissions = yes
EOF
  echo "----------------------------------------------------------"
fi

echo ""
echo "Note: to use the new '$SHARED_GROUP' group in your current terminal session,"
echo "run 'newgrp $SHARED_GROUP' or log out and back in."
