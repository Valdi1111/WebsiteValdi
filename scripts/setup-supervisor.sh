#!/usr/bin/env bash
set -euo pipefail

# ==========================================================
#        MAIN CONFIGURATION (CONSTANTS)
# ==========================================================
GROUP_NAME="website-valdi"
WORKER_USER="www-data"
CONF_DIR="/etc/supervisor/conf.d"
# ==========================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE_DIR="$SCRIPT_DIR/supervisor"
PROGRAMS_LIST="$TEMPLATE_DIR/programs.list"
CONF_FILE="$CONF_DIR/$GROUP_NAME.conf"

usage() {
  cat <<EOF
Usage: sudo $0 [--output FILE]

Generates $CONF_FILE from the templates and the program list in scripts/supervisor/,
with every program in the Supervisor group "$GROUP_NAME", then reloads Supervisor.

  --output FILE   only render the configuration into FILE (no root, no installation, no reload)
EOF
}

die() {
  echo "[ERROR] $*" >&2
  exit 1
}

# Removes leading and trailing whitespace
trim() {
  local value="$1"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "$value"
}

# Renders a template replacing each {{PLACEHOLDER}} with its value. Pattern and replacement are quoted,
# so characters such as "&" or "*" in the values are copied literally (bash 5.2 patsub_replacement).
render_template() {
  local content
  content="$(<"$1")"
  shift
  while [ $# -gt 0 ]; do
    content="${content//"$1"/"$2"}"
    shift 2
  done
  printf '%s\n' "$content"
}

OUTPUT_FILE=""
while [ $# -gt 0 ]; do
  case "$1" in
    --output)
      [ $# -ge 2 ] || die "--output needs a file path."
      OUTPUT_FILE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      exit 1
      ;;
  esac
done

# 1. Root privileges check (not needed to only render the configuration)
if [ -z "$OUTPUT_FILE" ] && [ "$EUID" -ne 0 ]; then
  die "Run this script with sudo or as root."
fi

[ -f "$PROGRAMS_LIST" ] || die "Program list not found: $PROGRAMS_LIST"

echo "=========================================================="
echo "        Supervisor setup (group $GROUP_NAME)"
echo "=========================================================="

# 2. Supervisor installation
if [ -z "$OUTPUT_FILE" ]; then
  if ! command -v supervisorctl >/dev/null 2>&1; then
    echo "==> Installing Supervisor..."
    apt-get update -qq
    apt-get install -y supervisor
  else
    echo "==> Supervisor is already installed."
  fi
  systemctl enable --now supervisor >/dev/null 2>&1 \
    || echo " - [WARNING] Could not enable the supervisor service with systemctl: start it manually if needed."
fi

# 3. Interactive input: project path and deploy user
DEFAULT_APP_DIR="$(dirname "$SCRIPT_DIR")"
read -r -p "Project root path [$DEFAULT_APP_DIR]: " INPUT_APP_DIR
APP_DIR="${INPUT_APP_DIR:-$DEFAULT_APP_DIR}"
APP_DIR="${APP_DIR%/}"
[ -f "$APP_DIR/bin/console" ] || die "No Symfony project found in $APP_DIR (bin/console is missing)."

DEFAULT_DEPLOY_USER="${SUDO_USER:-$(id -un)}"
read -r -p "Deploy user (runs node-services) [$DEFAULT_DEPLOY_USER]: " INPUT_DEPLOY_USER
DEPLOY_USER="${INPUT_DEPLOY_USER:-$DEFAULT_DEPLOY_USER}"
DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6 || true)"
[ -n "$DEPLOY_HOME" ] || die "User '$DEPLOY_USER' not found."
if [ ! -s "$DEPLOY_HOME/.nvm/nvm.sh" ]; then
  echo " - [WARNING] $DEPLOY_HOME/.nvm/nvm.sh not found: node-services will not start until NVM is installed for $DEPLOY_USER."
fi

# 4. Rendering of programs.list
echo ""
echo "==> Generating the configuration from $PROGRAMS_LIST..."

PROGRAMS=()
declare -A SEEN_PROGRAMS=()
RENDERED=""
LINE_NUMBER=0

while IFS= read -r line || [ -n "$line" ]; do
  LINE_NUMBER=$((LINE_NUMBER + 1))
  line="$(trim "${line%$'\r'}")"
  if [ -z "$line" ] || [[ "$line" == \#* ]]; then
    continue
  fi

  IFS='|' read -r type name queue options numprocs <<< "$line"
  type="$(trim "${type:-}")"
  name="$(trim "${name:-}")"
  queue="$(trim "${queue:-}")"
  options="$(trim "${options:-}")"
  numprocs="$(trim "${numprocs:-}")"

  [[ "$name" =~ ^[a-z0-9][a-z0-9-]*$ ]] || die "Line $LINE_NUMBER: invalid program name '$name'."

  case "$type" in
    node)
      template="node.conf.tpl"
      program="website-$name"
      ;;
    messenger)
      template="messenger.conf.tpl"
      program="website-messenger-$name"
      ;;
    messenger-multi)
      template="messenger-multi.conf.tpl"
      program="website-messenger-$name"
      [[ "$numprocs" =~ ^[1-9][0-9]*$ ]] || die "Line $LINE_NUMBER: messenger-multi needs a positive numprocs (got '$numprocs')."
      ;;
    scheduler)
      template="scheduler.conf.tpl"
      program="website-scheduler-$name"
      ;;
    *)
      die "Line $LINE_NUMBER: unknown program type '$type' (expected node, messenger, messenger-multi or scheduler)."
      ;;
  esac

  if [ "$type" != "node" ] && [ -z "$queue" ]; then
    die "Line $LINE_NUMBER: missing queue for $program."
  fi
  [ -z "${SEEN_PROGRAMS[$program]:-}" ] || die "Line $LINE_NUMBER: duplicate program $program."
  SEEN_PROGRAMS[$program]=1

  block="$(render_template "$TEMPLATE_DIR/$template" \
    '{{NAME}}' "$program" \
    '{{QUEUE}}' "$queue" \
    '{{OPTIONS}}' "$options" \
    '{{NUMPROCS}}' "${numprocs:-1}" \
    '{{APP_DIR}}' "$APP_DIR" \
    '{{WORKER_USER}}' "$WORKER_USER" \
    '{{DEPLOY_USER}}' "$DEPLOY_USER" \
    '{{DEPLOY_HOME}}' "$DEPLOY_HOME")"

  if [[ "$block" == *"{{"*"}}"* ]]; then
    die "Template $template contains an unknown placeholder."
  fi

  RENDERED+="$block"$'\n\n'
  PROGRAMS+=("$program")
  echo " - $program"
done < "$PROGRAMS_LIST"

[ ${#PROGRAMS[@]} -gt 0 ] || die "No program found in $PROGRAMS_LIST."

PROGRAM_NAMES="$(IFS=,; echo "${PROGRAMS[*]}")"
CONTENT="; Generated by scripts/setup-supervisor.sh from scripts/supervisor/ (templates + programs.list).
; Do not edit by hand: change programs.list and run the script again.

${RENDERED}[group:$GROUP_NAME]
programs=$PROGRAM_NAMES"

if [ -n "$OUTPUT_FILE" ]; then
  printf '%s\n' "$CONTENT" > "$OUTPUT_FILE"
  echo ""
  echo "==> Configuration written to $OUTPUT_FILE (${#PROGRAMS[@]} programs)."
  exit 0
fi

# 5. Installation of the generated file (with backup of the previous version)
echo ""
echo "==> Writing $CONF_FILE..."
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT
printf '%s\n' "$CONTENT" > "$TMP_FILE"

if [ -f "$CONF_FILE" ]; then
  if cmp -s "$TMP_FILE" "$CONF_FILE"; then
    echo " - No changes compared to the current file."
  else
    cp -p "$CONF_FILE" "$CONF_FILE.bak"
    echo " - Previous version saved to $CONF_FILE.bak"
  fi
fi
install -m 0644 "$TMP_FILE" "$CONF_FILE"
echo " - Written ${#PROGRAMS[@]} programs in the group $GROUP_NAME."

# 6. Old separate configuration files (they would define the same programs twice)
mapfile -t OLD_FILES < <(find "$CONF_DIR" -maxdepth 1 -type f -name 'website-*.conf' ! -name "$GROUP_NAME.conf" | sort)
if [ ${#OLD_FILES[@]} -gt 0 ]; then
  BACKUP_DIR="/etc/supervisor/conf.d.backup-$(date +%Y%m%d-%H%M%S)"
  echo ""
  echo "==> Found other website configuration files in $CONF_DIR:"
  printf '   %s\n' "${OLD_FILES[@]}"
  read -r -p "Move them to $BACKUP_DIR so the programs are not defined twice? [Y/n] " move_answer
  if [ -z "$move_answer" ] || [[ "$move_answer" =~ ^([Yy]|[Yy][Ee][Ss])$ ]]; then
    mkdir -p "$BACKUP_DIR"
    for old_file in "${OLD_FILES[@]}"; do
      mv "$old_file" "$BACKUP_DIR/"
      echo " - Moved $(basename "$old_file")"
    done
  else
    echo " - [WARNING] Files kept: the same programs may be defined twice and Supervisor may refuse the configuration."
  fi
fi

# 7. Log directory
LOG_DIR="$APP_DIR/var/log"
if [ ! -d "$LOG_DIR" ]; then
  echo ""
  echo "==> Creating $LOG_DIR..."
  mkdir -p "$LOG_DIR"
  chown "$DEPLOY_USER:$WORKER_USER" "$LOG_DIR"
  chmod 2775 "$LOG_DIR"
fi

# 8. Supervisor reload
echo ""
echo "==> Reloading Supervisor..."
supervisorctl reread
supervisorctl update
echo ""
supervisorctl status "$GROUP_NAME:*" || true

echo ""
echo "=========================================================="
echo " Done. Useful commands:"
echo "   sudo supervisorctl status $GROUP_NAME:*"
echo "   sudo supervisorctl restart $GROUP_NAME:*"
echo "   sudo supervisorctl restart $GROUP_NAME:website-node-services"
echo " To add a worker: add a line to scripts/supervisor/programs.list and run this script again."
echo "=========================================================="
