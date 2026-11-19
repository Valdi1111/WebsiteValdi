# HoYoverse Bundle (`HoyoverseBundle`) — Guida alle Funzionalità

Il bundle **`HoyoverseBundle`** gestisce l'integrazione completa con l'ecosistema **HoYoLAB / HoYoverse** per **Genshin Impact**, **Honkai: Star Rail**, **Zenless Zone Zero**, **Honkai Impact 3rd** e **Tears of Themis**.

Supporta autenticazione diretta o tramite cookie, rinnovo automatico delle sessioni, check-in giornalieri, riscatto automatico dei codici promozionali, monitoraggio in tempo reale delle risorse in-game (Resin/Trailblaze Power/Battery, spedizioni, Realm Currency, negozi, endgame), sincronizzazione del **Diary** (entrate mensili di valute premium) e **Pulls Tracker** (automatico via `stoken_v2` o tramite script PowerShell).

---

## 1. Giochi Supportati e Matrice delle Funzionalità

| Funzionalità | Genshin Impact (`hk4e_global`) | Honkai: Star Rail (`hkrpg_global`) | Zenless Zone Zero (`nap_global`) | Honkai Impact 3rd (`bh3_global`) | Tears of Themis (`nxx_global`) |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Auto Check-In & Missed Check-In** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Codici Promo (Auto Redeem API)** | ✅ (Automatico) | ✅ (Automatico) | ✅ (Automatico) | 🔔 (Notifica manuale) | 🔔 (Notifica manuale) |
| **Stamina Reminder** | ✅ (Original Resin, max 200) | ✅ (Trailblaze Power, max 300) | ✅ (Battery Charge, max 300) | ❌ | ❌ |
| **Dailies Reminder** | ✅ (Daily Tasks 4/4) | ✅ (Activity Points 500/500) | ✅ (Engagement, Scratch Card, Cafe) | ❌ | ❌ |
| **Weeklies Reminder** | ✅ (Resin Discounts 3/3) | ✅ (Echo of War 3/3, Sim. Universe) | ✅ (Bounty Commissions, Ridu Points) | ❌ | ❌ |
| **Expeditions Reminder** | ✅ | ✅ (Assignments) | ❌ | ❌ | ❌ |
| **Realm Currency Reminder** | ✅ (Serenitea Pot) | ❌ | ❌ | ❌ | ❌ |
| **Shop Status Reminder** | ❌ | ❌ | ✅ (Video Store / VHS) | ❌ | ❌ |
| **Endgames Reminder** | ✅ (Spiral Abyss, Imaginarium Theater, Stygian Onslaught) | ✅ (Memory of Chaos, Pure Fiction, Apocalyptic Shadow, Anomaly Arbitration) | ✅ (Shiyu Defense, Deadly Assault) | ❌ | ❌ |
| **Diary Sync (Valute Mensili)** | ✅ (Primogems, Mora) | ✅ (Stellar Jades, Passes) | ✅ (Polychromes, Tapes) | ❌ | ❌ |
| **Pulls Sync Automatico (`stoken_v2`)** | ✅ | ❌ *(Non supportato da HoYoverse)* | ✅ | ❌ | ❌ |
| **Pulls Sync tramite PowerShell** | ✅ | ✅ | ✅ | ❌ | ❌ |

### 1.1 Architettura dei Game Service: Interfacce di Capacità, Trait e Normalizzazione Dinamica

Ogni gioco è implementato come un servizio che estende `App\HoyoverseBundle\Model\Game\GameService` (registrato nel Service Locator tramite i tag `hoyoverse.game.id` e `hoyoverse.game.biz`).  
Le funzionalità supportate da ciascun gioco sono dichiarate tramite **Interfacce di Capacità** (capability interfaces): sia i **MessageHandler** (in background) sia `HoyoverseGameProfileNormalizer` (verso il frontend React) ispezionano con `instanceof` quali interfacce implementa il Game Service per abilitare/nascondere automaticamente i task e le opzioni nel `GameProfileSettingsDrawer`.

#### Interfacce Condivise (`App\CoreBundle\Model\Game\*`)
| Interfaccia | Trait Associato | Campi esposti dal Normalizer | Cosa indica / Funzionalità abilitata |
| :--- | :--- | :--- | :--- |
| `HasNotesInterface` | `NotesTrait` | — | Il gioco espone un endpoint *Real-Time Notes* (`dailyNote` / `note`) su HoYoLAB per leggere lo stato in tempo reale del profilo. |
| `HasStaminaInterface` | `NotesTrait` | `staminaCheck`, `staminaThreshold` | Il gioco permette di leggere la Stamina attuale/massima e il tempo di recupero, abilitando lo **Stamina Reminder**. |
| `HasDailiesInterface` | `NotesTrait` | `dailiesCheck` | Il gioco espone lo stato delle attività giornaliere (es. Commissioni, Activity Points, Engagement/Scratch Card/Cafe), abilitando il **Dailies Reminder**. |
| `HasWeekliesInterface` | `NotesTrait` | `weekliesCheck` | Il gioco espone lo stato delle attività settimanali (es. Resin Discounts, Echo of War/Sim. Universe, Bounty/Ridu Points), abilitando il **Weeklies Reminder**. |
| `HasEndgamesInterface` | `GenshinImpactEndgamesTrait`, `HonkaiStarRailEndgamesTrait`, `ZenlessZoneZeroEndgamesTrait` | `endgamesCheck` *(gestito via trait)* | Il gioco espone gli endpoint di stato e rotazione delle modalità Endgame competitive, abilitando l'**Endgames Reminder**. |

#### Interfacce Specifiche HoYoverse (`App\HoyoverseBundle\Model\Game\*`)
| Interfaccia | Trait Associato | Campi / Flag esposti dal Normalizer | Cosa indica / Funzionalità abilitata |
| :--- | :--- | :--- | :--- |
| `HasHoyolabCheckInInterface` | `HoyolabCheckInTrait` *(in `GameService`)* | `hoyolabCheckIn`, `hoyolabMissedCheckIn` | Il gioco supporta il check-in giornaliero HoYoLAB (`info`, `home`, `sign`), abilitando **Auto Check-In** e **Missed Check-In Reminder**. |
| `HasCodeRedemptionInterface` | `CodeRedemptionTrait` | `codeRedeem` | Il gioco dispone di una sorgente pubblica di codici promozionali (`api.ennead.cc`). Se implementata da sola (*HI3*, *ToT*), invia notifiche per il **riscatto manuale** in-game. |
| `HasAutoCodeRedemptionInterface` | `AutoCodeRedemptionTrait` | `codeRedeem` | Estende `HasCodeRedemptionInterface`: il gioco espone l'endpoint `webExchangeCdkeyRisk` (*GI*, *HSR*, *ZZZ*), abilitando il **riscatto automatico via API**. |
| `HasExpeditionsInterface` | `NotesTrait` | `expeditionsCheck` | Il gioco include spedizioni/assignments nelle Real-Time Notes (*GI*, *HSR*), abilitando l'**Expeditions Reminder**. |
| `HasRealmCurrencyInterface` | `NotesTrait` | `realmCurrencyCheck`, `realmCurrencyThreshold` | Il gioco include la valuta della Serenitea Pot nelle Real-Time Notes (*GI*), abilitando il **Realm Currency Reminder**. |
| `HasShopStatusInterface` | `NotesTrait` | `shopStatusCheck` | Il gioco include lo stato di gestione del Video Store / VHS nelle Real-Time Notes (*ZZZ*), abilitando lo **Shop Status Reminder**. |
| `HasDiaryInterface` | `DiaryTrait` | `syncDiary` | Il gioco espone gli endpoint mensili `month_info` e `month_detail` (*GI*, *HSR*, *ZZZ*), abilitando la pagina **Diary** e la sincronizzazione mensile delle valute. |
| `HasPullTrackerInterface` | `HoyoversePullTrait` | `supports_pulls: true` | Il gioco supporta il tracciamento dei banner Gacha (`getGachaConfig()`, `getRarityConfig()`, `fetchPullsPage()`), abilitando la pagina **Pulls Tracker**. |
| `HasStokenPullImportInterface` | `HoyoversePullTrait` | `syncPulls`, `supports_stoken_pulls: true` | Estende `HasPullTrackerInterface`: il gioco permette di generare l'`authkey` gacha direttamente da `stoken_v2` via Takumi API (*GI*, *ZZZ*), abilitando lo switch **Auto Sync Pulls** e il pulsante **Sync Pulls** in 1-click. |
| `HasPowershellPullImportInterface` | `HoyoversePullTrait` | `supports_powershell_pulls: true` | Estende `HasPullTrackerInterface`: il gioco definisce una `PowershellPullConfig` (*GI*, *HSR*, *ZZZ*), abilitando il pulsante **Import via PowerShell** per estrarre l'URL Gacha dalla cache webview locale di Windows. |
| `HasMimoInterface` | `MimoTrait` | `mimoCheck`, `mimoRedeem`, `mimoRedeemDraw`, `mimoLottery`, `mimoReservePoints` | Predisposizione per l'automazione degli eventi web *Traveling Mimo* su HoYoLAB. |
| `HasHilichurlInterface` | — | `hilichurlCheck`, `hilichurlRedeem` | Predisposizione per l'automazione degli eventi web *Hilichurl's Treasure Trove*. |

---

## 2. Autenticazione: Login con Credenziali, Cookie e Auto-Refresh

Ogni account HoYoverse (`HoyoverseAccount`) è associato a un utente della piattaforma e identificato univocamente tramite la colonna indicizzata `account_uid` (`ltuid_v2` / `aid`), prevenendo duplicazioni quando si aggiunge un account già presente e bloccando sovrascritture accidentali con account diversi in fase di **Re-login**.

### 2.1 Login Diretto con Credenziali (Email / Username + Password)
Il flusso di login diretto (`HoyoverseAuthService`) replica il protocollo mobile ufficiale HoYoLAB (`hk4e-sdk-os.hoyoverse.com`):
1. **Crittografia RSA-2048**: `account` e `password` vengono cifrati separatamente tramite chiave pubblica RSA OS (`LOGIN_KEY_TYPE_1`) prima di essere inviati a `/account/ma-passport/api/appLoginByPassword`.
2. **Firma `DS` v2 e Identificazione Device**:
   - Ogni richiesta è firmata con l'header `DS` (`t,r,md5(salt=IZPgfb0dRPtBeLuFkdDznSZ6f4wWt6y2&t=...&r=...&b=<json>&q=)`).
   - Viene generato (e persistito sull'entità `HoyoverseAccount::deviceId`) un `x-rpc-device_id` esadecimale a 16 caratteri, accompagnato da `x-rpc-device_name: Hoyoverse Integration` e `x-rpc-device_model: WebsiteValdi` (visibile nella sezione *Trusted Devices* dell'Account Center HoYoverse).
3. **Gestione Captcha GeeTest Aigis (`retcode: -3101`)**:
   - Se HoYoverse richiede una verifica anti-bot, il backend estrae l'header `x-rpc-aigis`, salva la sessione temporanea cifrata (`AES-256-GCM` tramite `AuthSessionStore`, TTL 10 minuti) e restituisce la challenge al frontend.
   - Il componente React `GeetestCaptchaFrame` renderizza il widget GeeTest v3 o v4 in un iframe isolato usando gli script SDK serviti dal proxy locale `GeetestService`.
4. **Verifica Email OTP per Nuovo Device (`retcode: -3239`)**:
   - Se il `device_id` non è ancora registrato come trusted device su HoYoverse, il server richiede un codice OTP via email (`createEmailCaptchaByActionTicket` → `verifyActionTicketPartly`).
   - Una volta verificato il codice OTP a 6 cifre, il `device_id` diventa *trusted* e i futuri re-login sullo stesso account riutilizzeranno lo stesso `device_id` senza richiedere nuovamente l'OTP.
5. **Scambio Token e Sincronizzazione Profili**:
   - Ottenuto `stoken_v2`, il servizio chiama `getLTokenBySToken` e `getCookieAccountInfoBySToken` per generare `ltoken_v2` e `cookie_token_v2`, salva la cookie string completa e sincronizza immediatamente i profili di gioco (`HoyoverseGameProfile`).

### 2.2 Login / Aggiornamento tramite Cookie String ("Paste Cookie")
In alternativa al login con credenziali, dalla tab **Paste Cookie** è possibile incollare manualmente la stringa dei cookie HoYoLAB:
- **Requisiti minimi**: `ltoken_v2`, `ltuid_v2`, `ltmid_v2`.
- **Token opzionali ma consigliati**:
  - `stoken_v2`: abilita il rinnovo automatico perpetuo dei cookie e l'import automatico delle pull per Genshin Impact e Zenless Zone Zero.
  - `cookie_token_v2` e `account_id_v2`: necessari per il riscatto automatico dei codici se `stoken_v2` non è presente.

### 2.3 Rinnovo Automatico dei Cookie (`UpdateCookieMessage`)
- **Schedule**: `0 */4 * * *` (ogni 4 ore).
- **Funzionamento**: `UpdateCookieMessageHandler` scansiona tutti gli account dotati di `stoken_v2` e rinnova `ltoken_v2` e `cookie_token_v2`.
- **Notifiche di Stato**: invia una notifica all'utente solo alle transizioni di stato (quando il refresh fallisce per la prima volta o quando viene ripristinato con successo).

---

## 3. Auto Check-In Giornaliero

Il sistema gestisce due task distinti per garantire che nessun check-in giornaliero HoYoLAB vada perso:

1. **Auto Check-In (`HoyolabCheckInBatchMessage`)** — Schedule: `0 20 * * *`
   - Controlla tramite l'endpoint `info` se il check-in odierno è già stato effettuato per ciascun profilo con `hoyolabCheckIn = true`.
   - Se non ancora effettuato, invia la richiesta di `sign`, recupera il premio del giorno dall'endpoint `home` (es. *Primogems x20*) e invia una notifica con l'icona e la quantità della ricompensa, più il totale dei check-in mensili e gli eventuali giorni mancati.
2. **Missed Check-In Reminder (`HoyolabMissedCheckInBatchMessage`)** — Schedule: `0 14 * * *`
   - Per i profili con `hoyolabMissedCheckIn = true` (e auto check-in disattivato o fallito), verifica a metà giornata se il check-in è ancora mancante e invia un promemoria con il premio riscattabile oggi.

---

## 4. Auto Redemption dei Codici Promozionali

- **Schedule**: `*/15 * * * *` (ogni 15 minuti) tramite `CodeRedeemBatchMessage`.
- **Sorgente Codici**: interroga le API pubbliche `https://api.ennead.cc/mihoyo/{game}/codes` per ottenere la lista dei codici attivi e le relative ricompense.
- **Cache Anti-Riscatto Multiplo**: ogni codice già processato per uno specifico `HoyoverseGameProfile` viene memorizzato in cache permanente (`hoyoverse.redeemed_codes.cache`) con il relativo esito (`success`, `manual`, `error`), evitando chiamate ripetute alle API di HoYoverse.
- **Modalità di Riscatto**:
  1. **Riscatto Automatico via API** (*Genshin Impact, Honkai: Star Rail, Zenless Zone Zero*):
     - Rinnova preventivamente `cookie_token_v2` se l'account possiede `stoken_v2`.
     - Chiama l'endpoint `webExchangeCdkeyRisk` con un delay di sicurezza di 5 secondi tra un codice e l'altro per rispettare il rate-limit HoYoverse.
     - Invia una notifica riepilogativa per ogni codice riscattato (o scaduto/già riscattato) con l'elenco delle ricompense.
  2. **Notifica per Riscatto Manuale** (*Honkai Impact 3rd, Tears of Themis*, oppure profili privi di `cookie_token_v2`):
     - Poiché questi titoli non espongono un endpoint web di riscatto, il sistema invia una notifica non appena rileva un nuovo codice attivo, indicando le ricompense e le istruzioni per riscattarlo in-game.

---

## 5. Reminders e Monitoraggio Real-Time (Game Notes & Endgames)

I reminder sono suddivisi in **Task Globali** (eseguiti ad intervalli regolari indipendentemente dal server) e **Task Regionali** (eseguiti in base al fuso orario del server di gioco: `SEA` → `Asia/Shanghai`, `EU` → `Europe/Paris`, `NA` → `America/New_York`).

### 5.1 Stamina Reminder (`StaminaCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (ogni 30 minuti).
- **Configurazione per profilo**: toggle `staminaCheck` e soglia personalizzabile `staminaThreshold` (se impostata a `-1`, avvisa solo quando la stamina raggiunge il cap massimo: `200` su GI, `300` su HSR e ZZZ).
- **Anti-Spam in Cache**: una volta inviata la notifica di superamento soglia, il sistema salva la quantità notificata in cache fino al momento stimato di rigenerazione completa; se l'utente consuma stamina in-game scendendo sotto il valore precedente, il lock viene azzerato.

### 5.2 Expeditions Reminder (`ExpeditionsCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (ogni 30 minuti) — *Genshin Impact, Honkai: Star Rail*.
- **Funzionamento**: controlla lo stato delle spedizioni/assignments; quando **tutte** le spedizioni attive risultano `Finished`, invia una notifica (una sola volta per ciclo di completamento grazie alla cache di stato).

### 5.3 Realm Currency Reminder (`RealmCurrencyCheckBatchMessage`)
- **Schedule**: `0 * * * *` (ogni ora) — *Genshin Impact (Serenitea Pot)*.
- **Funzionamento**: identico allo Stamina Reminder; avvisa quando le monete della Teapot superano `realmCurrencyThreshold` (o raggiungono il cap massimo se `-1`).

### 5.4 Shop Status Reminder (`ShopStatusCheckBatchMessage`)
- **Schedule**: `0 * * * *` (ogni ora) — *Zenless Zone Zero*.
- **Funzionamento**: monitora lo stato del Video Store (`VhsSaleState`) e invia un avviso se il negozio richiede gestione (`SaleStateNo` / VHS non impostate).

### 5.5 Dailies & Weeklies Reminders (`DailiesReminderBatchMessage` / `WeekliesReminderBatchMessage`)
- **Schedule Regionale**:
  - **Dailies**: `0 21 * * *` (ore 21:00 ora locale del server `SEA` / `EU` / `NA`).
  - **Weeklies**: `0 21 * * 0` (ogni Domenica alle ore 21:00 ora locale del server).
- **Funzionamento**: verifica tutte le metriche giornaliere o settimanali del gioco (es. Commissioni 4/4 su GI, 500 Activity Points o Echo of War su HSR, Engagement/Scratch Card/Coff Cafe/Bounty Commissions su ZZZ) e invia un promemoria **solo se almeno una attività risulta incompleta**.

### 5.6 Endgames Reminder (`EndgamesReminderBatchMessage`)
- **Schedule Regionale**: `0 15 * * *` (ore 15:00 ora locale del server).
- **Finestra di Avviso**: invia un promemoria solo quando una modalità endgame sta per scadere (**tra 1 e 3 giorni rimanenti** alla chiusura della rotazione) e il giocatore **non ha ancora completato tutte le stelle/livelli massimi**:
  - **Genshin Impact**: *Spiral Abyss* (Floor 12, 36★), *Imaginarium Theater* (Act 10), *Stygian Onslaught*.
  - **Honkai: Star Rail**: *Memory of Chaos*, *Pure Fiction*, *Apocalyptic Shadow*, *Anomaly Arbitration*.
  - **Zenless Zone Zero**: *Shiyu Defense* (Frontier 7, S-Rank), *Deadly Assault* (9★).

---

## 6. Sincronizzazione del Diary (Valute Mensili)

La funzionalità **Diary** permette di archiviare e consultare lo storico dettagliato di tutte le valute ottenute in-game (che HoYoLAB conserva solo per gli ultimi 3 mesi):
- **Valute tracciate**:
  - **Genshin Impact** (*Traveler's Diary*): `PRIMOGEM` e `MORA`.
  - **Honkai: Star Rail** (*Trailblaze Monthly Calendar*): `STELLAR_JADE` e `STAR_RAIL_PASS`.
  - **Zenless Zone Zero** (*Inter-Knot Monthly Report*): `POLYCHROME` e `MASTER_TAPE`.
- **Sincronizzazione Automatica (`SyncDiaryBatchMessage`)**:
  - **Schedule**: `0 0 2 * *` (il giorno 2 di ogni mese a mezzanotte).
  - Per tutti i profili con `syncDiary = true`, accoda la sincronizzazione completa del **mese appena concluso** e del **mese corrente**.
- **Sincronizzazione Manuale da UI**:
  - Dalla pagina **Diary**, l'utente può selezionare uno degli ultimi 3 mesi disponibili su HoYoLAB e avviare l'importazione asincrona (`POST /api/game-profiles/{id}/diary/sync`).
- **Architettura di Importazione (`SyncDiaryPageProfileHandler`)**:
  - Scarica i log paginati (`page_size = 100`) per ciascuna valuta e salva ogni record in `hoyoverse_diary_entry` (`action_id`, `action_name`, `amount`, `awarded_at`), ignorando automaticamente i duplicati già presenti a database.
  - La tabella frontend supporta filtri avanzati per valuta, sorgente/azione, range di date e ordinamento.

---

## 7. Sincronizzazione delle Pulls (Automatica e tramite PowerShell)

Il **Pulls Tracker** registra l'intero storico dei banner Gacha nella tabella `hoyoverse_pull` (`HoyoversePull`), calcola le statistiche di pity (`5★`/`S-Rank` e `4★`/`A-Rank`), mostra la distribuzione mensile (`PullsMonthlyChart`) e il dettaglio di ogni singolo banner (`PullsBannerCard` / `PullsBannerDetail`).

### 7.1 Architettura, DTO e Coda Asincrona (`hoyoverse_pulls`)
- **Interfacce & Trait**:
  - `HasPullTrackerInterface` + `HoyoversePullTrait`: gestiscono la chiamata agli endpoint `getGachaLog` / `getLdGachaLog`, la denormalizzazione nel DTO `HoyoversePullItem` e la mappatura sull'entità `HoyoversePull` tramite `Symfony\Component\ObjectMapper`.
  - `HasStokenPullImportInterface`: implementata da **Genshin Impact** e **Zenless Zone Zero** per l'importazione automatica server-side.
  - `HasPowershellPullImportInterface`: implementata da **Genshin Impact**, **Honkai: Star Rail** e **Zenless Zone Zero** per l'importazione tramite URL estratto da PowerShell.
- **Idempotenza e Vincoli Database (`hoyoverse_pull`)**:
  - Vincolo di unicità `IDX_hoyoverse_game_profile_id_pull_id` su `(hoyoverse_game_profile_id, pull_id)` (ID progressivo a 19 cifre di HoYoverse).
  - La paginazione a ritroso si interrompe automaticamente non appena incontra un `pull_id` già presente a database.
- **Canale Messenger Dedicato e Rate-Limiting**:
  - Tutti i job di importazione viaggiano sul trasporto dedicato **`hoyoverse_pulls`**.
  - `SyncPullsBatchHandler` scagliona i profili per servizio di gioco tramite `DelayStamp`.
  - `SyncPullsPageProfileHandler` scarica una singola pagina (`size = 20`) e, se ci sono altre pagine da acquisire, ri-accoda `SyncPullsPageProfileMessage` con `end_id` aggiornato e un **`DelayStamp` con jitter casuale (`1500ms – 2500ms`)** per evitare rate-limit sulle API HoYoverse.

### 7.2 Sincronizzazione Automatica via `stoken_v2` (Genshin Impact & Zenless Zone Zero)
Per i giochi che implementano `HasStokenPullImportInterface` (**Genshin Impact** e **Zenless Zone Zero**), se l'account possiede un `stoken_v2` (ottenuto automaticamente effettuando il login con Email/Password):
1. `HoyolabCookieUtilsService::generateAuthKey()` genera al volo un `authkey` temporaneo (`webview_gacha`) firmato con `DS` LK2 (`salt=ulInCDohgEs557j0VsPDYnQaaz6KJcv5`) tramite l'endpoint Takumi `https://api-account-os.hoyoverse.com/binding/api/genAuthKey`.
2. **Banner configurati**:
   - **Genshin Impact**: *Character Event Wish* (`301` + `400`, pity 90/10), *Weapon Event Wish* (`302`, pity 80/10), *Standard Wish* (`200`, pity 90/10), *Beginners' Wish* (`100`, pity 20/10), *Chronicled Wish* (`500`, pity 90/10).
   - **Zenless Zone Zero**: *Exclusive Channel* (`2`, pity 90/10), *W-Engine Channel* (`3`, pity 80/10), *Standard Channel* (`1`, pity 90/10), *Bangboo Channel* (`5`, pity 80/10).
3. **Esecuzione**:
   - **Automatica notturna (`SyncPullsBatchMessage`)**: ogni notte alle `01:00` (`0 1 * * *`) per tutti i profili con lo switch `syncPulls = true` nel `GameProfileSettingsDrawer`.
   - **Manuale in 1-click da UI**: cliccando **Sync Pulls** nella vista dello storico (`PullsPage`).

### 7.3 Sincronizzazione tramite Script PowerShell / URL Gacha (Tutti i giochi inclusi HSR)
Poiché **Honkai: Star Rail** (`hkrpg_global`) non consente la generazione dell'`authkey` gacha tramite `stoken_v2` (restituisce `retcode -106`), e come fallback per gli account privi di `stoken_v2`, è disponibile l'importazione assistita tramite PowerShell (`PowershellPullImportModal`):
1. L'utente copia ed esegue in Windows PowerShell il comando one-liner mostrato nella modale:
   ```powershell
   irm "https://hoyoverse.<dominio>/api/pulls/script/powershell?game_biz=<game_biz>" | iex
   ```
2. L'endpoint `GET /api/pulls/script/powershell` genera dinamicamente lo script `.ps1` in base alla `PowershellPullConfig` del gioco:
   - Ispeziona i file di log locali (`Player.log` / `output_log.txt` in `%USERPROFILE%\AppData\LocalLow\...`), individua la cartella `webCaches\...\Cache\Cache_Data\data_2`, estrae l'URL `getGachaLog` più recente contenente `authkey=...` e lo **copia automaticamente negli appunti** (`Set-Clipboard`).
3. L'utente incolla l'URL nella modale e avvia l'importazione (`POST /api/game-profiles/{id}/pulls/import-url`).
4. Il backend valida l'URL e accoda i messaggi `SyncPullsPageProfileMessage` su `hoyoverse_pulls` per tutti i banner del gioco, inclusi per **Honkai: Star Rail**:
   - *Character Event Warp* (`11`, pity 90/10), *Light Cone Event Warp* (`12`, pity 80/10), *Collaboration Character Warp* (`21` su `getLdGachaLog`, pity 90/10), *Collaboration Light Cone Warp* (`22` su `getLdGachaLog`, pity 80/10), *Regular Warp* (`1`, pity 90/10) e *Departure Warp* (`2`, pity 50/10).

### 7.4 Icone Item e Cache Immagini (`LiipImagine`)
Le miniature di personaggi, armi, Light Cone, W-Engine e Bangboo nelle card (`PullsRecentBadge`) e nella tabella di dettaglio (`PullItemThumbnail`) utilizzano l'architettura condivisa descritta in **[Pull Item Icons & Image Caching](../../docs/PULL_ITEM_ICONS.md)**:
- **Provider esterno (`NanokaIconResolver`)**: implementa `PullItemIconResolverInterface` per `GenshinImpactService`, `HonkaiStarRailService` e `ZenlessZoneZeroService` con priorità `100`, usando il client HTTP dedicato `hoyoverse.nanoka.client` e il pool di cache `hoyoverse.item_icons.cache` (TTL 24h con protezione anti-stampede):
  - **Genshin Impact** (`hk4e_global`): lookup per nome normalizzato su `gi/{version}/{character|weapon}.json` → `https://static.nanoka.cc/assets/gi/{icon}.webp`.
  - **Honkai: Star Rail** (`hkrpg_global`): risoluzione diretta per `itemId` → `avatarroundicon/{itemId}.webp` (personaggi) e `lightconemediumicon/{itemId}.webp` (Light Cone).
  - **Zenless Zone Zero** (`nap_global`): lookup su `zzz/{version}/{character|weapon|bangboo}.json` convertendo `IconRole*` → `IconRoleCircle*` per gli agenti.
- **Arricchimento API & Cache Locale**: i controller `ApiGameProfilesController` aggiungono `'icon_url'` tramite `PullItemIconUrlBuilder::enrichPullRows()`; al primo caricamento `LiipImagineBundle` scarica l'asset tramite `PullItemIconBinaryLoader` e salva la versione ridimensionata (`128×128`, `webp`, qualità `100`) in `public/media/cache/core_pull_item_icon/`.

---

## 8. Riepilogo Endpoint API del Bundle

| Metodo | Percorso | Descrizione |
| :--- | :--- | :--- |
| `POST` | `/api/accounts/auth/login/start` | Avvia il login diretto con Email/Username + Password |
| `POST` | `/api/accounts/{id}/relogin/start` | Avvia il re-login su un `HoyoverseAccount` esistente (riusa `device_id` e valida `account_uid`) |
| `POST` | `/api/accounts/auth/login/captcha` | Invia la soluzione del captcha GeeTest v3/v4 Aigis |
| `POST` | `/api/accounts/auth/login/verify-email` | Verifica il codice OTP a 6 cifre ricevuto via email (`-3239`) |
| `POST` | `/api/accounts/auth/login/resend-email` | Richiede un nuovo invio del codice OTP via email |
| `GET` | `/api/accounts/auth/geetest/frame` | Renderizza l'iframe isolato per il widget GeeTest v3/v4 |
| `GET` | `/api/accounts` / `POST /api/accounts` | Lista account o aggiunge/aggiorna un account tramite Cookie String |
| `POST` | `/api/accounts/{id}/sync` | Sincronizza i profili di gioco (`HoyoverseGameProfile`) dall'account HoYoLAB |
| `POST` | `/api/game-profiles/{id}/diary/sync` | Accoda la sincronizzazione del Diary per un mese specifico |
| `POST` | `/api/game-profiles/{id}/pulls/sync` | Accoda la sincronizzazione automatica delle pull tramite `stoken_v2` |
| `POST` | `/api/game-profiles/{id}/pulls/import-url` | Accoda l'importazione delle pull da un URL Gacha estratto via PowerShell |
| `GET` | `/api/pulls/script/powershell` | Restituisce lo script PowerShell `.ps1` per estrarre l'URL Gacha dalla cache locale |

