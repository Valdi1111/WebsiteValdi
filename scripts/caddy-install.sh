#!/usr/bin/env bash
set -euo pipefail

# 1. Controllo privilegi di root
if [ "$EUID" -ne 0 ]; then
  echo "[ERRORE] Esegui questo script con sudo o come root." >&2
  exit 1
fi

echo "=========================================================="
echo "    Setup Automatizzato Caddy + Mercure Hub (valdi.ovh)    "
echo "=========================================================="

REAL_USER="${SUDO_USER:-$USER}"

# 2. Richiesta dati interattiva
read -r -p "Inserisci il dominio base (es. valdi.ovh): " BASE_DOMAIN
BASE_DOMAIN="${BASE_DOMAIN:-valdi.ovh}"

read -r -p "Inserisci l'email per i certificati TLS Let's Encrypt: " ADMIN_EMAIL
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$BASE_DOMAIN}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
if [ -f "$PARENT_DIR/composer.json" ] || [ -d "$PARENT_DIR/public" ]; then
  DEFAULT_PROJECT_DIR="$PARENT_DIR"
else
  DEFAULT_PROJECT_DIR="/var/www/$BASE_DOMAIN"
fi

read -r -p "Inserisci il percorso della root del progetto Symfony [$DEFAULT_PROJECT_DIR]: " INPUT_PROJECT_DIR
PROJECT_DIR="${INPUT_PROJECT_DIR:-$DEFAULT_PROJECT_DIR}"
SITE_PUBLIC_DIR="$PROJECT_DIR/public"

# 3. Configurazione automatica /etc/hosts per domini locali su Linux
IS_LOCAL_DOMAIN=false
if [[ "$BASE_DOMAIN" =~ \.(wip|local|test|example|invalid|localhost|lan|home|internal)$ ]] || [ "$BASE_DOMAIN" = "localhost" ]; then
  IS_LOCAL_DOMAIN=true
  echo ""
  echo "==> Configurazione /etc/hosts per la risoluzione dei domini locali..."
  HOSTS_DOMAINS="$BASE_DOMAIN mercure.$BASE_DOMAIN books.$BASE_DOMAIN anime.$BASE_DOMAIN hoyoverse.$BASE_DOMAIN gryphline.$BASE_DOMAIN videos.$BASE_DOMAIN"
  if ! grep -qw "$BASE_DOMAIN" /etc/hosts 2>/dev/null; then
    echo "127.0.0.1 $HOSTS_DOMAINS" >> /etc/hosts
    echo " - Aggiunto a /etc/hosts: 127.0.0.1 $HOSTS_DOMAINS"
  else
    echo " - /etc/hosts contiene già una voce per $BASE_DOMAIN"
  fi
fi

# 4. Controllo e arresto web server in conflitto (porta 80/443)
echo ""
echo "==> Controllo web server in conflitto sulle porte 80 e 443..."
for srv in apache2 nginx lighttpd; do
  if systemctl is-active --quiet "$srv" 2>/dev/null; then
    echo " - Rilevato servizio '$srv' in esecuzione. Arresto e disabilitazione per liberare le porte 80 e 443..."
    systemctl stop "$srv" 2>/dev/null || true
    systemctl disable "$srv" 2>/dev/null || true
  fi
done

# 5. Associazione gruppo www-data a root e all'utente corrente
echo ""
echo "==> Associazione gruppo 'www-data' agli utenti..."
usermod -aG www-data root
echo " - Aggiunto a root"

if [ -n "$REAL_USER" ] && id -u "$REAL_USER" >/dev/null 2>&1; then
  usermod -aG www-data "$REAL_USER"
  echo " - Aggiunto a $REAL_USER"
fi

# 6. Selezione versione PHP-FPM
echo ""
echo "==> Rilevamento versioni PHP-FPM installate..."
AVAILABLE_SOCKS=()
if [ -d /run/php ]; then
  while IFS= read -r sock; do
    [ -n "$sock" ] && AVAILABLE_SOCKS+=("$sock")
  done < <(find /run/php/ -maxdepth 1 -name "php*-fpm.sock" 2>/dev/null | sort -V)
fi

PHP_SOCK=""
if [ ${#AVAILABLE_SOCKS[@]} -gt 0 ]; then
  echo "Seleziona la versione di PHP da utilizzare:"
  for i in "${!AVAILABLE_SOCKS[@]}"; do
    sock_name=$(basename "${AVAILABLE_SOCKS[$i]}")
    php_ver=$(echo "$sock_name" | grep -oP '\d+\.\d+' || echo "$sock_name")
    echo "  $((i+1))) PHP $php_ver (${AVAILABLE_SOCKS[$i]})"
  done
  echo "  $(( ${#AVAILABLE_SOCKS[@]} + 1 ))) Inserisci manualmente un'altra versione"

  while true; do
    read -r -p "Scelta [1-$(( ${#AVAILABLE_SOCKS[@]} + 1 ))]: " PHP_CHOICE
    if [[ "$PHP_CHOICE" =~ ^[0-9]+$ ]] && [ "$PHP_CHOICE" -ge 1 ] && [ "$PHP_CHOICE" -le "${#AVAILABLE_SOCKS[@]}" ]; then
      PHP_SOCK="${AVAILABLE_SOCKS[$((PHP_CHOICE-1))]}"
      break
    elif [ "$PHP_CHOICE" -eq "$(( ${#AVAILABLE_SOCKS[@]} + 1 ))" ]; then
      read -r -p "Inserisci la versione di PHP desiderata (es. 8.4 o 8.5): " MANUAL_VER
      PHP_SOCK="/run/php/php${MANUAL_VER}-fpm.sock"
      break
    else
      echo "Scelta non valida, riprova."
    fi
  done
else
  echo "[AVVISO] Nessun socket trovato automaticamente in /run/php/."
  read -r -p "Inserisci la versione di PHP desiderata (es. 8.4): " MANUAL_VER
  MANUAL_VER="${MANUAL_VER:-8.4}"
  PHP_SOCK="/run/php/php${MANUAL_VER}-fpm.sock"
fi

echo " -> Socket selezionato: $PHP_SOCK"
echo ""

# 7. Installazione dipendenze di base
echo "==> Installazione pacchetti di utilità (curl, tar, libcap2-bin, openssl, acl)..."
apt-get update -qq
apt-get install -y -qq curl tar libcap2-bin openssl acl >/dev/null

# 8. Generazione chiavi JWT sicure (256-bit / 64 caratteri esadecimali)
PUBLISHER_KEY=$(openssl rand -hex 32)
SUBSCRIBER_KEY=$(openssl rand -hex 32)

# 9. Preparazione directory di sistema
CADDY_DIR="/var/www/caddy"
LANDING_DIR="/var/www/mercure-landing"
CADDY_HOME="/var/lib/caddy"
LOG_FILE="/var/log/caddy.log"

echo "==> Creazione alberatura directory..."
mkdir -p "$CADDY_DIR" "$LANDING_DIR" "$SITE_PUBLIC_DIR" "$CADDY_HOME"
touch "$LOG_FILE"

# 10. Rilevamento architettura e download di Mercure da GitHub Releases
ARCH=$(uname -m)
case "$ARCH" in
  x86_64)  MERCURE_ARCH="Linux_x86_64" ;;
  aarch64) MERCURE_ARCH="Linux_arm64" ;;
  armv7l)  MERCURE_ARCH="Linux_armv7" ;;
  *) echo "[ERRORE] Architettura non supportata: $ARCH" >&2; exit 1 ;;
esac

echo "==> Ricerca e download dell'ultima release di Mercure ($MERCURE_ARCH)..."
DOWNLOAD_URL=$(curl -sSL "https://api.github.com/repos/dunglas/mercure/releases/latest" 2>/dev/null | grep -oE "https://[^\"]*mercure[^\"]*${MERCURE_ARCH}\.tar\.gz" | head -n 1 || true)

if [ -z "$DOWNLOAD_URL" ]; then
  LATEST_TAG=$(curl -sSL "https://api.github.com/repos/dunglas/mercure/releases/latest" 2>/dev/null | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/' || true)
  if [ -n "$LATEST_TAG" ]; then
    DOWNLOAD_URL="https://github.com/dunglas/mercure/releases/download/${LATEST_TAG}/mercure_${MERCURE_ARCH}.tar.gz"
  else
    DOWNLOAD_URL="https://github.com/dunglas/mercure/releases/latest/download/mercure_${MERCURE_ARCH}.tar.gz"
  fi
fi

echo " - Scaricamento da: $DOWNLOAD_URL"
curl -fsSL "$DOWNLOAD_URL" | tar -xz -C "$CADDY_DIR" mercure
chmod +x "$CADDY_DIR/mercure"

# 11. Assegnazione capability per porte 80 e 443 a utente non root
echo "==> Impostazione CAP_NET_BIND_SERVICE sul binario..."
setcap 'cap_net_bind_service=+ep' "$CADDY_DIR/mercure"

# 12. Creazione Landing Page HTML
echo "==> Generazione landing page in $LANDING_DIR/index.html..."
cat << 'EOF' > "$LANDING_DIR/index.html"
<!DOCTYPE html>
<html lang="it">
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
            <span class="dot"></span> Hub Operativo
        </div>
        <h1>Mercure Real-Time</h1>
        <p>Il server di streaming SSE per le applicazioni di Valdi.ovh è attivo e in ascolto.</p>
        <div class="endpoint">/.well-known/mercure</div>
        <div class="footer">Caddy + Mercure Protocol Server</div>
    </div>
</body>
</html>
EOF

# 13. Generazione Caddyfile
echo "==> Scrittura del Caddyfile in $CADDY_DIR/Caddyfile..."

GLOBAL_TLS_DIRECTIVES="email $ADMIN_EMAIL"
SITE_HOSTS="$BASE_DOMAIN, books.$BASE_DOMAIN, anime.$BASE_DOMAIN, hoyoverse.$BASE_DOMAIN, gryphline.$BASE_DOMAIN, videos.$BASE_DOMAIN"
CORS_ORIGINS="https://$BASE_DOMAIN https://books.$BASE_DOMAIN https://anime.$BASE_DOMAIN https://hoyoverse.$BASE_DOMAIN https://gryphline.$BASE_DOMAIN https://videos.$BASE_DOMAIN"

if [ "$IS_LOCAL_DOMAIN" = true ]; then
  echo " -> Rilevato dominio locale/sviluppo ($BASE_DOMAIN): configurata CA interna (local_certs) e fallback SNI"
  GLOBAL_TLS_DIRECTIVES="email $ADMIN_EMAIL
    local_certs
    default_sni $BASE_DOMAIN"
  SITE_HOSTS="$BASE_DOMAIN, localhost, 127.0.0.1, books.$BASE_DOMAIN, anime.$BASE_DOMAIN, hoyoverse.$BASE_DOMAIN, gryphline.$BASE_DOMAIN, videos.$BASE_DOMAIN"
  CORS_ORIGINS="$CORS_ORIGINS https://localhost https://127.0.0.1"
fi

cat << EOF > "$CADDY_DIR/Caddyfile"
{
    $GLOBAL_TLS_DIRECTIVES
}

# 1. Hub Mercure
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

    # Landing page sulla root
    handle / {
        root * $LANDING_DIR
        file_server
    }

    # Direttiva Mercure
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

# 2. Applicazione Symfony e sottodomini
$SITE_HOSTS {
    root * $PROJECT_DIR/public

    encode zstd gzip
    file_server

    php_fastcgi unix/$PHP_SOCK {
        try_files {path} index.php
        env X_SENDFILE_TYPE X-Sendfile
        resolve_root_symlink
    }

    @phpFile {
        path *.php*
    }
    error @phpFile "Not found" 404
}
EOF

# 14. Creazione file systemd
echo "==> Configurazione del servizio systemd (/etc/systemd/system/caddy.service)..."
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

# Environment e JWT Keys
Environment=HOME=$CADDY_HOME
Environment=MERCURE_PUBLISHER_JWT_KEY=$PUBLISHER_KEY
Environment=MERCURE_SUBSCRIBER_JWT_KEY=$SUBSCRIBER_KEY

# Logging
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

# Sicurezza di processo e capabilities
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF

# 15. Configurazione permessi base Caddy e log
echo "==> Configurazione permessi Caddy..."
chown -R www-data:www-data "$CADDY_DIR" "$LANDING_DIR" "$CADDY_HOME" "$LOG_FILE"
chmod -R u=rwX,g=rwX,o=rX "$CADDY_DIR" "$LANDING_DIR"
chmod 700 "$CADDY_HOME"
chmod 644 "$LOG_FILE"

# 16. Configurazione permessi avanzati sul progetto (setgid + ACL)
echo "==> Applicazione permessi ed ereditarietà su $PROJECT_DIR..."
TARGET_OWNER="${REAL_USER:-www-data}"

# Assegna il proprietario all'utente reale (per lavorare comodamente) e gruppo www-data
chown -R "$TARGET_OWNER:www-data" "$PROJECT_DIR"

# Permessi standard lettura/scrittura per utente e gruppo, esecuzione per directory
chmod -R u=rwX,g=rwX,o=rX "$PROJECT_DIR"

# Bit setgid (g+s): ogni nuova directory creata all'interno erediterà il gruppo www-data
find "$PROJECT_DIR" -type d -exec chmod g+s {} +

# POSIX ACL: garantisce che i nuovi file e cartelle creati da Symfony/PHP o dall'utente
# abbiano sempre rwX sia per l'utente che per il gruppo www-data di default
setfacl -R -d -m u::rwx,g::rwx,o::rx "$PROJECT_DIR" 2>/dev/null || true
setfacl -R -m u::rwx,g::rwx,o::rx "$PROJECT_DIR" 2>/dev/null || true

# 17. Attivazione del servizio
echo "==> Ricaricamento systemd e avvio del servizio caddy..."
systemctl daemon-reload
systemctl enable caddy.service
systemctl restart caddy.service

sleep 1
if ! systemctl is-active --quiet caddy.service; then
  echo ""
  echo "[ERRORE] Il servizio Caddy non è riuscito ad avviarsi." >&2
  echo "--- Ultimi log da $LOG_FILE ---" >&2
  tail -n 20 "$LOG_FILE" >&2 || true
  echo "-------------------------------" >&2
  exit 1
fi

# Copia del certificato Root CA di Caddy in posizione pubblica per Windows/browser
if [ -f "$CADDY_HOME/.local/share/caddy/pki/authorities/local/root.crt" ]; then
  cp "$CADDY_HOME/.local/share/caddy/pki/authorities/local/root.crt" "$CADDY_DIR/caddy-root-ca.crt" 2>/dev/null || true
  chmod 644 "$CADDY_DIR/caddy-root-ca.crt" 2>/dev/null || true
fi

# 18. Report finale
echo ""
echo "=========================================================="
echo "          INSTALLAZIONE COMPLETATA CON SUCCESSO!          "
echo "=========================================================="
echo ""
echo "Gruppo 'www-data' associato a: root e $REAL_USER"
echo "Cartella progetto configurata: $PROJECT_DIR"
echo " -> Proprietà:               $TARGET_OWNER:www-data"
echo " -> Ereditarietà gruppo:     Attiva (setgid g+s)"
echo " -> Ereditarietà permessi:   Attiva (POSIX default ACLs)"
echo ""
echo "PHP Socket in uso:          $PHP_SOCK"
echo "MERCURE_PUBLISHER_JWT_KEY:  $PUBLISHER_KEY"
echo "MERCURE_SUBSCRIBER_JWT_KEY: $SUBSCRIBER_KEY"
echo "----------------------------------------------------------"
echo ""
echo "Per configurare Symfony nel tuo file .env / .env.local:"
echo ""
echo "MERCURE_URL=https://mercure.$BASE_DOMAIN/.well-known/mercure"
echo "MERCURE_PUBLIC_URL=https://mercure.$BASE_DOMAIN/.well-known/mercure"
echo "MERCURE_JWT_SECRET=\"$PUBLISHER_KEY\""
echo ""
echo "Stato del servizio: systemctl status caddy"
echo "Log in tempo reale: tail -f $LOG_FILE"

if grep -qi microsoft /proc/version 2>/dev/null; then
  WSL_IP=$(ip -4 addr show eth0 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n 1 || hostname -I | awk '{print $1}')
  echo ""
  echo "=========================================================="
  echo "         [CONFIGURAZIONE RETE WINDOWS / WSL]              "
  echo "=========================================================="
  echo "IP ATTUALE DI WSL: $WSL_IP"
  echo ""
  echo "1. AGGIORNAMENTO REGOLE 'netsh portproxy' IN WINDOWS:"
  echo "   (Se usi il redirect da Windows o Pi-hole verso l'IP del PC):"
  echo "   Apri PowerShell come AMMINISTRATORE ed esegui:"
  echo ""
  echo "   netsh interface portproxy set v4tov4 listenport=80 connectaddress=$WSL_IP connectport=80"
  echo "   netsh interface portproxy set v4tov4 listenport=443 connectaddress=$WSL_IP connectport=443"
  echo ""
  echo "2. MAPPATURA HOSTS IN WINDOWS:"
  echo "   Add-Content -Path C:\\Windows\\System32\\drivers\\etc\\hosts -Value \"\`n127.0.0.1 $BASE_DOMAIN mercure.$BASE_DOMAIN books.$BASE_DOMAIN anime.$BASE_DOMAIN hoyoverse.$BASE_DOMAIN gryphline.$BASE_DOMAIN videos.$BASE_DOMAIN\""
  echo ""
  echo "3. SOLUZIONE DEFINITIVA SENZA PORTPROXY (Windows 11):"
  echo "   Crea o modifica C:\\Users\\$REAL_USER\\.wslconfig con:"
  echo "   [wsl2]"
  echo "   networkingMode=mirrored"
  echo "   Poi riavvia WSL con 'wsl --shutdown'. WSL condividerà direttamente l'IP del PC!"
  echo "=========================================================="
fi
echo ""
echo "Nota: Per usare il nuovo gruppo '$REAL_USER:www-data' nella sessione corrente,"
echo "esegui 'newgrp www-data' oppure effettua un logout/login."
