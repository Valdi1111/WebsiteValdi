## youtube-dlp
* Install ffmpeg and ffprobe `sudo apt install ffmpeg -y`
* Install youtube-dlp
  * `wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp`
  * `chmod a+rx /usr/local/bin/yt-dlp`

## Apache
* `sudo apt install apache2`
* `sudo apt install php8.3-fpm `
* `sudo a2enmod ssl headers proxy proxy_http rewrite proxy_fcgi setenvif`
* `sudo a2enconf php8.3-fpm`
* `sudo a2dismod mpm_prefork`
* `sudo a2enmod mpm_event http2`
* [Http2 tutorial](https://gist.github.com/GAS85/38eb5954a27d64ae9ac17d01bfe9898c)

## Mercure
* Download mercure server from [repository](https://github.com/dunglas/mercure/releases)
* Create and start mercure service
  * `systemctl enable mercure.service`
  * `systemctl start mercure.service`

## Deploy
* Stop all running workers `php bin/console messenger:stop-workers`
* Copy new files
* Install php packages `composer install`
* Clear symfony cache `php bin/console cache:clear`
* Install assets from bundles `php bin/console assets:install`
* Install node packages `npm run build`

## Services
* Command messenger:consume core_async
  * `systemctl enable website-messenger-core.service`
  * `systemctl start website-messenger-core.service`
* Command messenger:consume scheduler_default
  * `systemctl enable website-scheduler-default.service`
  * `systemctl start website-scheduler-default.service`
* Command anime:aw-socket-listener
  * `systemctl enable website-anime-aw-socket.service`
  * `systemctl start website-anime-aw-socket.service`
* Command messenger:consume anime_episode_download (currently unused)
  * `systemctl enable website-anime-episode-download@{1..12}.service`
  * `systemctl start website-anime-episode-download@{1..12}.service`

## Creazione di un nuovo Bundle

### Creare il bundle con la seguente struttura
```
src/CoreBundle/
    assets/
        images/
    config/
        routing.yaml
        services.yaml
    public/
    src/
        Command/
        Controller/ [required]
        Entity/ [required]
        Exception/
        Message/
        MessageHandler/
        Repository/ [required]
        Scheduler/
        Service/
    templates/
    tests/
    translations/
```

### Creare il file del bundle `src/CoreBundle/CoreBundle.php`
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

### Creare il file di configurazione `src/CoreBundle/config/services.yaml`
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

### Aggiungere a `composer.json`
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

### Aggiungere a `config/packages/twig.yaml`
```yaml
twig:
    file_name_pattern: '*.twig'
    paths:
        src/CoreBundle/templates: 'CoreBundle'
```

### Aggiungere a `config/Kernel.php`
```php
<?php

return [
    App\CoreBundle\CoreBundle::class => ['all' => true],
];
```

### Aggiungere a `config/routes.yaml`
```yaml
core:
    resource: '@CoreBundle/config/routing.yaml'
```

### Aggiungere a `config/services.yaml`
```yaml
services:
    App\:
        exclude:
            - '../src/CoreBundle/'
```

### Aggiungere a `webpack.config.js`
```javascript
Encore
    .addAliases({
        '@CoreBundle': path.resolve(__dirname, 'src/CoreBundle/assets/'),
    })
    .copyFiles([
        {from: path.resolve(__dirname, 'src/CoreBundle/assets/images/'), to: 'images/[path][name].[hash:8].[ext]', pattern: /\.(png|jpg|jpeg|svg|ico)$/},
        {from: path.resolve(__dirname, 'src/CoreBundle/assets/'), to: '[path][name].[hash:8].[ext]', pattern: /\.json$/},
    ])
```

### Aggiungere a `phpstorm.config.js`
```javascript
System.config({
    "paths": {
        "@CoreBundle/*": "./src/CoreBundle/assets/*",
    }
});
```