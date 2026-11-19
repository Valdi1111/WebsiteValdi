#!/usr/bin/env bash
set -euo pipefail

# ==========================================================
#           CONFIGURAZIONE PRINCIPALE (COSTANTI)
# ==========================================================
SHARED_GROUP="condivisi"
DEFAULT_BASE_PATH="/media"
# ==========================================================

# 1. Controllo privilegi di root
if [ "$EUID" -ne 0 ]; then
  echo "[ERRORE] Esegui questo script con sudo o come root." >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-$USER}"

echo "=========================================================="
echo "       Configurazione Cartella Condivisa ($SHARED_GROUP)       "
echo "=========================================================="

# 2. Acquisizione percorso cartella (da argomento CLI o interattivo)
TARGET_DIR="${1:-}"
if [ -z "$TARGET_DIR" ]; then
  read -r -p "Inserisci il percorso della cartella da condividere (es. $DEFAULT_BASE_PATH/xyz): " INPUT_DIR
  TARGET_DIR="${INPUT_DIR:-}"
fi

if [ -z "$TARGET_DIR" ]; then
  echo "[ERRORE] Nessun percorso specificato. Operazione annullata." >&2
  exit 1
fi

# Rimuove eventuale slash finale (es. /media/xyz/ -> /media/xyz)
TARGET_DIR="${TARGET_DIR%/}"

if [ ! -d "$TARGET_DIR" ]; then
  read -r -p "La cartella '$TARGET_DIR' non esiste. Vuoi crearla ora? [S/n]: " CREATE_DIR
  CREATE_DIR="${CREATE_DIR:-S}"
  if [[ "$CREATE_DIR" =~ ^[SsYy]$ ]]; then
    mkdir -p "$TARGET_DIR"
    echo " - Creata directory: $TARGET_DIR"
  else
    echo "[ERRORE] Directory inesistente. Operazione annullata." >&2
    exit 1
  fi
fi

# 3. Installazione pacchetto acl se mancante
if ! command -v setfacl >/dev/null 2>&1; then
  echo "==> Installazione pacchetto 'acl'..."
  apt-get update -qq
  apt-get install -y -qq acl >/dev/null
fi

# 4. Creazione gruppo 'condivisi' e associazione utenti
echo ""
echo "==> Configurazione gruppo '$SHARED_GROUP' e utenti..."
if ! getent group "$SHARED_GROUP" >/dev/null 2>&1; then
  groupadd "$SHARED_GROUP"
  echo " - Creato gruppo di sistema: $SHARED_GROUP"
else
  echo " - Gruppo '$SHARED_GROUP' già esistente"
fi

# Lista utenti da associare (evita duplicati se REAL_USER è root)
USERS_TO_ADD=("root" "www-data" "plex")
if [ -n "$REAL_USER" ] && [ "$REAL_USER" != "root" ]; then
  USERS_TO_ADD+=("$REAL_USER")
fi

for usr in "${USERS_TO_ADD[@]}"; do
  if id -u "$usr" >/dev/null 2>&1; then
    usermod -aG "$SHARED_GROUP" "$usr"
    echo " - Aggiunto utente '$usr' al gruppo '$SHARED_GROUP'"
  else
    echo " - Utente '$usr' non presente sul sistema (saltato)"
  fi
done

# 5. Applicazione proprietà, permessi e POSIX ACL
echo ""
echo "==> Applicazione permessi ed ereditarietà su $TARGET_DIR..."
TARGET_OWNER="${REAL_USER:-root}"

# Proprietari: utente reale + gruppo condivisi
chown -R "$TARGET_OWNER:$SHARED_GROUP" "$TARGET_DIR"

# Imposta rw-rw-r-- sui file normali, ma PRESERVA (+x) su directory e file già eseguibili (.sh)
chmod -R u=rwX,g=rwX,o=rX "$TARGET_DIR"

# Attiva il bit setgid (2775) su tutte le directory affinché ereditino il gruppo condivisi
find "$TARGET_DIR" -type d -exec chmod g+s {} +

# Assicura che eventuali file .sh presenti siano sempre eseguibili da proprietario e gruppo
find "$TARGET_DIR" -type f -name "*.sh" -exec chmod ug+x {} +

# POSIX ACL di default: nuovi file/cartelle creati da PHP/Symfony, Plex o Samba
# manterranno sempre lettura/scrittura per proprietario e gruppo condivisi
setfacl -R -d -m u::rwx,g::rwx,o::rx "$TARGET_DIR" 2>/dev/null || true
setfacl -R -m u::rwX,g::rwX,o::rX "$TARGET_DIR" 2>/dev/null || true

# Ricarica servizi che usano www-data / plex per applicare subito la nuova appartenenza al gruppo
for svc in php*-fpm plexmediaserver; do
  if systemctl is-active --quiet "$svc" 2>/dev/null; then
    systemctl restart "$svc" 2>/dev/null || true
  fi
done

# 6. Configurazione opzionale Samba (/etc/samba/smb.conf)
SHARE_NAME="$(basename "$TARGET_DIR")"
SAMBA_CONFIGURED=false

if command -v smbd >/dev/null 2>&1 && [ -f /etc/samba/smb.conf ]; then
  echo ""
  read -r -p "Samba rilevato. Vuoi aggiungere/verificare la condivisione [$SHARE_NAME] in /etc/samba/smb.conf? [s/N]: " ADD_SAMBA
  ADD_SAMBA="${ADD_SAMBA:-N}"
  if [[ "$ADD_SAMBA" =~ ^[SsYy]$ ]]; then
    if grep -q "^\[$SHARE_NAME\]" /etc/samba/smb.conf; then
      echo " - [AVVISO] La sezione [$SHARE_NAME] esiste già in /etc/samba/smb.conf. Nessuna modifica effettuata."
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
      echo " - Aggiunta sezione [$SHARE_NAME] a /etc/samba/smb.conf"
      if testparm -s >/dev/null 2>&1; then
        systemctl restart smbd nmbd 2>/dev/null || systemctl restart smbd 2>/dev/null || true
        echo " - Servizio Samba riavviato con successo"
        SAMBA_CONFIGURED=true
      else
        echo " [ERRORE] Errore di sintassi rilevato da testparm in /etc/samba/smb.conf! Controlla il file." >&2
      fi
    fi
  fi
fi

# 7. Report finale
echo ""
echo "=========================================================="
echo "       CARTELLA CONDIVISA CONFIGURATA CON SUCCESSO!       "
echo "=========================================================="
echo "Percorso:                $TARGET_DIR"
echo "Proprietà:               $TARGET_OWNER:$SHARED_GROUP"
echo "Permessi directory/file: 2775 (setgid) / 0664 + POSIX ACL"
echo "Utenti nel gruppo:       $(getent group "$SHARED_GROUP" | cut -d: -f4)"

if [ "$SAMBA_CONFIGURED" = false ]; then
  echo ""
  echo "Blocco di configurazione per Samba (/etc/samba/smb.conf):"
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
echo "Nota: Per usare il nuovo gruppo '$SHARED_GROUP' nella tua sessione terminale corrente,"
echo "esegui 'newgrp $SHARED_GROUP' oppure effettua un logout/login."
