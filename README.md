## Apache
sudo a2enmod ssl
sudo a2enmod headers
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod rewrite
sudo a2enmod proxy_fcgi setenvif
sudo a2enconf php8.3-fpm

sudo a2dismod mpm_prefork
sudo a2enmod mpm_event

sudo a2enmod http2

https://gist.github.com/GAS85/38eb5954a27d64ae9ac17d01bfe9898c

## Creazione di un nuovo Bundle

### Creare il bundle con la seguente struttura
```
src/CoreBundle/
    assets/
    config/
        routing.yaml
        services.yaml
    public/
    src/
        Controller/
        Entity/
        Repository/
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

### Aggiungere a `config/packages/assets.yaml`
```yaml
framework:
    assets:
        packages:
            core:
                base_path: '/bundles/core'
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
```

### Aggiungere a `phpstorm.config.js`
```javascript
System.config({
    "paths": {
        "@CoreBundle/*": "./src/CoreBundle/assets/*",
    }
});
```