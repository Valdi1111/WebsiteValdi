# Piano: GamesBundle e frontend unificato su `games.<domain>`

## 1. Obiettivo

Oggi HoYoverse e Gryphline sono due bundle con due sottodomini (`hoyoverse.<domain>`, `gryphline.<domain>`), due SPA React quasi identiche (ognuna con un solo tab) e una parte di codice "da giochi" parcheggiata in `CoreBundle`.

Vogliamo:

1. un nuovo **`GamesBundle`** che contenga il codice comune ai giochi (oggi in Core o duplicato nei due bundle);
2. un **unico frontend** servito da `games.<domain>` (`games.valdi.ovh` in prod, `games.valdi.wip` in dev), con un tab per provider (HoYoverse, Gryphline, e in futuro altri);
3. eliminare la duplicazione dei componenti frontend e delle parti backend più semplici da unificare, **senza over-engineering**.

I due bundle provider restano: contengono API client, entità, servizi di gioco, messaggi, handler e le parti di UI davvero specifiche.

## 2. Stato attuale (sintesi dell'analisi)

### Frontend

- Struttura della SPA identica nei due bundle, cambiano solo i nomi: `app.js`, `App.jsx`, `MainLayout.jsx`, `BackendApi.js`, `BackendApiContext.jsx`, `constants.js`, `templates/index.html.twig`, `IndexController`, `ApiController` (vuoto).
- Le route React sono **già prefissate** con il provider (`/hoyoverse/...`, `/gryphline/...`) e tutti i `navigate()` usano path assoluti, quindi nella SPA unica le route non cambiano.
- Duplicazione misurata (righe diverse ignorando gli spazi):

| Componente | Righe (H / G) | Righe diverse | Note |
|---|---|---|---|
| PullsMonthlyChart | 163 / 163 | 8 | solo fallback (5★/6★, "Pulls"/"Headhunts") |
| PullsBannerCard | 313 / 313 | 18 | fallback pity 90/80 e label |
| PullsBannerDetail | 419 / 414 | 41 | fallback, `item_type` mostrato solo in H; il repository Gryphline espone già anche `gacha_name` |
| AccountsPage | 191 / 191 | 19 | testi (cambia solo il nome del provider) e flag `canRedeemCodes` |
| PullsPage | 511 / 468 | 87 | voce PowerShell, flag di sync diverso, path, fallback "Arknights: Endfield" |
| AccountCard | 141 / 125 | 48 | tag di stato dell'account |
| GameProfileCard | 116 / 105 | 74 | tag e azioni; fallback immagine solo in Gryphline |
| GameProfileSettingsDrawer | 517 / 353 | 270 | ~300 righe di Gryphline identiche a Hoyoverse |
| AddAccountModal | 472 / 315 | 285 | ~250 righe di Gryphline identiche a Hoyoverse |

- In Core ci sono già componenti usati **solo** dai due bundle giochi: `components/pulls/*` (BackupPullImportModal, PullOperationProgressBanner, usePullOperationProgress), `GeetestCaptchaFrame`, `SettingRow`, `ThresholdInput`.

### Backend e infrastruttura

- In `CoreBundle` ci sono ~45 file PHP usati solo da Hoyoverse/Gryphline (vedi §5, Fase 1). Dipendono da Core solo per `User` e `LabeledInterface`.
- Duplicazioni quasi identiche tra i due bundle: blocco di configurazione dei task nei `*Bundle.php` (~80 righe), `ScheduleProvider` (17 righe diverse), endpoint Geetest in `ApiAccountAuthController`, `HoyoverseLoginStepResult`/`GryphlineLoginStepResult` (diversi solo per il tipo dell'account e una costante).
- Le route API dei due bundle hanno **gli stessi path** (`/api/accounts/...`) e la stessa route catch-all `/{path}`: oggi le separa solo il `host`.
- Il topic Mercure dei pulls è `https://{domain}/profiles/{id}/pulls/progress`, con `id` che viene da due tabelle diverse.
- Cache e lock dei pulls sono già separati per provider (pool distinti, chiavi `hoyoverse_pulls_profile_%d` / `gryphline_profile_pulls_%d`).
- L'hub Mercure è su `mercure.<domain>`. Le sue origini CORS (`cors_origins`, `publish_origins` nel Caddyfile) sono generate da `SUBDOMAINS` in `scripts/setup-server.sh`. Il cookie `mercureAuthorization` è impostato sul dominio padre (`.<domain>`).
- Il routing Messenger è dichiarato con `#[AsMessage]` sulle classi dei provider; supervisor usa solo i nomi dei transport.

### Test

Baseline: **116 test, 662 asserzioni, tutti verdi** (`php bin/phpunit`). Sono tutti test unitari/di servizio con mock; nessun `WebTestCase` (richiederebbe il DB anche per la sessione, salvata con `PdoSessionHandler`). Nessuna infrastruttura di test JS.

## 3. Decisioni prese e perché

| # | Decisione | Perché |
|---|---|---|
| D1 | Nuovo `GamesBundle`. Dipendenze: `Core ← Games ← Hoyoverse/Gryphline` | Core torna generico (utenti, ruoli, notifiche, layout, tabelle, file manager). Il codice "da giochi" ha un posto preciso e i futuri provider dipendono solo da Games. |
| D2 | Un solo host `games.%domain_name%`. I vecchi sottodomini vengono **rimossi, senza redirect** | Richiesta esplicita: i vecchi link non interessano. Niente controller o regole di redirect da mantenere. |
| D3 | API dei provider sotto `/api/{provider}/...` (es. `/api/hoyoverse/accounts`) | Evita la collisione dei path. Con il prefisso `/api/...` la route catch-all della SPA resta semplice (`^(?!api/).*`). |
| D4 | Il topic Mercure include il provider: `https://games.<domain>/{provider}/profiles/{id}/pulls/progress` | Senza provider, su un solo dominio il profilo Hoyoverse 5 e il Gryphline 5 avrebbero lo stesso topic: chi è autorizzato a uno riceverebbe gli aggiornamenti dell'altro, anche di un altro utente. |
| D5 | Nuovo ruolo `ROLE_USER_GAMES` via migration dati, incluso in `ROLE_USER_HOYOVERSE` e `ROLE_USER_GRYPHLINE` nella gerarchia DB | Stesso meccanismo già usato per gli altri ruoli; la gerarchia è transitiva, quindi anche `ROLE_ADMIN_*` arriva a `ROLE_USER_GAMES`. La pagina games richiede `ROLE_USER_GAMES`; le API restano protette dai ruoli dei singoli provider. |
| D6 | La lista dei provider e del ruolo che sblocca ciascun tab è configurata esplicitamente in `config/packages/games.yaml` | Ordine dei tab chiaro e in un solo punto lato backend. Alternativa scartata: ogni bundle che fa `prependExtensionConfig('games', ...)` (ordine dipendente da `bundles.php`, più magia del necessario). |
| D7 | Una sola SPA (entry Encore `games`). Il codice comune vive in `GamesBundle/assets`, le parti specifiche restano nei bundle provider | Ogni provider possiede backend e UI specifica; Games possiede struttura e componenti comuni. |
| D8 | `GamesBundle/assets/providers.js` è l'unico punto che importa i provider, ed è importato **solo** da `app.js`/`App.jsx`. I componenti comuni leggono il provider corrente dal context | Composition root unico; così non nascono import circolari tra Games e provider. |
| D9 | Ogni provider esporta un **descrittore** statico (`assets/provider.js`) con il minimo indispensabile, che evolve fase per fase. Niente `texts` (i testi cambiano solo per il nome: basta `label`) e niente `apiUrl` (si ricava da `/api/${key}`) | Serve solo a far funzionare pagine comuni con parti diverse. Niente registry dinamico o sistema di plugin. |
| D10 | Un solo `BackendApi` e un solo `BackendApiContext`, **istanziati per provider** dentro un `ProviderScope` con `key={provider.key}` | Ogni sottoalbero `/hoyoverse/*` e `/gryphline/*` ha la sua istanza; i componenti continuano a chiamare `useBackendApi()`. La `key` fa rimontare tutto al cambio tab: senza, React riuserebbe gli stessi componenti e resterebbero lista account, modal e drawer del provider precedente. |
| D11 | Si unificano: struttura SPA, componenti pulls, AccountsPage, AccountCard, GameProfileCard, struttura del drawer impostazioni, logica del login (`useLoginWizard` + `CaptchaStep`); lato backend config task + scheduler, endpoint Geetest, `LoginStepResult`, flag `supports_pull_sync` | Sono le parti con molta duplicazione e differenze piccole o già guidate dai dati (`"campo" in profile`). |
| D12 | **Non** si unificano: classi dei messaggi, `AbstractBatchTaskMessageHandler`, interfacce/manager di importer e reconciler, `AbstractProfileTaskMessageHandler` e handler di reminder, `ApiAccountsController`, endpoint pulls, servizi di import/sync, entità, servizi di autenticazione, normalizer. Nel login non facciamo un modal comune a slot | Messaggi: ognuno instrada sulla coda del proprio bundle. Handler batch: saldo di righe circa zero, rischio con le proprietà `readonly` ridichiarate e `CodeRedeemBatchHandler` ha un costruttore proprio. Importer/reconciler: tipizzati sulle entità del bundle. Modal a slot: troppa astrazione per circa 30 righe di `Modal` + `Tabs`. Il resto: struttura simile, dettagli diversi. |
| D13 | Nessun cambio a tabelle/entità, transport e schedule Messenger, configurazioni supervisor, sessione/remember-me | Non servono: le tabelle restano per provider e sessione/remember-me sono già sul dominio padre. Unica migration: il ruolo. |
| D14 | Le code Messenger vengono svuotate al deploy | Deciso dall'utente; nessuna compatibilità da garantire per messaggi serializzati. |

## 4. Architettura finale

### Dipendenze

```
CoreBundle      utenti, ruoli/permessi, sicurezza, notifiche, layout React, tema, tabelle, file manager
   ▲
GamesBundle     modelli/interfacce comuni dei giochi, tracker pulls, icone pulls, Geetest, auth session,
   ▲            pagina games + SPA comune
   │
HoyoverseBundle, GryphlineBundle   API client, entità, servizi di gioco, messaggi/handler, UI specifica
```

### Struttura `GamesBundle`

```
src/GamesBundle/
  GamesBundle.php                 AbstractBundle: config (domain_name, providers), parametri, autoconfigurazione
  README.md
  config/
    services.yaml                 esclude Model/, Entity/, DependencyInjection/ (come Core)
    routing.yaml                  host games, name_prefix games_
    packages/
      cache.yaml                  games.geetest.cache
      framework.yaml              client games.pull_icon_fetch.client
      liip_imagine.yaml           filtro games_pull_item_icon
      twig.yaml                   global games_domain_name
  templates/index.html.twig       <div id="root" data-providers="...">, entry "games"
  assets/
    app.js                        entry Encore "games"
    providers.js                  [hoyoverse, gryphline]; importato solo da app.js/App.jsx
    images/                       (eventuale favicon games)
    components/
      App.jsx, MainLayout.jsx, ProviderScope.jsx
      BackendApi.js, BackendApiContext.jsx, GameProviderContext.jsx
      GeetestCaptchaFrame.jsx, SettingRow.jsx, ThresholdInput.jsx        (da Core)
      account/      AccountsPage.jsx, AccountCard.jsx, CaptchaStep.jsx, useLoginWizard.js
      game-profile/ GameProfileCard.jsx, GameProfileSettingsDrawer.jsx
      pulls/        PullsPage.jsx, PullsBannerCard.jsx, PullsBannerDetail.jsx, PullsMonthlyChart.jsx,
                    BackupPullImportModal.jsx, PullOperationProgressBanner.jsx, usePullOperationProgress.js
  src/
    Command/PullIconResolversCommand.php
    Controller/IndexController.php, GeetestController.php
    DependencyInjection/GameTasksConfiguration.php
    Exception/PullProfileLockedException.php
    Message/{Task,RegionalTask,ProfileTask,FeatureFlag}MessageInterface.php
    Model/Auth/{SubmitCaptchaRequest,LoginStepResult}.php
    Model/Game/*, Model/GameNotes/*, Model/Pull/*
    Normalizer/AbstractGameProfileNormalizer.php
    Scheduler/AbstractTaskScheduleProvider.php
    Service/AbstractPullOperationTracker.php, AuthSessionStore.php, GameProviderAccess.php,
            GameUtilsService.php, GeetestService.php, PullItemIconBinaryLoader.php,
            PullItemIconPathCodec.php, PullItemIconResolverManager.php, PullItemIconUrlBuilder.php
tests/GamesBundle/                test spostati da tests/CoreBundle + nuovi
```

Frontend che resta nei provider:

```
src/HoyoverseBundle/assets/
  provider.js
  components/account/AddAccountModal.jsx           Modal + Tabs, campi credenziali, tab cookie, passo email;
                                                   usa useLoginWizard + CaptchaStep
  components/account/AccountStatusTags.jsx
  components/game-profile/ProfileSettingsSections.jsx
  components/game-profile/PowershellPullImportModal.jsx
  components/diary/*
src/GryphlineBundle/assets/
  provider.js
  components/account/AddAccountModal.jsx           Modal + Tabs, campi credenziali, tab token;
                                                   usa useLoginWizard + CaptchaStep
  components/account/AccountStatusTags.jsx
  components/game-profile/ProfileSettingsSections.jsx
```

### Mappa URL

| Prima | Dopo |
|---|---|
| `hoyoverse.<d>/hoyoverse/...` (SPA) | `games.<d>/hoyoverse/...` |
| `gryphline.<d>/gryphline/...` (SPA) | `games.<d>/gryphline/...` |
| `hoyoverse.<d>/api/accounts/...` | `games.<d>/api/hoyoverse/accounts/...` |
| `gryphline.<d>/api/accounts/...` | `games.<d>/api/gryphline/accounts/...` |
| `hoyoverse.<d>/api/pulls/script/powershell` | `games.<d>/api/hoyoverse/pulls/script/powershell` |
| `{hoyoverse,gryphline}.<d>/api/accounts/login/geetest-script/{v}` | `games.<d>/api/geetest-script/{v}` |
| `games.<d>/` | redirect React (`replace`) al primo provider consentito |
| `games.<d>/foo`, `games.<d>/hoyoverse/foo`, provider non consentito | pagina 404 della SPA |

I nomi delle route Symfony dei provider **non cambiano** (es. `hoyoverse_api_hoyoverse_accounts_get`).

## 5. Fasi di implementazione

Ogni fase termina con: `composer dump-autoload` (se serve), `bin/console lint:container`, `bin/console cache:clear`, `php bin/phpunit` verde, `npm run dev` senza errori. Ogni fase è un punto di commit naturale; nella Fase 3 conviene un commit per componente.

### Fase 0 — Baseline

- Salvare in scratch l'output di `bin/console debug:router`, `debug:scheduler`, `debug:messenger` per confrontarlo dopo le fasi 2 e 4 (scheduler e messenger non devono cambiare).
- Test: 116 verdi (già verificato).

### Fase 1 — Creare GamesBundle e spostarci il codice comune (nessun cambio di comportamento)

1. Scheletro del bundle come gli altri bundle interni (`AbstractBundle`, `getPath()`, `prependExtension()` che importa `config/packages/`, `loadExtension()` che importa `services.yaml`). `services.yaml` esclude `Model/`, `Entity/` e `DependencyInjection/`, come Core.
2. Registrazione:
   - `composer.json`: `"App\\GamesBundle\\": "src/GamesBundle/src/"` in `autoload` e la voce `Tests` in `autoload-dev`, poi `composer dump-autoload`.
   - `config/bundles.php`: `GamesBundle` dopo `CoreBundle` e prima dei provider. (Bundle interno: non esiste una ricetta Flex, la registrazione manuale è quella già usata per gli altri.)
   - `webpack.config.js`: alias `@GamesBundle` e `copyFiles` per `src/GamesBundle/assets/images/`.
3. Spostamento con `git mv` (per tenere la storia) da `src/CoreBundle/src/` a `src/GamesBundle/src/`:
   - `Model/Game/*` (16), `Model/GameNotes/*` (9), `Model/Pull/*` (4), `Model/Auth/SubmitCaptchaRequest.php`
   - `Message/{TaskMessageInterface,RegionalTaskMessageInterface,ProfileTaskMessageInterface,FeatureFlagMessageInterface}.php`
   - `Normalizer/AbstractGameProfileNormalizer.php`
   - `Service/{AbstractPullOperationTracker,AuthSessionStore,GameUtilsService,GeetestService,PullItemIconBinaryLoader,PullItemIconPathCodec,PullItemIconResolverManager,PullItemIconUrlBuilder}.php`
   - `Command/PullIconResolversCommand.php`, `Exception/PullProfileLockedException.php`
4. Riscrittura dei namespace (`App\CoreBundle\X` → `App\GamesBundle\X` per le sole classi spostate) in `src/` e `tests/`, **inclusi i file non ancora committati** (`GryphlineCheckInCommand.php`, `GryphlineFetchGameProfilesCommand.php`, `HoyolabCommand.php`). Verifica finale con `grep` che non restino riferimenti ai vecchi FQCN.
5. Configurazione spostata e rinominata con prefisso `games`, compresi tutti i riferimenti negli attributi:
   - cache `core.geetest.cache` → `games.geetest.cache` (anche `#[Target]` in `GeetestService.php:19`);
   - client HTTP `core.pull_icon_fetch.client` → `games.pull_icon_fetch.client` (anche `#[Target]` in `PullItemIconBinaryLoader.php:27` e `PullIconResolversCommand.php:24`);
   - filtro Liip `core_pull_item_icon` → `games_pull_item_icon`: `liip_imagine.yaml`, costante `PullItemIconUrlBuilder::FILTER_NAME`, loader `core.pull_item_icon_loader` → `games.pull_item_icon_loader` (`liip_imagine.yaml` e `#[AutoconfigureTag]` in `PullItemIconBinaryLoader.php:14`);
   - tag `core.pull_item_icon_resolver` → `games.pull_item_icon_resolver`: `#[AutoconfigureTag]` su `PullItemIconResolverInterface`, `#[AutowireIterator]` in `PullItemIconResolverManager`, e `registerForAutoconfiguration` spostato da `CoreBundle.php` a `GamesBundle.php`. Va tenuto anche l'attributo, come oggi: l'interfaccia sta in `Model/`, che è escluso dai servizi.
6. JS spostato da `src/CoreBundle/assets/components/` a `src/GamesBundle/assets/components/`: `pulls/*`, `GeetestCaptchaFrame.jsx`, `SettingRow.jsx`, `ThresholdInput.jsx`; aggiornamento degli import `@CoreBundle/...` → `@GamesBundle/...` nei due bundle.
7. Test spostati in `tests/GamesBundle/`: `Model/GameNotes/*`, `Service/PullItemIconComponentsTest.php` (aggiornando l'URL atteso con `core_pull_item_icon` alle righe 164 e 177), `Service/CoreAuthComponentsTest.php` (rinominato `GamesAuthComponentsTest.php`).
8. `config/reference.php` è generato e cambia: va rigenerato e committato.

Conseguenza nota: cambiando il nome del filtro Liip le miniature delle icone vengono rigenerate alla prima richiesta (`public/media/cache/core_pull_item_icon` si può cancellare).

### Fase 2 — Un solo host `games` (backend + struttura frontend)

Backend e frontend vanno fatti insieme: appena tolti gli `IndexController` dei provider serve la SPA unica.

**Backend**

1. Config di `GamesBundle` (`configure()`):
   ```yaml
   # config/packages/games.yaml
   games:
       domain_name: 'games.%domain_name%'
       providers:
           hoyoverse: { role: ROLE_USER_HOYOVERSE }
           gryphline: { role: ROLE_USER_GRYPHLINE }
   ```
   Parametri `games.domain_name` e `games.providers`; global Twig `games_domain_name`; `config/routes/games.yaml` che importa `@GamesBundle/config/routing.yaml` (host `%games.domain_name%`, `name_prefix: games_`).
2. `GamesBundle\Service\GameProviderAccess`: riceve `AuthorizationCheckerInterface` e `#[Autowire(param: 'games.providers')]`. `getGrantedProviders(): list<string>` restituisce le chiavi dei provider consentiti, nell'ordine di config.
3. `GamesBundle\Controller\IndexController`, di poche righe:
   - `#[IsGranted('ROLE_USER_GAMES')]`, route `/{path}` con `requirements: ['path' => '^(?!api/).*']`, `priority: -10`, come oggi;
   - `Discovery::addLink()` per Mercure, come oggi;
   - rende `@Games/index.html.twig` con `<div id="root" data-providers="{{ providers|json_encode|e('html_attr') }}">` ed entry `games`, dove `providers` viene da `GameProviderAccess`.
4. Route dei provider (`src/{Hoyoverse,Gryphline}Bundle/config/routing.yaml`): `host: '%games.domain_name%'` e `prefix: /api/hoyoverse` (o `/api/gryphline`). Si toglie `/api` dai path:
   - `#[Route]` di classe: `ApiAccountsController` e `ApiAccountAuthController` → `/accounts`, `ApiGameProfilesController` → `/accounts/{account}`;
   - `PublicPowershellScriptController`, dove la route è **sul metodo** (riga 28): `/pulls/script/powershell`. Resta pubblico: nessun `IsGranted`, nessun `access_control`.
   I nomi delle route restano uguali.
5. Eliminati nei provider: `IndexController`, `ApiController` (vuoto), `templates/index.html.twig`, nodo `domain_name` in `configure()` e relativo parametro, global Twig `*_domain_name`, riga `domain_name` in `config/packages/{hoyoverse,gryphline}.yaml`.
6. `templates/favicon.html.twig`: i due casi `hoyoverse`/`gryphline` diventano un caso `games_domain_name` (icona: `games-favicon.ico` in `GamesBundle/assets/images` se fornita, altrimenti un SVG emoji 🎮 come il fallback già presente).
7. Mercure, in `AbstractPullOperationTracker`:
   - il costruttore riceve il dominio games;
   - `getFallbackDomain()` sostituito da `abstract protected function getProviderKey(): string`;
   - topic `sprintf('https://%s/%s/profiles/%d/pulls/progress', $domain, $this->getProviderKey(), $profileId)`, fallback `games.local`;
   - le due sottoclassi `PullOperationTracker` usano `#[Autowire(param: 'games.domain_name')]` e restituiscono `hoyoverse`/`gryphline`.
   Nessun cambio lato JS: il topic arriva già nella risposta `pulls/overview` (`mercure_topic`). Il cookie `mercureAuthorization` resta sul dominio padre e non va toccato.
8. Migration dati (generata con `bin/console doctrine:migrations:generate`, stesso stile di `Version20261107022830` / `Version20261107022845`, con `INSERT IGNORE` e subselect):
   - ruolo `ROLE_USER_GAMES` (`'User games'`, bundle `GamesBundle`);
   - gerarchia: `ROLE_USER_HOYOVERSE → ROLE_USER_GAMES`, `ROLE_USER_GRYPHLINE → ROLE_USER_GAMES`, `ROLE_USER_GAMES → ROLE_USER`;
   - `down()` simmetrico.
   La gerarchia è in cache (`core.security_hierarchy.cache`, senza TTL e senza invalidazione). In Symfony 8 i pool applicativi stanno in `var/share/{env}/pools/app`, che `cache:clear` **non** svuota: dopo la migration serve `bin/console cache:pool:clear core.security_hierarchy.cache`. (Verificato in dev: senza questo comando la pagina games restituiva accesso negato.)

**Frontend (struttura comune)**

9. Entry Encore: tolte `hoyoverse` e `gryphline`, aggiunta `games` → `@GamesBundle/app.js`.
10. `app.js`: legge `data-providers` da `#root`, filtra `providers.js` e rende `<AppRoot><App providers={granted}/></AppRoot>`.
11. Descrittore di provider (`src/{Hoyoverse,Gryphline}Bundle/assets/provider.js`), **minimo in Fase 2**:
    ```js
    export default {
        key: "hoyoverse",
        label: "HoYoverse",
        icon: <UserSwitchOutlined />,
        AccountsPage,          // componenti esistenti del provider, ancora duplicati in questa fase
        PullsPage,
        extraRoutes: [{ path: "accounts/:accountId/profiles/:gameProfileId/diary", element: <DiaryPage /> }],
    };
    ```
    In Fase 2 i componenti esistenti funzionano senza toccare la navigazione, perché usano path assoluti (`/hoyoverse/...`) e il `basename` resta `""`.
12. `App.jsx`, un solo `BrowserRouter`:
    ```jsx
    <Routes>
        <Route element={<MainLayout providers={providers} />}>          {/* layout route con <Outlet/> */}
            <Route index element={providers.length
                ? <Navigate to={`/${providers[0].key}`} replace />
                : <Empty description="No games available" />} />
            {providers.map((p) => (
                <Route key={p.key} path={`/${p.key}/*`} element={<ProviderScope key={p.key} provider={p} />} />
            ))}
            <Route path="*" element={<NotFoundComponent redirectPath="/" redirectText="Back Home" />} />
        </Route>
    </Routes>
    ```
    `ProviderScope`:
    - crea `createBackendApi(`/api/${provider.key}`, app)` (memo);
    - fornisce `BackendApiContext` e `GameProviderContext` (`{...provider, basePath: "/" + key}`);
    - rende le route annidate: index → `AccountsPage`, `accounts/:accountId/profiles/:gameProfileId/pulls` → `PullsPage`, più `extraRoutes`; `*` → `NotFoundComponent` con `redirectPath={basePath}`.
    La `key={p.key}` su `ProviderScope` è necessaria: fa rimontare tutto il sottoalbero al cambio tab (D10).
13. `MainLayout.jsx`: un tab per ogni provider consentito (label, icona, `pathname_regex` `^/{key}(/|$)`), contenuto via `<Outlet/>`.
14. `BackendApi.js` unico con tutti gli endpoint (diary, verify/resend email e import PowerShell compresi). `BackendApiContext` con default `null` (oggi `{}`, quindi il controllo d'errore in `useBackendApi` non scatta mai). `PowershellPullImportModal` costruisce l'URL dello script da `/api/hoyoverse` invece dell'URL scritto a mano `/api/pulls/script/powershell` (riga 16).
15. Eliminati nei provider: `app.js`, `App.jsx`, `MainLayout.jsx`, `BackendApi.js`, `BackendApiContext.jsx`, `constants.js`. Gli import `@{Provider}Bundle/components/BackendApiContext` diventano `@GamesBundle/components/BackendApiContext`.

### Fase 3 — Unificazione componenti frontend

Per ogni componente: la versione comune nasce dalla più completa delle due, i fallback specifici di un gioco vengono tolti, la navigazione usa path assoluti costruiti con `basePath` (non path relativi, fragili dentro `/*`), e i file duplicati nei provider vengono cancellati. Il descrittore cambia insieme ai componenti: `AccountsPage` e `PullsPage` escono quando diventano comuni, gli altri campi entrano uno alla volta, solo quando un componente comune ne ha bisogno. Forma finale:

```js
export default {
    key, label, icon,
    AddAccountModal,                          // componente del provider
    AccountStatusTags,                        // tag di stato nella AccountCard
    getReloginState: (account) => ({ highlight, danger }),
    profileSettings: { defaults: { /* campo: valore */ }, Sections: ProfileSettingsSections },
    pullsExtraImporters: [{ key: "powershell", label, icon, Modal: PowershellPullImportModal }],   // solo Hoyoverse
    extraRoutes: [ /* diary */ ],             // solo Hoyoverse
};
```

1. **Pulls** (`PullsMonthlyChart`, `PullsBannerCard`, `PullsBannerDetail`):
   - fallback neutri ("Pulls"/"Pull", pity preso sempre da `banner.s_max_pity`, `rarity_config` sempre presente dal backend);
   - `PullsBannerDetail` usa `gacha_name` (esposto da entrambi i repository) e mostra `item_type`, come oggi in Hoyoverse;
   - **backend:** `GryphlinePullRepository` (righe 264 e 291) oggi mette in `item_type` il `poolCategory` grezzo, che apparirebbe come "(char)": espone invece un'etichetta leggibile (`char` → `Operator`, `weapon` → `Weapon`);
   - il testo di ricerca diventa generico ("Search item...").
2. **`PullsPage`**:
   - breadcrumb e "indietro" da `useGameProvider()` (`label`, `basePath`); tolto il fallback `"Arknights: Endfield"` nel breadcrumb;
   - il pulsante di sync usa il nuovo flag `profile.supports_pull_sync`;
   - le voci extra del menu Import arrivano da `provider.pullsExtraImporters`, ciascuna col proprio modal; il divisore compare solo se ci sono sia voci extra sia importer di backup;
   - layout banner `xs={24} md={12} xl={8}`.
3. **Normalizer (backend)**: in `HoyoverseGameProfileNormalizer` `supports_stoken_pulls` diventa `supports_pull_sync`; `GryphlineGameProfileNormalizer` aggiunge `supports_pull_sync` (`HasAutoPullImportInterface`). `supports_pulls` significa per entrambi "ha il tracker". Test dei normalizer aggiornati.
4. **`AccountsPage`**:
   - testi costruiti da `label` ("{label} Accounts", "Manage your {label} credentials…", "No {label} accounts found");
   - apre `provider.AddAccountModal` e il drawer comune;
   - lo stato selezionato è `{ accountId, profileId }` e l'account si ricava dalla lista, così non si usa una copia che diventa vecchia dopo `loadAccounts`.
5. **`AccountCard`**: struttura comune; i tag di stato sono `<provider.AccountStatusTags account={account}/>`; l'evidenziazione del pulsante Re-login viene da `provider.getReloginState(account)`.
6. **`GameProfileCard`**: unione dei tag, ognuno già condizionato da `"campo" in profile`:
   - Check-In per `hoyolab_check_in` o `skport_check_in`;
   - Codes (con avviso "No Token" se `!account.can_redeem_codes`);
   - Stamina, Teapot, Dailies, Weeklies, Diary Sync, con colori tutti distinti (oggi Teapot e Dailies sono entrambi `purple`, Diary e Weeklies entrambi `geekblue`; nella card Hoyoverse Dailies/Weeklies prima non comparivano).
   Azioni: diario se `"sync_diary" in profile`, pulls se `supports_pulls`, link esterno se `hoyolab_url`, impostazioni. Fallback immagine con `onError` per entrambi.
7. **`GameProfileSettingsDrawer`** (struttura comune):
   - caricamento del profilo;
   - header (avatar con fallback, nickname o `UID …`, livello);
   - dati del profilo (regione con fallback `server_id`);
   - switch Active con avviso "Suspended" dal testo generico ("All background tasks for this profile are bypassed…");
   - piattaforme di notifica;
   - salvataggio, con chiave del messaggio `${provider.key}-profile-settings-saving`.
   Il provider fornisce `profileSettings.defaults` (l'attuale lista `checkAndSet`) e `profileSettings.Sections`, renderizzato dentro il `Form` con `{ supports, hasAny, isProfileActive, profile, account }`. Le sezioni di Hoyoverse (check-in e codici, diario, pulls, stamina ed expedition, realm currency, dailies e weeklies, Mimo, Hilichurl) e di Gryphline (check-in, stamina, dailies e weeklies, pulls) restano nei rispettivi bundle. Non facciamo un form generato da schema.
8. **Login**:
   - `useLoginWizard({ api, reloginAccount, onLoginSuccess, onStatus, onReset })` gestisce passo corrente, `loginSessionId`, loading, start/relogin e invio captcha. Espone `{ step, setStep, loginSessionId, loading, run, start, submitCaptcha, reset }`;
   - `run(promise)` gestisce il loading e passa il risultato a `handleStepResult`: `captcha_required` → passo `captcha`, `authenticated` → reset + `onLoginSuccess`, ogni altro status → `onStatus(data)`;
   - il provider usa `onStatus` per i suoi passi (Hoyoverse: `email_verification_required` → passo `email_verification` + cooldown di 60 s) e `onReset` per azzerare i propri form; verify e resend email passano da `run()`;
   - `CaptchaStep` comune: `GeetestCaptchaFrame` più i pulsanti "Back to Credentials" e "Cancel";
   - ogni `AddAccountModal` resta nel proprio bundle con `Modal` + `Tabs` (~30 righe), campi credenziali, tab incolla (parser cookie o token) e passi extra.
9. **Diario** e **PowerShell**: restano in Hoyoverse; cambiano solo gli import (`useBackendApi`, `useGameProvider`).

### Fase 4 — Unificazioni backend a basso costo

1. **Configurazione dei task**: `App\GamesBundle\DependencyInjection\GameTasksConfiguration` con due metodi statici, usati da `HoyoverseBundle` e `GryphlineBundle`:
   - `addTaskNode(NodeBuilder $tasks, string $name, string $messageClass, bool $regional = false, ?int $jitter = null)` (l'attuale closure `$addCronNode`);
   - `registerTaskParameters(ContainerConfigurator $c, string $prefix, array $tasks)` (validazione `class_exists` / `TaskMessageInterface` / `RegionalTaskMessageInterface` più parametri `{prefix}.tasks` e `{prefix}.task.{name}.{option}`).
   La definizione dei task di ciascun provider resta nel proprio `configure()`.
2. **Scheduler**: `App\GamesBundle\Scheduler\AbstractTaskScheduleProvider`:
   - il costruttore riceve solo `ParameterBagInterface`;
   - `getSchedule()` contiene la logica attuale e legge `{key}.tasks` e `{key}.timezones` da `getProviderKey()`, che dà anche il transport del `RedispatchMessage`;
   - nei provider resta `#[AsSchedule('hoyoverse')] final class ScheduleProvider extends AbstractTaskScheduleProvider` con il solo `getProviderKey()`, senza costruttore.
   Verifica: `debug:scheduler` identico alla baseline.
3. **Geetest**:
   - `App\GamesBundle\Controller\GeetestController::geetestScript()` su `/api/geetest-script/{version}` (nome `games_geetest_script`, `ROLE_USER_GAMES`): non dipende dal provider. Le due route `login/geetest-script/{version}` dei provider vengono tolte;
   - `GeetestService::createFrameResponse(array $captchaConfig, int $defaultVersion): Response` costruisce l'URL dello script (`UrlGeneratorInterface`, route `games_geetest_script`), usa `renderFrameHtml()` e mette gli header attuali (`Content-Type`, `Referrer-Policy`, `Cache-Control`);
   - l'azione `geetestFrame` di ogni provider resta di poche righe: legge la config del captcha dalla propria sessione di login e restituisce `createFrameResponse($config, 3)` per Hoyoverse e `createFrameResponse($config, 4)` per Gryphline (default attuali).
4. **`LoginStepResult`**: `App\GamesBundle\Model\Auth\LoginStepResult` sostituisce `HoyoverseLoginStepResult` e `GryphlineLoginStepResult`. Account tipizzato `?GameAccountInterface` (implementata da entrambe le entità), costanti di stato comprese `STATUS_EMAIL_VERIFICATION_REQUIRED`. I `jsonStepResult()` dei controller restano dove sono (3 righe), tipizzati sulla nuova classe. Il JSON in uscita non cambia.

### Fase 5 — Infrastruttura e documentazione

- `scripts/setup-server.sh`: in `SUBDOMAINS` i due sottodomini diventano `games`. Lo script deriva da qui anche gli host e le origini CORS di Mercure, quindi per le nuove installazioni basta questo.
- **Server esistente, Mercure**: nel Caddyfile vanno aggiornate a mano `cors_origins` e `publish_origins` (via `https://hoyoverse.<domain>` e `https://gryphline.<domain>`, dentro `https://games.<domain>`), poi va ricaricato Caddy. **Non** rilanciare `setup-server.sh`: rigenera le chiavi JWT di Mercure. Senza questa modifica l'`EventSource` (con `withCredentials`) viene bloccato e l'avanzamento ripiega in silenzio sul polling.
- Aggiornati:
  - `docs/SERVER_SETUP_AND_DEPLOY.md`, `docs/README.md`, `README.md`, `docs/CREATING_A_BUNDLE.md` (dove citano i sottodomini o i bundle giochi);
  - `src/HoyoverseBundle/README.md` e `src/GryphlineBundle/README.md`, che citano anche `public/media/cache/core_pull_item_icon` (Hoyoverse riga 222, Gryphline riga 135);
  - nuovo `src/GamesBundle/README.md` (cosa contiene, come aggiungere un provider).
- Dev: `games.valdi.wip` va aggiunto a hosts, vhost, certificato e origini CORS dell'hub Mercure locale.

## 6. Test

**Aggiornati**
- Namespace delle classi spostate (Fase 1) e URL atteso del filtro Liip in `PullItemIconComponentsTest`.
- `LoginStepResult` nei test di controller e servizi auth di entrambi i provider.
- Test dell'auth controller sulla nuova `createFrameResponse()`.
- `getProfileTopic` in `GryphlinePullServicesTest`.
- Path `/api/...` nei test controller (dove il path conta).

**Nuovi**
- `tests/GamesBundle/Routing/GamesRoutingTest` (`KernelTestCase`, router reale). In `test` `.env.local` non viene caricato e `DOMAIN_NAME` vale quello di `.env`, quindi l'host si legge dal parametro `games.domain_name` e si imposta con `router->getContext()->setHost()`, insieme al metodo HTTP. Si usano route GET:
  - `/api/hoyoverse/accounts` → `hoyoverse_api_hoyoverse_accounts_get`;
  - `/api/gryphline/accounts` → `gryphline_api_gryphline_accounts_get`;
  - `/hoyoverse/accounts/1/profiles/2/pulls` e `/gryphline` → `games_index`;
  - `/api/geetest-script/v4` → `games_geetest_script`;
  - `/api/hoyoverse/pulls/script/powershell` → route PowerShell;
  - `/api/accounts` → nessuna route.
- `GameProviderAccess`: con authorization checker mockato restituisce solo i provider consentiti, nell'ordine di config.
- `AbstractPullOperationTracker`: lo stesso `profileId` produce topic diversi per `hoyoverse` e `gryphline`.
- `GameTasksConfiguration`: classe inesistente o interfaccia sbagliata → `InvalidConfigurationException`; parametri appiattiti corretti.
- `AbstractTaskScheduleProvider`: task globali e regionali generano i `RecurringMessage` attesi (cron, timezone, transport).
- `GeetestService::createFrameResponse()`: versione scelta dalla config o dal default, URL dello script e header.
- Normalizer: `supports_pull_sync` per entrambi i provider.

**Verifica manuale della sicurezza** (non copribile senza DB di test):
- anonimo su `games.valdi.wip` → login; utente senza `ROLE_USER_GAMES` → 403;
- utente con solo `ROLE_USER_GRYPHLINE`: vede solo il tab Gryphline; `/hoyoverse/...` → 404 della SPA; le API `/api/hoyoverse/...` → 403;
- `/api/hoyoverse/pulls/script/powershell?gameId=…` raggiungibile senza login.

**Frontend** (nessuna infrastruttura di test JS): `npm run build` senza errori, poi verifica manuale su `games.valdi.wip` per **entrambi** i provider:
- tab visibili in base ai ruoli e redirect di `/`;
- cambio tab con un drawer o un modal aperto (deve ripartire pulito);
- reload su un link profondo (pagina pulls e diario), tasto indietro;
- `/foo` e `/hoyoverse/foo` → 404 della SPA;
- lista account, aggiunta e re-login (con captcha; verifica email e reinvio per HoYoverse);
- drawer impostazioni salva;
- pagina pulls (overview, dettaglio banner con `item_type` leggibile, sync, import backup, import PowerShell, progress live via Mercure senza ripiegare sul polling);
- due schede del browser su provider diversi con un'operazione in corso (il cookie Mercure si riscrive a ogni overview: comportamento già presente tra profili dello stesso provider, da osservare);
- diario HoYoverse.

## 7. Deploy

1. `git pull`, `composer install` (autoload), `npm ci && npm run build`.
2. Fermare i worker e svuotare le code Messenger.
3. `bin/console doctrine:migrations:migrate` (ruolo `ROLE_USER_GAMES`).
4. `bin/console cache:clear` e `bin/console cache:pool:clear core.security_hierarchy.cache` (obbligatorio: la gerarchia ruoli sta in `var/share`, che `cache:clear` non svuota).
5. DNS, certificato e vhost per `games.<domain>`; rimozione di `hoyoverse.<domain>` e `gryphline.<domain>`.
6. Caddyfile di Mercure: aggiornare `cors_origins` e `publish_origins` con `https://games.<domain>` e ricaricare Caddy (vedi Fase 5; non rilanciare `setup-server.sh`).
7. Riavvio dei worker (supervisor invariato).
8. Facoltativo: cancellare `public/media/cache/core_pull_item_icon`.

## 8. Rischi e mitigazioni

| Rischio | Mitigazione |
|---|---|
| Riferimenti ai vecchi namespace o nomi di servizio (`#[Target]`, tag) dimenticati | elenco esplicito in Fase 1, `grep` sui FQCN e sui nomi `core.*` spostati, `lint:container`, test completi |
| Collisioni di route sul nuovo host | `GamesRoutingTest` + confronto `debug:router` con baseline |
| Aggiornamenti Mercure ricevuti da profili di un altro provider | topic con provider + test dedicato |
| Progress live bloccato da CORS sul nuovo host | aggiornamento del Caddyfile nel deploy + voce in checklist |
| Stato del provider precedente visibile dopo il cambio tab | `ProviderScope` con `key` + voce in checklist |
| Utente senza `ROLE_USER_GAMES` dopo il deploy | gerarchia in migration; `cache:pool:clear core.security_hierarchy.cache` obbligatorio (`cache:clear` non basta); verifica con un utente che ha solo `ROLE_USER_GRYPHLINE` |
| Scheduler cambiato per errore nella Fase 4 | confronto `debug:scheduler` / `debug:messenger` con la baseline della Fase 0 |
| Regressioni UI senza test JS | build più checklist manuale (§6) ripetuta per entrambi i provider; un commit per componente in Fase 3 |
| Differenze di comportamento perse unificando i componenti | elencate in Fase 3 (`item_type`, avviso Suspended, chiave messaggio, colori dei tag, fallback breadcrumb) |
| Miniature delle icone rigenerate (rename del filtro Liip) | effetto solo sulla prima richiesta; cache binaria già presente |
| Comandi PowerShell copiati in passato con il vecchio URL | accettato: il modal genera sempre il comando aggiornato |
| File non committati nei bundle (`GryphlineCheckInCommand`, …) | inclusi nella riscrittura dei namespace; la modifica in corso su `FileManagerTrait` non viene toccata |

## 9. Fuori scope (da valutare più avanti)

- `AbstractBatchTaskMessageHandler` comune: saldo di righe circa zero, rischio con le proprietà `readonly` promosse ridichiarate, e `CodeRedeemBatchHandler` ha un costruttore proprio.
- `AbstractProfileTaskMessageHandler` e la logica degli handler di reminder (Dailies/Weeklies/Stamina/Check-in): unificabili con 4 punti di estensione (ricerca del servizio per `gameId`/`gameBiz`, dati runtime con cookie, contesto delle eccezioni, icona di fallback).
- Endpoint pulls di `ApiGameProfilesController`, `PullBackupImportService`, handler di sync e import: stessa struttura, dettagli diversi.
- Interfacce e manager di importer e reconciler: richiederebbero di tipizzare su interfacce comuni.
- Un modal di login comune a slot: `useLoginWizard` + `CaptchaStep` coprono la parte che vale la pena condividere.
- Nomi dei campi check-in (`hoyolab_check_in` / `skport_check_in` → `check_in`): richiederebbe di rinominare colonne DB.

## 10. Esito della revisione

Il piano è stato revisionato da due agenti indipendenti, uno sul backend e uno sul frontend, verificando sul codice. Nessun errore bloccante. Modifiche integrate:

- **Correzioni:**
  - CORS dell'hub Mercure nel deploy;
  - elenco esplicito di `#[Target]`, tag e stringhe da rinominare in Fase 1;
  - route PowerShell sul metodo;
  - `ProviderScope` con `key`;
  - layout route con 404 di primo livello e gestione dell'utente senza provider;
  - `BackendApiContext` con default `null`;
  - regola sugli import di `providers.js`;
  - differenze di comportamento da preservare in Fase 3;
  - callback `onStatus`/`onReset` in `useLoginWizard`;
  - `GameProviderAccess` testabile;
  - accortezze per `GamesRoutingTest`;
  - verifiche manuali di sicurezza e checklist estesa.
- **Semplificazioni:**
  - tolto l'handler batch comune;
  - niente modal di login a slot (solo `useLoginWizard` + `CaptchaStep`);
  - niente trait nei controller (la risposta del frame Geetest è in `GeetestService`);
  - `AbstractTaskScheduleProvider` col solo parameter bag;
  - descrittore senza `texts` e `apiUrl`, che evolve fase per fase.
