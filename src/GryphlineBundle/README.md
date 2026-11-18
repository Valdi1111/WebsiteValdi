# Gryphline Bundle (`GryphlineBundle`) — Guida alle Funzionalità

Il bundle **`GryphlineBundle`** gestisce l'integrazione completa con la piattaforma **Gryphline / SKPort** per **Arknights: Endfield** (`game_id = 3`, `game_biz = endfield`).

Supporta il login diretto con credenziali Gryphline (con gestione del captcha GeeTest v4) o tramite `ACCOUNT_TOKEN`, la negoziazione automatica delle sessioni OAuth con firma crittografica `HMAC-SHA256` (`vCode`), l'auto check-in giornaliero su SKPort, i reminder per Stamina (Sanity), missioni giornaliere e settimanali, e la sincronizzazione automatica dello storico delle pull (Headhunting).

---

## 1. Panoramica delle Funzionalità (`Arknights: Endfield`)

| Funzionalità | Stato | Dettagli |
| :--- | :---: | :--- |
| **Login con Credenziali (Email + Password)** | ✅ | Autenticazione diretta su `as.gryphline.com` con supporto GeeTest v4 e generazione OAuth immediata |
| **Login con `ACCOUNT_TOKEN`** | ✅ | Inserimento manuale del token (es. estratto dal cookie `ACCOUNT_TOKEN` di SKPort) |
| **Deduplicazione Account & Re-login Sicuro** | ✅ | Basato su `account_uid` (`hgId`), aggiorna l'account esistente ed evita mismatch in Re-login |
| **SKPort Auto Check-In & Missed Check-In** | ✅ | Riscatto automatico delle ricompense giornaliere SKPort e promemoria pomeridiano |
| **Stamina Reminder** | ✅ | Monitoraggio continuo (ogni 30 min) con soglia personalizzabile (cap massimo: `360`) |
| **Dailies Reminder** | ✅ | Controllo alle 21:00 (ora locale del server) per *Daily Activation* (`100/100`) |
| **Weeklies Reminder** | ✅ | Controllo ogni Domenica alle 21:00 (ora locale del server) per *Weekly Mission* |
| **Sincronizzazione Pulls Automatica** | ✅ | Importazione 1-click e notturna tramite token OAuth `u8_token` (Character & Weapon Headhunting) |

### 1.1 Architettura dei Game Service: Interfacce di Capacità, Trait e Normalizzazione Dinamica

Ogni gioco nel bundle (attualmente `EndfieldService`, registrato nel Service Locator tramite i tag `gryphline.game.id` e `gryphline.game.biz`) implementa `App\GryphlineBundle\Model\Game\GameInterface` e dichiara le proprie capacità tramite **Interfacce di Capacità** (condivise con `CoreBundle` o specifiche di `GryphlineBundle`).  
Sia i **MessageHandler** sia `GryphlineGameProfileNormalizer` controllano con `instanceof` quali interfacce sono implementate dal Game Service per abilitare i job schedulati ed esporre dinamicamente al frontend solo le impostazioni effettivamente supportate dal gioco:

| Interfaccia | Namespace | Trait Associato | Campi / Flag esposti dal Normalizer | Cosa indica / Funzionalità abilitata |
| :--- | :--- | :--- | :--- | :--- |
| `GameInterface` | `GryphlineBundle` | — | — | Contratto base di un servizio di gioco Gryphline/SKPort (`getGameId()`, `getGameBiz()`, `getGameName()`, `getIcon()`, `ensureCredentials()`). |
| `HasCheckInInterface` | `GryphlineBundle` | `CheckInTrait` | `skportCheckIn`, `skportMissedCheckIn` | Il gioco supporta il check-in giornaliero firmato su SKPort (`/web/v1/game/{biz}/attendance`), abilitando **Auto Check-In** e **Missed Check-In Reminder**. |
| `HasNotesInterface` | `CoreBundle` | `NotesTrait` | — | Il gioco espone l'endpoint *Real-Time Card Detail* (`/api/v1/game/{biz}/card/detail`) su SKPort per leggere i dati live del profilo (`EndfieldNotes`). |
| `HasStaminaInterface` | `CoreBundle` | `NotesTrait` | `staminaCheck`, `staminaThreshold` | Il gioco permette di monitorare la Stamina/Sanity in tempo reale e il timestamp di recupero completo, abilitando lo **Stamina Reminder**. |
| `HasDailiesInterface` | `CoreBundle` | `NotesTrait` | `dailiesCheck` | Il gioco espone il progresso giornaliero (*Daily Activation* `100/100`), abilitando il **Dailies Reminder** regionale. |
| `HasWeekliesInterface` | `CoreBundle` | `NotesTrait` | `weekliesCheck` | Il gioco espone il progresso settimanale (*Weekly Mission*), abilitando il **Weeklies Reminder** regionale. |
| `HasAutoPullImportInterface` | `GryphlineBundle` | `GryphlinePullTrait` | `syncPulls`, `supports_pulls: true` | Il gioco supporta l'importazione 100% automatica dello storico Gacha/Headhunting tramite token OAuth `u8_token`, abilitando la pagina **Pulls Tracker**, lo switch **Auto Sync Pulls** e il pulsante **Sync Pulls**. |

---

## 2. Autenticazione: Login con Credenziali, Token e Flusso OAuth SKPort

Ogni account Gryphline (`GryphlineAccount`) è associato a un utente della piattaforma e identificato univocamente tramite la colonna indicizzata `account_uid` (che memorizza l'`hgId` ufficiale di Gryphline).
- **In modalità Add Account**: se l'utente effettua il login con un account Gryphline il cui `account_uid` (`hgId`) è già registrato per il proprio profilo, il sistema aggiorna automaticamente il token e le credenziali dell'account esistente senza creare duplicati.
- **In modalità Re-login**: se l'utente effettua il re-login su un account esistente, il sistema verifica che l'`hgId` corrisponda a `account_uid` (se presente), restituendo un errore `422 Unprocessable Entity` in caso di account differente.

### 2.1 Login Diretto con Credenziali (Email + Password)
Il servizio `GryphlineAuthService` comunica con l'Authentication Server di Gryphline (`https://as.gryphline.com/user/auth/v1/token_by_email_password`):
1. **Identificazione Device**:
   - Invia gli header dispositivo `X-DeviceId` (32 caratteri esadecimali), `X-DeviceName: Gryphline Integration`, `X-DeviceModel: WebsiteValdi`, `X-DeviceType: 7`, `X-OSVer: Linux`.
2. **Gestione Captcha GeeTest v4 (`status: 1`)**:
   - Se Gryphline richiede una verifica di sicurezza (es. per IP reputation o dopo tentativi errati), restituisce `status: 1` con `data.captcha.geetestId`, `riskType` e un token server `challenge`.
   - Il backend cifra la password in `AuthSessionStore` (`AES-256-GCM`, TTL 10 minuti) e mostra nel frontend il widget **GeeTest v4** tramite il componente condiviso `GeetestCaptchaFrame`.
   - Una volta risolto il puzzle, `submitCaptcha()` unisce i 5 parametri GeeTest v4 (`captcha_id`, `lot_number`, `pass_token`, `gen_time`, `captcha_output`) con il `challenge` del server e completa il login.
3. **Finalizzazione Immediata**:
   - Ottenuto l'`accountToken` e l'`hgId` (`status: 0`), `finalizeAuthenticatedAccount()` esegue subito il flusso OAuth SKPort per validare il token e sincronizza in automatico i profili di gioco (`GryphlineGameProfile`).

### 2.2 Login tramite Token Manuale ("Paste Token")
Dalla tab **Paste Token** della modale di aggiunta/re-login, è possibile incollare manualmente il valore di `ACCOUNT_TOKEN` recuperato dai cookie del browser su `skport.com`.

### 2.3 Flusso OAuth a 3 Step e Firma Crittografica `SkportSignerService`
Per interagire con le API di `zonai.skport.com`, l'`accountToken` a lungo termine viene scambiato da `SkportAuthService::performOAuthFlow()` in 3 passaggi:
1. **Verifica Info Utente**: `GET https://as.gryphline.com/user/info/v1/basic?token=...` → recupera e aggiorna `hgId` (`account_uid`) e `nickname`.
2. **OAuth Grant Code**: `POST https://as.gryphline.com/user/oauth2/v2/grant` (`appCode: 6eb76d4e13aa36e6`, `type: 0`) → ottiene un codice di autorizzazione OAuth.
3. **Session Credentials**: `POST https://zonai.skport.com/web/v1/user/auth/generate_cred_by_code` → ottiene:
   - `cred` (`session_cred`): token di sessione SKPort (TTL 45 minuti, salvato su DB e rinnovato automaticamente alla scadenza).
   - `token` (`session_salt`): chiave segreta utilizzata da `SkportSignerService` per calcolare l'header `sign` (`MD5(HMAC-SHA256(path + query/body + timestamp + headersJson, salt))`) richiesto da tutte le chiamate autenticate a `zonai.skport.com`.

---

## 3. Auto Check-In Giornaliero su SKPort

Il sistema esegue due task schedulati per il check-in giornaliero di *Arknights: Endfield* (`/web/v1/game/endfield/attendance`):

1. **Auto Check-In (`SkportCheckInBatchMessage`)** — Schedule: `0 20 * * *`
   - Per ogni profilo attivo con `skportCheckIn = true`, verifica tramite `GET /web/v1/game/endfield/attendance` se l'utente ha già effettuato l'accesso oggi (`hasToday`).
   - Se non ancora effettuato, invia una richiesta firmata `POST /web/v1/game/endfield/attendance` con l'header `sk-game-role: 3_{gameUid}_{serverId}`.
   - Estrae le ricompense ottenute (`awardIds` → `resourceInfoMap`) e invia una notifica all'utente con il nome, la quantità e l'icona del premio riscattato.
2. **Missed Check-In Reminder (`SkportMissedCheckInBatchMessage`)** — Schedule: `0 14 * * *`
   - Per i profili con `skportMissedCheckIn = true`, controlla alle 14:00 se il check-in giornaliero risulta ancora non effettuato e invia una notifica di promemoria.

---

## 4. Reminders e Monitoraggio Real-Time (Game Notes)

Tramite l'endpoint firmato `GET /api/v1/game/endfield/card/detail`, `EndfieldService` recupera i dati in tempo reale del profilo (`EndfieldNotes`) per alimentare i reminder automatici:

### 4.1 Stamina Reminder (`StaminaCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (ogni 30 minuti).
- **Parametri**:
  - Cap massimo stamina: `360` (tasso di rigenerazione: 1 punto ogni 360 secondi / 6 minuti).
  - Configurabile per profilo tramite il toggle `staminaCheck` e la soglia `staminaThreshold` (`-1` = avvisa solo al raggiungimento di `360/360`).
- **Logica Anti-Spam**: memorizza in cache l'ultimo valore notificato fino al timestamp di pieno recupero (`staminaRecoveryTime`), resettando il lock non appena il giocatore spende stamina in-game.

### 4.2 Dailies & Weeklies Reminders (`DailiesReminderBatchMessage` / `WeekliesReminderBatchMessage`)
I task regionali vengono eseguiti in base al fuso orario del server associato al profilo (`US_EU` → `Europe/Paris`, `SEA` → `Asia/Shanghai`):
- **Dailies Reminder** — Schedule: `0 21 * * *` (ore 21:00 ora locale del server)
  - Verifica la metrica **Daily Activation** (`curDailyActivation / maxDailyActivation`, target `100/100`). Se incompleta, invia una notifica di promemoria.
- **Weeklies Reminder** — Schedule: `0 21 * * 0` (ogni Domenica alle ore 21:00 ora locale del server)
  - Verifica la metrica **Weekly Mission** (`weeklyScore / weeklyTotal`). Se incompleta, invia una notifica prima del reset settimanale.

---

## 5. Sincronizzazione Automatica delle Pulls (Headhunting Tracker)

A differenza di altri giochi che richiedono l'estrazione manuale di URL dai file di log locali su PC, **Arknights: Endfield** su `GryphlineBundle` supporta la **sincronizzazione 100% automatica** dello storico gacha direttamente tramite l'`accountToken` memorizzato, salvando i dati nella tabella `gryphline_pull` (`GryphlinePull`).

### 5.1 Flusso di Autenticazione Webview (`u8_token`)
Quando viene avviata una sincronizzazione delle pull, `SkportAuthService::getEndfieldU8Token()` esegue una catena OAuth dedicata:
1. Richiede un OAuth Grant Token specifico per Endfield (`POST /user/oauth2/v2/grant` con `appCode: 3dacefa138426cfe` e `type: 1`).
2. Interroga `GET https://binding-api-account-prod.gryphline.com/account/binding/v1/binding_list` per individuare il `binding.uid` corrispondente al `roleId` (`gameUid`) del profilo.
3. Scambia il `binding.uid` su `POST /account/binding/v1/u8_token_by_uid` ottenendo un **`u8_token`** temporaneo valido per le API webview dei record di gioco.

### 5.2 Banner Supportati, Paginazione e Coda Asincrona (`gryphline_pulls`)
Utilizzando l'`u8_token`, il worker asincrono `SyncPullsPageProfileHandler` (sul canale Messenger dedicato **`gryphline_pulls`**) scarica i record paginati tramite `seq_id` dagli endpoint `https://ef-webview.gryphline.com/api/record/char` e `/api/record/weapon`, denormalizzandoli tramite il DTO `GryphlinePullItem` e mappandoli su `GryphlinePull` con `Symfony\Component\ObjectMapper`:
- **Chartered Headhunting** (`E_CharacterGachaPoolType_Special`) — Hard Pity: `80` (`6★`), `10` (`5★`).
- **Basic Headhunting** (`E_CharacterGachaPoolType_Standard`) — Hard Pity: `80` (`6★`), `10` (`5★`).
- **Weapon Headhunting** (`E_WeaponGachaPoolType_Standard`) — Hard Pity: `40` (`6★`), `10` (`5★`).
- **Beginner Headhunting** (`E_CharacterGachaPoolType_Beginner`) — Hard Pity: `50` (`6★`), `10` (`5★`).

Caratteristiche dell'importazione:
- **Idempotenza e Vincoli Database (`gryphline_pull`)**: vincolo di unicità `IDX_gryphline_game_profile_id_pool_category_seq_id` su `(gryphline_game_profile_id, pool_category, seq_id)`. La paginazione a ritroso si ferma non appena incontra un `seq_id` già presente a database.
- **Paginazione con Jitter Anti-Rate-Limit**: tra una pagina e la successiva, `SyncPullsPageProfileHandler` ri-accoda `SyncPullsPageProfileMessage` applicando un `DelayStamp` con jitter casuale (`1500ms – 2500ms`).
- **Filtro Bonus Non-Pull**: esclude automaticamente dal calcolo del pity le voci di log bonus che non sono estrazioni reali (es. `kind = "gift_intel_book"` / *Headhunting Dossier*).
- **Gestione Pool Vuote (`code: 50000`)**: se un banner restituisce il codice `50000` (*"save seq id unavailable"*), viene gestito silenziosamente come storico vuoto senza generare errori.
- **Modalità di Esecuzione**:
  - **Automatica notturna (`SyncPullsBatchMessage`)**: eseguita ogni notte alle `01:00` (`0 1 * * *`) per tutti i profili con lo switch `syncPulls = true` attivo nel `GameProfileSettingsDrawer`.
  - **Manuale da UI**: tramite il pulsante **Sync Pulls** nella vista dello storico (`PullsPage`).

---

## 6. Riepilogo Endpoint API del Bundle

| Metodo | Percorso | Descrizione |
| :--- | :--- | :--- |
| `POST` | `/api/accounts/auth/login/start` | Avvia il login diretto con Email + Password su `as.gryphline.com` |
| `POST` | `/api/accounts/{id}/relogin/start` | Avvia il re-login su un `GryphlineAccount` esistente (valida corrispondenza `account_uid` / `hgId`) |
| `POST` | `/api/accounts/auth/login/captcha` | Invia la soluzione del captcha GeeTest v4 insieme al `challenge` di sessione |
| `GET` | `/api/accounts/auth/geetest/frame` | Renderizza l'iframe isolato per il widget GeeTest v4 |
| `GET` | `/api/accounts` / `POST /api/accounts` | Lista account o aggiunge/aggiorna un account tramite `ACCOUNT_TOKEN` manuale |
| `POST` | `/api/accounts/{id}/sync` | Sincronizza i profili di gioco (`GryphlineGameProfile`) tramite SKPort |
| `POST` | `/api/game-profiles/{id}/pulls/sync` | Accoda la sincronizzazione automatica delle pull tramite `u8_token` |

