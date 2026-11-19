## Server Setup (Caddy + Mercure + PHP 8.5)

L'intero stack web (PHP-FPM 8.5, estensioni, Composer, FFmpeg/yt-dlp, Caddy con supporto nativo Mercure Hub, certificati TLS, servizio `systemd` e permessi con ereditarietà POSIX ACL) viene configurato tramite lo script automatizzato:

```bash
sudo ./scripts/setup-server.sh
```

### Cosa configura lo script
* **PHP-FPM & CLI (`8.5`):** installa `php8.5-fpm`, `php8.5-cli` e le estensioni richieste (`ldap`, `imap`, `readline`, `imagick`, `mbstring`, `mysql`, `curl`, `zip`, `xml`, `intl`), imposta la CLI predefinita e crea `/etc/php/8.5/{fpm,cli}/conf.d/99-custom.ini` con `date.timezone = Europe/Rome`, coordinate locali, `memory_limit = 4G` e `max_execution_time = 300`.
* **Strumenti esterni:** installa o aggiorna `composer` in `/usr/local/bin/composer`, `ffmpeg`/`ffprobe` e `yt-dlp` in `/usr/local/bin/yt-dlp`.
* **Caddy + Mercure Hub:** scarica il binario `mercure` in `/var/www/caddy/mercure`, assegna `CAP_NET_BIND_SERVICE` per le porte `80`/`443`, genera le chiavi JWT HS256, scrive `/var/www/caddy/Caddyfile` (con supporto `X-Sendfile` e domini/sottodomini `valdi.ovh`, `mercure`, `books`, `anime`, `hoyoverse`, `gryphline`, `videos`) e registra `/etc/systemd/system/caddy.service`.
* **Permessi progetto:** imposta `davide:www-data`, bit `setgid` (`g+s`) sulle directory e default POSIX ACL (`setfacl`) su `/var/www/valdi.ovh`.

### Gestione e manutenzione rapida
* **Stato e log Caddy/Mercure:**
  ```bash
  sudo systemctl status caddy
  tail -f /var/log/caddy.log
  ```
* **Ricaricare la configurazione del Caddyfile:**
  ```bash
  sudo systemctl reload caddy
  ```

---

## Setup Shared Folder (Samba / Media / Backups)

Per configurare una cartella condivisa tra utente locale, `root`, `www-data` (PHP-FPM/Symfony), `plex` e Samba con ereditarietà automatica dei permessi (preservando i permessi di esecuzione sugli script `.sh`):

```bash
sudo ./scripts/setup-shared-folder.sh /media/xyz
```

Lo script crea il gruppo `condivisi` (se assente), associa gli utenti `root`, `www-data`, `plex` e l'utente corrente, applica `2775` (`setgid`) sulle directory, `0664` sui file normali, `0775` sugli script `.sh`, imposta le POSIX ACL di default e propone l'aggiunta del blocco di condivisione in `/etc/samba/smb.conf`.

### Esempio configurazione Samba (`/etc/samba/smb.conf`)
```ini
[xyz]
   comment = xyz
   path = /media/xyz
   browsable = yes
   writable = yes
   guest ok = no

   force group = condivisi
   create mask = 0664
   directory mask = 2775
   inherit permissions = yes
```

---

## Deploy script
- File [website-deploy.sh](scripts/website-deploy.sh)
- Add `YOURUSER ALL=(ALL) NOPASSWD:/usr/bin/supervisorctl` to `sudo visudo` for github actions

## Services
- Install supervisor `sudo apt install supervisor`
- Copy service files in `/etc/supervisor/conf.d/`
  - [website-node-services](scripts/website-node-services.conf)
  - [website-anime-download](scripts/website-messenger-anime-episode-download.conf)
  - [website-messenger-core](scripts/website-messenger-core.conf)
  - [website-scheduler-default](scripts/website-scheduler-default.conf)
- Replace `YOURUSER` and `/path/to/your/app`
- Enable and start services with `sudo supervisorctl reread && sudo supervisorctl update`

---

## Create a new Bundle

### Create the following structure
```
src/CoreBundle/
    assets/
        images/
    config/
        packages/
            twig.yaml
        routing.yaml
        services.yaml
    public/
    src/
        Command/
        Controller/
        Entity/
        Exception/ [optional]
        Message/ [optional]
        MessageHandler/ [optional]
        Model/
        Normalizer/ [optional]
        Repository/
        Security/ [optional]
        Service/ [optional]
    templates/
    tests/
    translations/
```

### Create the bundle file `src/CoreBundle/CoreBundle.php`
```php
<?php

namespace App\CoreBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class CoreBundle extends AbstractBundle
{

    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Load configurations
        $configurator->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Load parameters
        $configurator->parameters()->set('core.some_param', $config['some_param']);
        // Load services
        $configurator->import('./config/services.yaml');
    }

    // Custom configuration
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('some_param')->defaultNull()->end()
            ->end();
    }

}
```

### Create the configuration file `src/CoreBundle/config/services.yaml` to enable bundle services and autowiring
```yaml
services:
    # default configuration for services in *this* file
    _defaults:
        autowire: true      # Automatically injects dependencies in your services.
        autoconfigure: true # Automatically registers your services as commands, event subscribers, etc.

    # makes classes in src/ available to be used as services
    # this creates a service per class whose id is the fully-qualified class name
    App\CoreBundle\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Model/'
            - '../src/Kernel.php'

    # add more service definitions when explicit configuration is needed
    # please note that last definitions always *replace* previous ones
```

### Add to `composer.json`
```json
{
    "autoload": {
        "psr-4": {
            "App\\CoreBundle\\": "src/CoreBundle/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\CoreBundle\\Tests\\": "src/CoreBundle/tests/"
        }
    }
}
```

### Add to `config/bundles.php` to enable the bundle
```php
<?php

return [
    App\CoreBundle\CoreBundle::class => ['all' => true],
];
```

### Create the configuration file `config/packages/core.yaml` to implement bundle config
```yaml
core:
    domain_name: 'core.%domain_name%'
```

### Create the configuration file `config/routes/core.yaml` to implement bundle routes
```yaml
core:
    resource: '@CoreBundle/config/routing.yaml'
```

### Create the configuration file `src/CoreBundle/config/packages/twig.yaml` to implement templates
```yaml
twig:
    globals:
        core_domain_name: '%core.domain_name%'
```

### Add to `webpack.config.js`
```javascript
Encore
    .addAliases({
        '@CoreBundle': path.resolve(__dirname, 'src/CoreBundle/assets/'),
    })
    .copyFiles([
        {from: path.resolve(__dirname, 'src/CoreBundle/assets/images/'), to: 'images/[path][name].[hash:8].[ext]', pattern: /\.(png|jpg|jpeg|svg|ico)$/},
    ])
    .addEntry('core', '@CoreBundle/app.js')
```

### Add to `phpstorm.config.js`
```javascript
System.config({
    "paths": {
        "@CoreBundle/*": "./src/CoreBundle/assets/*",
    }
});
```

### Refresh composer
`composer dump-autoload`
