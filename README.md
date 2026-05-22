## youtube-dlp
* Install ffmpeg and ffprobe `sudo apt install ffmpeg -y`
* Install youtube-dlp
  * `wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp`
  * `chmod a+rx /usr/local/bin/yt-dlp`

## Apache
* `sudo apt install apache2`
* `sudo apt install php8.4-{common,zip,mysql,mbstring,readline,ldap,xml,opcache,curl,imap,intl,cli,imagick,gd,fpm}`
* `sudo a2enmod ssl headers proxy proxy_http proxy_wstunnel rewrite proxy_fcgi setenvif`
* `sudo a2enconf php8.4-fpm`
* `sudo a2dismod mpm_prefork`
* `sudo a2enmod mpm_event`

### Enable http2
* `sudo a2enmod mpm_event http2`
* [Http2 tutorial](https://gist.github.com/GAS85/38eb5954a27d64ae9ac17d01bfe9898c)

```aiignore
<IfModule http2_module>
    H2Direct on
</IfModule>
```

### Enable mod SendFile
* `sudo apt-get install libapache2-mod-xsendfile`
* `sudo a2enmod xsendfile`

```aiignore
XSendFile On
XSendFilePath /media
ProxyFCGISetEnvIf "true" HTTP_X_SENDFILE_TYPE "X-Sendfile"
```

## Mercure
### Install mercure
* `sudo mkdir mercure_Linux_arm64`
* `sudo wget https://github.com/dunglas/mercure/releases/latest/download/mercure_Linux_arm64.tar.gz`
* `sudo tar -xvzf mercure_Linux_arm64.tar.gz`
* `sudo rm mercure_Linux_arm64.tar.gz`
### Create and start mercure service
* File [mercure.service](scripts/mercure.service)
* `systemctl enable mercure.service`
* `systemctl start mercure.service`
### Edit apache configuration
```aiignore
<Location "/mercure-hub">
    SetEnvIf Origin "^https?://[^/]*(valdi)\.wip" ORIGIN=$0
    Header set Access-Control-Allow-Origin %{ORIGIN}e env=ORIGIN
    Header set Access-Control-Allow-Credentials "true" env=ORIGIN
    Header merge Vary Origin
    
    ProxyPass http://localhost:3000/.well-known/mercure
    ProxyPassReverse http://localhost:3000/.well-known/mercure
</Location>
```

## Deploy
* Pull latest code
* Install PHP dependencies `composer install --no-dev --optimize-autoloader`
* Run Doctrine migrations `php bin/console doctrine:migrations:migrate `
* Clear and warmup cache 
  * `php bin/console cache:clear --env=prod`
  * `php bin/console cache:warmup --env=prod`
* Install Symfony assets `php bin/console assets:install`
* Install Node dependencies (frontend) `npm ci`
* Build frontend `npm run build`
* Install Node dependencies (node-services) 
  * `cd node-services`
  * `npm ci`
  * `npx playwright install chromium`
  * `cd ..`
* Restart node services `sudo supervisorctl restart website-node-services`
* Restart messenger workers `php bin/console messenger:stop-workers`

## Deploy script
* File [website-deploy.sh](scripts/website-deploy.sh)
* Add `YOURUSER ALL=(ALL) NOPASSWD:/usr/bin/supervisorctl` to `sudo visudo` for github actions

## Services
* Install supervisor `sudo apt install supervisor`
* Copy service files in `/etc/supervisor/conf.d/`
  * [website-node-services](scripts/website-node-services.conf)
  * [website-anime-download](scripts/website-anime-download.conf)
  * [website-messenger-core](scripts/website-messenger-core.conf)
  * [website-scheduler-default](scripts/website-scheduler-default.conf)
* Replace `YOURUSER` and `/path/to/your/app`
* Enable and start services with `sudo supervisorctl reread && sudo supervisorctl update`

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

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class CoreBundle extends AbstractBundle
{

    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Load configurations
        $container->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Load parameters
        $container->parameters()->set('core.some_param', $config['some_param']);
        // Load services
        $container->import('./config/services.yaml');
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
    App\AnimeBundle\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
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