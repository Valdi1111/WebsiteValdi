#!/usr/bin/env bash
set -euo pipefail

# ==========================================================
#              MAIN CONFIGURATION (CONSTANTS)
# ==========================================================
DEFAULT_BASE_DOMAIN="valdi.ovh"
DEFAULT_PHP_VERSION="8.5"
SUBDOMAINS=(
  "books"
  "anime"
  "games"
  "videos"
)
# ==========================================================

# 1. Check for root privileges
if [ "$EUID" -ne 0 ]; then
  echo "[ERROR] Run this script with sudo or as root." >&2
  exit 1
fi

echo "=========================================================="
echo "     Automated Caddy + Mercure Hub Setup ($DEFAULT_BASE_DOMAIN)      "
echo "=========================================================="

REAL_USER="${SUDO_USER:-$USER}"

# 2. Interactive input
read -r -p "Enter the base domain [$DEFAULT_BASE_DOMAIN]: " BASE_DOMAIN
BASE_DOMAIN="${BASE_DOMAIN:-$DEFAULT_BASE_DOMAIN}"

read -r -p "Enter the email for the Let's Encrypt TLS certificates [admin@$BASE_DOMAIN]: " ADMIN_EMAIL
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$BASE_DOMAIN}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
if [ -f "$PARENT_DIR/composer.json" ] || [ -d "$PARENT_DIR/public" ]; then
  DEFAULT_PROJECT_DIR="$PARENT_DIR"
else
  DEFAULT_PROJECT_DIR="/var/www/$BASE_DOMAIN"
fi

read -r -p "Enter the Symfony project root path [$DEFAULT_PROJECT_DIR]: " INPUT_PROJECT_DIR
PROJECT_DIR="${INPUT_PROJECT_DIR:-$DEFAULT_PROJECT_DIR}"
SITE_PUBLIC_DIR="$PROJECT_DIR/public"

# Build the domain and CORS origin lists dynamically from the SUBDOMAINS array
HOSTS_DOMAINS="$BASE_DOMAIN mercure.$BASE_DOMAIN"
SITE_HOSTS="$BASE_DOMAIN"
CORS_ORIGINS="https://$BASE_DOMAIN"

for sub in "${SUBDOMAINS[@]}"; do
  [ -z "$sub" ] && continue
  HOSTS_DOMAINS="$HOSTS_DOMAINS ${sub}.${BASE_DOMAIN}"
  SITE_HOSTS="$SITE_HOSTS, ${sub}.${BASE_DOMAIN}"
  CORS_ORIGINS="$CORS_ORIGINS https://${sub}.${BASE_DOMAIN}"
done

# 3. Automatic /etc/hosts setup for local domains on Linux
IS_LOCAL_DOMAIN=false
if [[ "$BASE_DOMAIN" =~ \.(wip|local|test|example|invalid|localhost|lan|home|internal)$ ]] || [ "$BASE_DOMAIN" = "localhost" ]; then
  IS_LOCAL_DOMAIN=true
  echo ""
  echo "==> Configuring /etc/hosts to resolve the local domains..."
  if ! grep -qw "$BASE_DOMAIN" /etc/hosts 2>/dev/null; then
    echo "127.0.0.1 $HOSTS_DOMAINS" >> /etc/hosts
    echo " - Added to /etc/hosts: 127.0.0.1 $HOSTS_DOMAINS"
  else
    echo " - /etc/hosts already has an entry for $BASE_DOMAIN"
  fi
fi

# 4. Detect and stop conflicting web servers (ports 80/443)
echo ""
echo "==> Checking for web servers conflicting on ports 80 and 443..."
for srv in apache2 nginx lighttpd; do
  if systemctl is-active --quiet "$srv" 2>/dev/null; then
    echo " - Service '$srv' is running. Stopping and disabling it to free ports 80 and 443..."
    systemctl stop "$srv" 2>/dev/null || true
    systemctl disable "$srv" 2>/dev/null || true
  fi
done

# 5. Add root and the current user to the www-data group
echo ""
echo "==> Adding users to the 'www-data' group..."
usermod -aG www-data root
echo " - Added root"

if [ -n "$REAL_USER" ] && id -u "$REAL_USER" >/dev/null 2>&1; then
  usermod -aG www-data "$REAL_USER"
  echo " - Added $REAL_USER"
fi

# Helper function that installs PHP-FPM + the required extensions
install_php_stack() {
  local ver="$1"
  local extensions=(
    "php${ver}-fpm"
    "php${ver}-cli"
    "php${ver}-common"
    "php${ver}-ldap"
    "php${ver}-imap"
    "php${ver}-readline"
    "php${ver}-imagick"
    "php${ver}-mbstring"
    "php${ver}-mysql"
    "php${ver}-curl"
    "php${ver}-zip"
    "php${ver}-xml"
    "php${ver}-intl"
  )

  echo "==> Installing PHP ${ver}-FPM and extensions..."
  apt-get update -qq
  apt-get install -y -qq software-properties-common >/dev/null

  if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
    echo " - Adding the ondrej/php PPA repository..."
    add-apt-repository ppa:ondrej/php -y >/dev/null
    apt-get update -qq
  fi

  echo " - Installing packages: ${extensions[*]}..."
  apt-get install -y "${extensions[@]}"
  systemctl enable --now "php${ver}-fpm"
}

# 6. Select or install PHP-FPM and write the custom INI configuration
echo ""
echo "==> Detecting installed PHP-FPM versions..."
AVAILABLE_SOCKS=()
if [ -d /run/php ]; then
  while IFS= read -r sock; do
    [ -n "$sock" ] && AVAILABLE_SOCKS+=("$sock")
  done < <(find /run/php/ -maxdepth 1 -name "php*-fpm.sock" 2>/dev/null | sort -V)
fi

PHP_SOCK=""
if [ ${#AVAILABLE_SOCKS[@]} -gt 0 ]; then
  echo "Select the PHP version to use:"
  for i in "${!AVAILABLE_SOCKS[@]}"; do
    sock_name=$(basename "${AVAILABLE_SOCKS[$i]}")
    php_ver=$(echo "$sock_name" | grep -oP '\d+\.\d+' || echo "$sock_name")
    echo "  $((i+1))) Use the installed PHP $php_ver (${AVAILABLE_SOCKS[$i]})"
  done
  OPT_INSTALL=$(( ${#AVAILABLE_SOCKS[@]} + 1 ))
  OPT_MANUAL=$(( ${#AVAILABLE_SOCKS[@]} + 2 ))
  echo "  ${OPT_INSTALL}) Install a new PHP-FPM version + extensions"
  echo "  ${OPT_MANUAL}) Manually enter the path/version of an existing socket"

  while true; do
    read -r -p "Choice [1-${OPT_MANUAL}]: " PHP_CHOICE
    if [[ "$PHP_CHOICE" =~ ^[0-9]+$ ]] && [ "$PHP_CHOICE" -ge 1 ] && [ "$PHP_CHOICE" -le "${#AVAILABLE_SOCKS[@]}" ]; then
      PHP_SOCK="${AVAILABLE_SOCKS[$((PHP_CHOICE-1))]}"
      break
    elif [ "$PHP_CHOICE" -eq "$OPT_INSTALL" ]; then
      read -r -p "Enter the PHP version to install [$DEFAULT_PHP_VERSION]: " INSTALL_VER
      INSTALL_VER="${INSTALL_VER:-$DEFAULT_PHP_VERSION}"
      install_php_stack "$INSTALL_VER"
      PHP_SOCK="/run/php/php${INSTALL_VER}-fpm.sock"
      break
    elif [ "$PHP_CHOICE" -eq "$OPT_MANUAL" ]; then
      read -r -p "Enter the desired PHP version [$DEFAULT_PHP_VERSION]: " MANUAL_VER
      MANUAL_VER="${MANUAL_VER:-$DEFAULT_PHP_VERSION}"
      PHP_SOCK="/run/php/php${MANUAL_VER}-fpm.sock"
      break
    else
      echo "Invalid choice, please try again."
    fi
  done
else
  echo "[WARNING] No PHP-FPM socket found in /run/php/."
  read -r -p "Install PHP-FPM and the required extensions now? [Y/n]: " DO_INSTALL_PHP
  DO_INSTALL_PHP="${DO_INSTALL_PHP:-Y}"
  if [[ "$DO_INSTALL_PHP" =~ ^[Yy]([Ee][Ss])?$ ]]; then
    read -r -p "Enter the PHP version to install [$DEFAULT_PHP_VERSION]: " INSTALL_VER
    INSTALL_VER="${INSTALL_VER:-$DEFAULT_PHP_VERSION}"
    install_php_stack "$INSTALL_VER"
    PHP_SOCK="/run/php/php${INSTALL_VER}-fpm.sock"
  else
    read -r -p "Manually enter the PHP version for the Caddyfile [$DEFAULT_PHP_VERSION]: " MANUAL_VER
    MANUAL_VER="${MANUAL_VER:-$DEFAULT_PHP_VERSION}"
    PHP_SOCK="/run/php/php${MANUAL_VER}-fpm.sock"
  fi
fi

SELECTED_PHP_VER=$(basename "$PHP_SOCK" | grep -oP '\d+\.\d+' || true)
echo " -> Selected socket: $PHP_SOCK"

if [ -n "$SELECTED_PHP_VER" ]; then
  if [ -x "/usr/bin/php$SELECTED_PHP_VER" ]; then
    update-alternatives --set php "/usr/bin/php$SELECTED_PHP_VER" >/dev/null 2>&1 || true
    echo " -> PHP CLI set to /usr/bin/php$SELECTED_PHP_VER"
  fi

  echo "==> Writing custom PHP settings (99-custom.ini) for PHP $SELECTED_PHP_VER..."
  if [ -d "/etc/php/$SELECTED_PHP_VER/fpm/conf.d" ]; then
    cat << 'EOF' > "/etc/php/$SELECTED_PHP_VER/fpm/conf.d/99-custom.ini"
date.timezone = Europe/Rome
date.default_latitude = 44.202091
date.default_longitude = 12.036725
max_execution_time = 300
memory_limit = 4G
; Large uploads (EPUB books, game pull exports); the POST limit must exceed the single-file limit
upload_max_filesize = 256M
post_max_size = 260M
EOF
    echo " - Created /etc/php/$SELECTED_PHP_VER/fpm/conf.d/99-custom.ini"
    systemctl restart "php${SELECTED_PHP_VER}-fpm" 2>/dev/null || true
  fi

  if [ -d "/etc/php/$SELECTED_PHP_VER/cli/conf.d" ]; then
    cat << 'EOF' > "/etc/php/$SELECTED_PHP_VER/cli/conf.d/99-custom.ini"
date.timezone = Europe/Rome
date.default_latitude = 44.202091
date.default_longitude = 12.036725
memory_limit = 4G
EOF
    echo " - Created /etc/php/$SELECTED_PHP_VER/cli/conf.d/99-custom.ini"
  fi
fi
echo ""

# 7. Install base dependencies
echo "==> Installing utility packages (curl, wget, tar, unzip, libcap2-bin, openssl, acl)..."
apt-get update -qq
apt-get install -y -qq curl wget tar unzip libcap2-bin openssl acl >/dev/null

# 7b. Install or update Composer
echo "==> Checking and installing Composer..."
if command -v php >/dev/null 2>&1; then
  if [ -f /usr/local/bin/composer ]; then
    echo " - Composer already installed, running self-update..."
    COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer self-update --quiet || true
  else
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
      echo "[ERROR] Invalid Composer installer checksum!" >&2
      rm -f /tmp/composer-setup.php
      exit 1
    fi

    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
    chmod +x /usr/local/bin/composer
    echo " - Composer installed in /usr/local/bin/composer"
  fi
else
  echo " [WARNING] PHP CLI binary not found, skipping Composer installation."
fi

# 7c. Install FFmpeg (includes ffprobe) and yt-dlp
echo "==> Installing FFmpeg / FFprobe and yt-dlp..."
apt-get install -y -qq ffmpeg >/dev/null
echo " - FFmpeg and FFprobe installed"

if [ -f /usr/local/bin/yt-dlp ]; then
  echo " - yt-dlp already installed, checking for updates..."
  /usr/local/bin/yt-dlp -U >/dev/null 2>&1 || true
else
  curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp" -o /usr/local/bin/yt-dlp
  chmod a+rx /usr/local/bin/yt-dlp
  echo " - yt-dlp installed in /usr/local/bin/yt-dlp"
fi
echo ""

# 8. Generate a secure shared JWT key (256-bit / 64 hex characters)
# Symfony MercureBundle uses a single MERCURE_JWT_SECRET for both publishing and the subscribe cookie.
MERCURE_JWT_KEY=$(openssl rand -hex 32)
PUBLISHER_KEY="$MERCURE_JWT_KEY"
SUBSCRIBER_KEY="$MERCURE_JWT_KEY"

# 9. Prepare the system directories
CADDY_DIR="/var/www/caddy"
LANDING_DIR="/var/www/mercure-landing"
CADDY_HOME="/var/lib/caddy"
LOG_FILE="/var/log/caddy.log"

echo "==> Creating the directory tree..."
mkdir -p "$CADDY_DIR" "$LANDING_DIR" "$SITE_PUBLIC_DIR" "$CADDY_HOME"
touch "$LOG_FILE"

# 10. Detect the architecture and download Mercure from GitHub Releases
ARCH=$(uname -m)
case "$ARCH" in
  x86_64)  MERCURE_ARCH="Linux_x86_64" ;;
  aarch64) MERCURE_ARCH="Linux_arm64" ;;
  armv7l)  MERCURE_ARCH="Linux_armv7" ;;
  *) echo "[ERROR] Unsupported architecture: $ARCH" >&2; exit 1 ;;
esac

echo "==> Finding and downloading the latest Mercure release ($MERCURE_ARCH)..."
DOWNLOAD_URL=$(curl -sSL "https://api.github.com/repos/dunglas/mercure/releases/latest" 2>/dev/null | grep -oE "https://[^\"]*mercure[^\"]*${MERCURE_ARCH}\.tar\.gz" | head -n 1 || true)

if [ -z "$DOWNLOAD_URL" ]; then
  LATEST_TAG=$(curl -sSL "https://api.github.com/repos/dunglas/mercure/releases/latest" 2>/dev/null | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/' || true)
  if [ -n "$LATEST_TAG" ]; then
    DOWNLOAD_URL="https://github.com/dunglas/mercure/releases/download/${LATEST_TAG}/mercure_${MERCURE_ARCH}.tar.gz"
  else
    DOWNLOAD_URL="https://github.com/dunglas/mercure/releases/latest/download/mercure_${MERCURE_ARCH}.tar.gz"
  fi
fi

echo " - Downloading from: $DOWNLOAD_URL"
curl -fsSL "$DOWNLOAD_URL" | tar -xz -C "$CADDY_DIR" mercure
chmod +x "$CADDY_DIR/mercure"

# 11. Grant a non-root user the capability to bind ports 80 and 443
echo "==> Setting CAP_NET_BIND_SERVICE on the binary..."
setcap 'cap_net_bind_service=+ep' "$CADDY_DIR/mercure"

# 12. Create the HTML landing page
echo "==> Generating the landing page in $LANDING_DIR/index.html..."
cat << 'EOF' > "$LANDING_DIR/index.html"
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Mercure Hub - Valdi.ovh</title>
    <style>
        :root { color-scheme: dark light; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            padding: 2.5rem;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        .dot {
            width: 8px;
            height: 8px;
            background: #4ade80;
            border-radius: 50%;
            box-shadow: 0 0 8px #4ade80;
        }
        h1 { font-size: 1.5rem; margin: 0 0 0.5rem; color: #f8fafc; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin: 0 0 1.5rem; }
        .endpoint {
            background: #0f172a;
            padding: 0.75rem;
            border-radius: 0.5rem;
            font-family: monospace;
            font-size: 0.85rem;
            color: #38bdf8;
            word-break: break-all;
            border: 1px solid #334155;
            margin-bottom: 1.5rem;
        }
        .footer { font-size: 0.75rem; color: #64748b; border-top: 1px solid #334155; padding-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="status-badge">
            <span class="dot"></span> Hub Online
        </div>
        <h1>Mercure Real-Time</h1>
        <p>The SSE streaming server for the Valdi.ovh applications is up and listening.</p>
        <div class="endpoint">/.well-known/mercure</div>
        <div class="footer">Caddy + Mercure Protocol Server</div>
    </div>
</body>
</html>
EOF

# 13. Generate the Caddyfile
echo "==> Writing the Caddyfile to $CADDY_DIR/Caddyfile..."

GLOBAL_TLS_DIRECTIVES="email $ADMIN_EMAIL"

if [ "$IS_LOCAL_DOMAIN" = true ]; then
  echo " -> Local/development domain detected ($BASE_DOMAIN): configured the internal CA (local_certs) and SNI fallback"
  GLOBAL_TLS_DIRECTIVES="email $ADMIN_EMAIL
    local_certs
    default_sni $BASE_DOMAIN"
  SITE_HOSTS="$SITE_HOSTS, localhost, 127.0.0.1"
  CORS_ORIGINS="$CORS_ORIGINS https://localhost https://127.0.0.1"
fi

cat << EOF > "$CADDY_DIR/Caddyfile"
{
    $GLOBAL_TLS_DIRECTIVES
}

# 1. Mercure hub
mercure.$BASE_DOMAIN {
    log {
        format filter {
            fields {
                request>uri query {
                    replace authorization REDACTED
                }
            }
        }
    }

    encode zstd gzip

    # Landing page at the root
    handle / {
        root * $LANDING_DIR
        file_server
    }

    # Mercure directive
    mercure {
        protocol_version_compatibility 8
        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY} HS256
        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} HS256

        cors_origins $CORS_ORIGINS
        publish_origins $CORS_ORIGINS
    }

    log_skip /robots.txt
    log_skip /healthz
    log_skip /favicon.ico

    header / Content-Type "text/html; charset=utf-8"

    respond /robots.txt "User-agent: *\nDisallow: /"
    respond /healthz 200
    respond "Not Found" 404
}

# 2. Symfony application and subdomains
$SITE_HOSTS {
    root * $PROJECT_DIR/public

    encode zstd gzip
    file_server

    # Files sent by PHP (videos, books, downloads) are served by Caddy, as mod_xsendfile did on Apache:
    # Symfony answers with an X-Accel-Redirect header and no body, and the PHP-FPM worker is freed at once
    # instead of staying busy for the whole stream. Caddy always sets both request headers, replacing
    # any value sent by the client.
    request_header X-Sendfile-Type X-Accel-Redirect
    request_header X-Accel-Mapping /=/
    # "intercept" and not handle_response inside php_fastcgi: there the FastCGI connection stays open
    # until the file is sent, and PHP-FPM keeps the worker until Caddy closes it
    intercept {
        @accel header X-Accel-Redirect *
        handle_response @accel {
            root * /
            rewrite * {resp.header.X-Accel-Redirect}
            method * GET
            # Never show the real path to the client
            header -X-Accel-Redirect
            file_server
        }
    }

    php_fastcgi unix/$PHP_SOCK {
        try_files {path} index.php
        resolve_root_symlink
        # Symfony trusts X-Sendfile-Type only on requests coming through here
        env SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER 1
    }

    @phpFile {
        path *.php*
    }
    error @phpFile "Not found" 404
}
EOF

# 14. Create the systemd unit file
echo "==> Configuring the systemd service (/etc/systemd/system/caddy.service)..."
cat << EOF > /etc/systemd/system/caddy.service
[Unit]
Description=Caddy Mercure Hub Service
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$CADDY_DIR
ExecStart=$CADDY_DIR/mercure run --config $CADDY_DIR/Caddyfile
Restart=always
RestartSec=5s
StartLimitBurst=5
StartLimitIntervalSec=33

# Environment and JWT keys
Environment=HOME=$CADDY_HOME
Environment=MERCURE_PUBLISHER_JWT_KEY=$PUBLISHER_KEY
Environment=MERCURE_SUBSCRIBER_JWT_KEY=$SUBSCRIBER_KEY

# Logging
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

# Process security and capabilities
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF

# 15. Base permissions for Caddy and its log
echo "==> Setting Caddy permissions..."
chown -R www-data:www-data "$CADDY_DIR" "$LANDING_DIR" "$CADDY_HOME" "$LOG_FILE"
chmod -R u=rwX,g=rwX,o=rX "$CADDY_DIR" "$LANDING_DIR"
chmod 700 "$CADDY_HOME"
chmod 644 "$LOG_FILE"

# 16. Advanced project permissions (setgid + ACL)
echo "==> Applying permissions and inheritance on $PROJECT_DIR..."
TARGET_OWNER="${REAL_USER:-www-data}"

# Give ownership to the real user (to work comfortably) and the www-data group
chown -R "$TARGET_OWNER:www-data" "$PROJECT_DIR"

# Standard read/write permissions for user and group, execute for directories
chmod -R u=rwX,g=rwX,o=rX "$PROJECT_DIR"

# Setgid bit (g+s): every new directory created inside inherits the www-data group
find "$PROJECT_DIR" -type d -exec chmod g+s {} +

# POSIX ACLs: make sure new files and directories created by Symfony/PHP or the user
# always get rwX for both the user and the www-data group by default
setfacl -R -d -m u::rwx,g::rwx,o::rx "$PROJECT_DIR" 2>/dev/null || true
setfacl -R -m u::rwx,g::rwx,o::rx "$PROJECT_DIR" 2>/dev/null || true

# 17. Enable the service
echo "==> Reloading systemd and starting the caddy service..."
systemctl daemon-reload
systemctl enable caddy.service
systemctl restart caddy.service

sleep 1
if ! systemctl is-active --quiet caddy.service; then
  echo ""
  echo "[ERROR] The Caddy service failed to start." >&2
  echo "--- Latest logs from $LOG_FILE ---" >&2
  tail -n 20 "$LOG_FILE" >&2 || true
  echo "-------------------------------" >&2
  exit 1
fi

# Copy Caddy's root CA certificate to a public location (Windows/browser) and into the Linux CA store (PHP/cURL)
LOCAL_ROOT_CA="$CADDY_HOME/.local/share/caddy/pki/authorities/local/root.crt"
if [ "$IS_LOCAL_DOMAIN" = true ]; then
  for _ in 1 2 3 4 5; do
    [ -f "$LOCAL_ROOT_CA" ] && break
    sleep 1
  done
fi

if [ -f "$LOCAL_ROOT_CA" ]; then
  cp "$LOCAL_ROOT_CA" "$CADDY_DIR/caddy-root-ca.crt" 2>/dev/null || true
  chmod 644 "$CADDY_DIR/caddy-root-ca.crt" 2>/dev/null || true

  if [ "$IS_LOCAL_DOMAIN" = true ] && [ -d /usr/local/share/ca-certificates ]; then
    echo "==> Installing Caddy's local root CA into the system store (for PHP/cURL)..."
    cp "$LOCAL_ROOT_CA" /usr/local/share/ca-certificates/caddy-root-ca.crt
    chmod 644 /usr/local/share/ca-certificates/caddy-root-ca.crt
    update-ca-certificates >/dev/null 2>&1 || true
    echo " - Installed /usr/local/share/ca-certificates/caddy-root-ca.crt"
  fi
fi

# 18. Final report
echo ""
echo "=========================================================="
echo "           INSTALLATION COMPLETED SUCCESSFULLY!           "
echo "=========================================================="
echo ""
echo "Added to the 'www-data' group: root and $REAL_USER"
echo "Project folder configured: $PROJECT_DIR"
echo " -> Ownership:               $TARGET_OWNER:www-data"
echo " -> Group inheritance:       Enabled (setgid g+s)"
echo " -> Permission inheritance:  Enabled (POSIX default ACLs)"
echo ""
echo "Configured domains:         $SITE_HOSTS"
echo "PHP socket in use:          $PHP_SOCK"
echo "Custom PHP config:          /etc/php/${SELECTED_PHP_VER:-$DEFAULT_PHP_VERSION}/{fpm,cli}/conf.d/99-custom.ini"
echo "Composer:                   $(/usr/local/bin/composer --version 2>/dev/null || echo 'Not installed')"
echo "yt-dlp:                     $(/usr/local/bin/yt-dlp --version 2>/dev/null || echo 'Not installed')"
echo "MERCURE_JWT_KEY (pub+sub):  $MERCURE_JWT_KEY"
if [ "$IS_LOCAL_DOMAIN" = true ] && [ -f "$CADDY_DIR/caddy-root-ca.crt" ]; then
  echo "Local root CA:              $CADDY_DIR/caddy-root-ca.crt (installed in the Linux store)"
fi
echo "----------------------------------------------------------"
echo ""
echo "To configure Symfony, add these to your .env / .env.local file:"
echo ""
echo "MERCURE_URL=https://mercure.$BASE_DOMAIN/.well-known/mercure"
echo "MERCURE_PUBLIC_URL=https://mercure.$BASE_DOMAIN/.well-known/mercure"
echo "MERCURE_JWT_SECRET=\"$MERCURE_JWT_KEY\""
echo ""
echo "Service status: systemctl status caddy"
echo "Live logs:      tail -f $LOG_FILE"

if grep -qi microsoft /proc/version 2>/dev/null; then
  WSL_IP=$(ip -4 addr show eth0 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n 1 || hostname -I | awk '{print $1}')
  echo ""
  echo "=========================================================="
  echo "              [WINDOWS / WSL NETWORK SETUP]               "
  echo "=========================================================="
  echo "CURRENT WSL IP: $WSL_IP"
  echo ""
  echo "1. UPDATE THE 'netsh portproxy' RULES IN WINDOWS:"
  echo "   (If you redirect from Windows or Pi-hole to the PC's IP):"
  echo "   Open PowerShell as ADMINISTRATOR and run:"
  echo ""
  echo "   netsh interface portproxy set v4tov4 listenport=80 connectaddress=$WSL_IP connectport=80"
  echo "   netsh interface portproxy set v4tov4 listenport=443 connectaddress=$WSL_IP connectport=443"
  echo ""
  echo "2. HOSTS MAPPING IN WINDOWS:"
  echo "   Add-Content -Path C:\\Windows\\System32\\drivers\\etc\\hosts -Value \"\`n127.0.0.1 $HOSTS_DOMAINS\""
  echo ""
  echo "3. PERMANENT FIX WITHOUT PORTPROXY (Windows 11):"
  echo "   Create or edit C:\\Users\\$REAL_USER\\.wslconfig with:"
  echo "   [wsl2]"
  echo "   networkingMode=mirrored"
  echo "   Then restart WSL with 'wsl --shutdown'. WSL will share the PC's IP directly!"
  echo "=========================================================="
fi
echo ""
echo "Note: to use the new group '$REAL_USER:www-data' in the current session,"
echo "run 'newgrp www-data' or log out and back in."
