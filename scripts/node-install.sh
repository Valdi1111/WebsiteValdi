#!/usr/bin/env bash
set -euo pipefail

# Verifica privilegi di root
if [ "$EUID" -ne 0 ]; then
  echo "[ERRORE] Esegui questo script con privilegi di root (es. sudo bash $0)" >&2
  exit 1
fi

NODE_USER="node"
NODE_GROUP="node"
NODE_HOME="/opt/node"
TARGET_BIN_DIR="$NODE_HOME/20-lts"
REAL_USER="${SUDO_USER:-$USER}"

# Eseguibili base del runtime Node
ESSENTIAL_BINS=("node" "npm" "npx" "corepack")

echo "==> 1. Installazione dipendenze di sistema..."
apt-get update -qq
apt-get install -y -qq curl tar acl >/dev/null

echo "==> 2. Creazione utente e gruppo di sistema '$NODE_USER'..."
if ! getent group "$NODE_GROUP" >/dev/null; then
  groupadd -r "$NODE_GROUP"
fi

if ! id -u "$NODE_USER" >/dev/null 2>&1; then
  useradd -r -m -d "$NODE_HOME" -s /bin/bash -g "$NODE_GROUP" "$NODE_USER"
else
  usermod -d "$NODE_HOME" -m "$NODE_USER" 2>/dev/null || true
  mkdir -p "$NODE_HOME"
fi

echo "==> 3. Associazione del gruppo '$NODE_GROUP' agli utenti..."
if [ -n "$REAL_USER" ] && id -u "$REAL_USER" >/dev/null 2>&1; then
  usermod -aG "$NODE_GROUP" "$REAL_USER"
  echo " - Aggiunto a $REAL_USER"
fi

usermod -aG "$NODE_GROUP" root
echo " - Aggiunto a root"

if id -u www-data >/dev/null 2>&1; then
  usermod -aG "$NODE_GROUP" www-data
  echo " - Aggiunto a www-data"
fi

echo "==> 4. Installazione NVM e Node 20 LTS..."
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

echo "==> 5. Rilevamento path binari Node..."
NODE_BIN_DIR=$(sudo -u "$NODE_USER" -H bash -c '
  export HOME="/opt/node"
  export NVM_DIR="$HOME/.nvm"
  [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
  nvm which default | sed "s/\/node$//"
')

if [ -z "$NODE_BIN_DIR" ] || [ ! -d "$NODE_BIN_DIR" ]; then
  echo "[ERRORE] Impossibile trovare la cartella bin di Node in NVM." >&2
  exit 1
fi
echo " - Sorgente binari: $NODE_BIN_DIR"

echo ""
read -r -p "Vuoi installare PM2 globalmente tramite l'utente node? [s/N] " install_pm2_resp
case "$install_pm2_resp" in
  [sS][iI]|[sS]|[yY][eE][sS]|[yY])
    echo "==> Installazione PM2 in corso..."
    sudo -u "$NODE_USER" -H bash -c '
      export HOME="/opt/node"
      export NVM_DIR="$HOME/.nvm"
      [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
      npm install -g pm2
    '
    ESSENTIAL_BINS+=("pm2")
    echo " - PM2 installato con successo."
    ;;
  *)
    echo " - Installazione PM2 saltata."
    ;;
esac

echo ""
echo "==> 6. Creazione collegamenti in $TARGET_BIN_DIR..."
mkdir -p "$TARGET_BIN_DIR"

for bin in "${ESSENTIAL_BINS[@]}"; do
  src_path="$NODE_BIN_DIR/$bin"
  if [ -x "$src_path" ]; then
    ln -sfn "$src_path" "$TARGET_BIN_DIR/$bin"
    echo " - Collegato: $bin -> $TARGET_BIN_DIR/$bin"
  fi
done

echo "==> 7. Configurazione permessi, setgid e Default ACL su $NODE_HOME..."
# Proprietà ricorsiva all'utente e gruppo node
chown -R "$NODE_USER:$NODE_GROUP" "$NODE_HOME"

# Permessi standard Linux
chmod -R u=rwX,g=rwX,o=rX "$NODE_HOME"

# Setgid (g+s) su tutte le cartelle per forzare il gruppo proprietario
find "$NODE_HOME" -type d -exec chmod g+s {} +

# POSIX ACL: applica rwX a elementi esistenti e imposta le regole di default per i nuovi file/cartelle
setfacl -R -m u::rwx,g::rwx,o::rx "$NODE_HOME"
setfacl -R -d -m u::rwx,g::rwx,o::rx "$NODE_HOME"

echo ""
echo "==> Configurazione completata in $TARGET_BIN_DIR."
echo ""

# Richiesta interattiva per /usr/local/bin
read -r -p "Vuoi creare i collegamenti simbolici anche in /usr/local/bin per renderli globali nel PATH? [s/N] " response
case "$response" in
  [sS][iI]|[sS]|[yY][eE][sS]|[yY])
    echo "==> Creazione collegamenti in /usr/local/bin..."
    for bin in "${ESSENTIAL_BINS[@]}"; do
      if [ -e "$TARGET_BIN_DIR/$bin" ]; then
        ln -sfn "$TARGET_BIN_DIR/$bin" "/usr/local/bin/$bin"
        echo " - Collegato: /usr/local/bin/$bin -> $TARGET_BIN_DIR/$bin"
      fi
    done
    echo "I comandi sono ora disponibili ovunque senza dover modificare il PATH."
    ;;
  *)
    echo "Saltata creazione in /usr/local/bin."
    echo "Per usare i comandi punta direttamente a '$TARGET_BIN_DIR' o aggiungilo al tuo PATH."
    ;;
esac

echo ""
echo "Configurazione terminata. Ricorda di eseguire 'newgrp $NODE_GROUP' per aggiornare la sessione corrente."