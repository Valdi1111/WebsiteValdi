# Creating a New Bundle

This guide describes the standard structure and steps required to create and register a new Symfony bundle under `src/`. The examples below use `ExampleBundle` (config prefix `example`) as a placeholder name.

---

## 1. Directory Structure

Create the following directory tree under `src/ExampleBundle/`:

```text
src/ExampleBundle/
├── assets/
│   ├── app.js
│   └── images/
├── config/
│   ├── packages/
│   │   └── twig.yaml
│   ├── routing.yaml
│   └── services.yaml
├── public/
├── src/
│   ├── Command/
│   ├── Controller/
│   ├── Entity/
│   ├── Exception/        # [optional]
│   ├── Message/          # [optional]
│   ├── MessageHandler/   # [optional]
│   ├── Model/
│   ├── Normalizer/       # [optional]
│   ├── Repository/
│   ├── Security/         # [optional]
│   └── Service/          # [optional]
├── templates/
├── tests/
├── translations/
└── ExampleBundle.php
```

---

## 2. Bundle Class (`src/ExampleBundle/ExampleBundle.php`)

Extend `AbstractBundle` to automatically load bundle package configs, parameters, and services:

```php
<?php

namespace App\ExampleBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ExampleBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Load internal bundle package configurations
        $configurator->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Expose configured parameters
        $configurator->parameters()->set('example.domain_name', $config['domain_name']);
        // Load bundle services
        $configurator->import('./config/services.yaml');
    }

    // Custom configuration schema
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('domain_name')->defaultNull()->end()
            ->end();
    }
}
```

---

## 3. Internal Bundle Configuration

### Services (`src/ExampleBundle/config/services.yaml`)
Enable autowiring, autoconfiguration, and automatic service registration for classes in `src/`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\ExampleBundle\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Model/'
            - '../src/Kernel.php'
```

### Routing (`src/ExampleBundle/config/routing.yaml`)
```yaml
controllers:
    resource:
        path: ../src/Controller/
        namespace: App\ExampleBundle\Controller
    type: attribute
    name_prefix: example_
    host: '%example.domain_name%'
```

### Twig (`src/ExampleBundle/config/packages/twig.yaml`)
```yaml
twig:
    globals:
        example_domain_name: '%example.domain_name%'
```

*(Optional)* You can also place bundle-specific `doctrine.yaml`, `framework.yaml`, `messenger.yaml`, `monolog.yaml`, or `cache.yaml` files inside `src/ExampleBundle/config/packages/` (following the pattern in `AnimeBundle`, `HoyoverseBundle`, and `GryphlineBundle`).

### Encrypted columns
To store a secret (token, cookie, API key) encrypted at rest, map the field with the `CoreBundle` type:

```php
use App\CoreBundle\Doctrine\EncryptedTextType;

#[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
private ?string $apiToken = null;
```

The entity keeps seeing the plain value. The column is `LONGTEXT`, like `Types::TEXT`: an existing `TEXT` column needs no migration, any other column needs one. Do not use the field in `WHERE`, indexes or unique constraints (the same value encrypts differently each time). `core:database:encrypt` finds the column by itself from the Doctrine metadata. Details: [Encrypted Columns guide](../src/CoreBundle/src/Service/Encryption/README.md).

---

## 4. Application-Level Registration

### 4.1 `composer.json`
Add the PSR-4 autoload entries under `autoload` and `autoload-dev`:

```json
{
    "autoload": {
        "psr-4": {
            "App\\ExampleBundle\\": "src/ExampleBundle/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\ExampleBundle\\Tests\\": "src/ExampleBundle/tests/"
        }
    }
}
```

Refresh the autoloader:
```bash
composer dump-autoload
```

### 4.2 `config/bundles.php`
Enable the bundle:

```php
<?php

return [
    // ...
    App\ExampleBundle\ExampleBundle::class => ['all' => true],
];
```

### 4.3 App Configuration & Routes
- Create `config/packages/example.yaml`:
  ```yaml
  example:
      domain_name: 'example.%domain_name%'
  ```
- Create `config/routes/example.yaml`:
  ```yaml
  example:
      resource: '@ExampleBundle/config/routing.yaml'
  ```

---

## 5. Frontend & IDE Configuration

### 5.1 `webpack.config.js`
Register the bundle alias, static image copy rules, and entry point:

```javascript
Encore
    .addAliases({
        '@ExampleBundle': path.resolve(import.meta.dirname, 'src/ExampleBundle/assets/'),
    })
    .copyFiles([
        {
            from: path.resolve(import.meta.dirname, 'src/ExampleBundle/assets/images/'),
            to: 'images/[path][name].[hash:8].[ext]',
            pattern: /\.(png|jpg|jpeg|svg|ico)$/,
        },
    ])
    .addEntry('example', '@ExampleBundle/app.js')
```

### 5.2 `phpstorm.config.js`
Add the path alias for IDE navigation:

```javascript
System.config({
    "paths": {
        "@ExampleBundle/*": "./src/ExampleBundle/assets/*",
    }
});
```

---

## 6. Subdomain Exposure (Caddy)

If the bundle is served on its own subdomain (e.g. `example.valdi.ovh`), add `"example"` to the `SUBDOMAINS` array in [`scripts/setup-server.sh`](../scripts/setup-server.sh) or update `/var/www/caddy/Caddyfile` manually and reload Caddy (`sudo systemctl reload caddy`).

> **Game provider bundles** (HoYoverse, Gryphline, ...) are not served on their own subdomain: they plug into the unified `games.<domain>` frontend provided by `GamesBundle`. Follow [Adding a game provider](../src/GamesBundle/README.md#3-adding-a-game-provider) instead.
