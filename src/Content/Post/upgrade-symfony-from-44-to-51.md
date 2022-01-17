---
title: Upgrading my symfony application from 4.4 to 5.1
description: Just a short overview of what I did to update my symfony applications to 5.1
date: July 4, 2020
slug: 'upgrade-symfony-from-44-to-51'
tags:
- Symfony
---


Just a short overview of what I did to update my symfony applications to 5.1

### Update composer dependencies

First, I updated my composer.json according to the instructions [here](https://symfony.com/doc/current/setup/upgrade_major.html#update-to-the-new-major-version-via-composer)

- Changed all `4.4.*` lines to `5.1.*`
- Change the extra.symfony.require line in `composer.json`
    ```json
        "extra": {
            "symfony": {
                "require": "5.1.*"
            }
        }
    ```

- Run the update.
    ```shell
    composer update "symfony/*" --with-all-dependencies
    ```

### Remove symfony/web-server-bundle

During the update, I ran into a version error with the symfony/web-server-bundle package.

Doing some research, It looks like we can now use symfony's cli tool to start local servers, so I simply removed this package and re-ran the update command.

```shell
composer remove symfony/web-server-bundle
composer update "symfony/*" --with-all-dependencies
```

### Replace twig.yml with framework.yml

Reloading the application, I started getting errors with my `config/routes/dev/twig.yaml` file, specifically about the line `@TwigBundle/Resources/config/routing/errors.xml`

To fix this

- Replace the file name to `framework.yml`
- Replace _errors.resource with the updated class
    ```yaml
    # config/routes/dev/framework.yaml

    _errors:
        resource: '@FrameworkBundle/Resources/config/routing/errors.xml'
        prefix: /_error
    ```

### Update reference to new Debug class

I then ran into the error
`Fatal error: Uncaught Error: Class 'Symfony\Component\Debug\Debug' not found in public/index.php:12 Stack trace: #0 {main} thrown in public/index.php on line 12`

To fix this

- Update the `Debug` class
    ```php
    // public/index.php

    ...

    // This used to be "use Symfony\Component\Debug\Debug;"
    use Symfony\Component\ErrorHandler\Debug;

    ...
    ```

### Replace depreciations

This is not required but It is best to move away from depreciated code.

- Update my `configureRoutes()` method in `Kernel.php` to use the new `RoutingConfigurator` class.
    ```php
    // old src/Kernel.php

    use Symfony\Component\Routing\RouteCollectionBuilder;

	...

    protected function configureRoutes(RouteCollectionBuilder $routes): void
    {
        $confDir = $this->getProjectDir().'/config';

        $routes->import($confDir.'/{routes}/'.$this->environment.'/**/*'.self::CONFIG_EXTS, '/', 'glob');
        $routes->import($confDir.'/{routes}/*'.self::CONFIG_EXTS, '/', 'glob');
        $routes->import($confDir.'/{routes}'.self::CONFIG_EXTS, '/', 'glob');
    }
    ```

    ```php
    // new src/Kernel.php

    use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

	...

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('../config/{routes}/'.$this->environment.'/*.yaml');
        $routes->import('../config/{routes}/*.yaml');

        if (file_exists(\dirname(__DIR__).'/config/routes.yaml')) {
            $routes->import('../config/{routes}.yaml');
        } else {
            $path = \dirname(__DIR__).'/config/routes.php';
            (require $path)($routes->withPath($path), $this);
        }
    }
    ```

— and Voila!

My application was updated to the latest stable version without any depreciations!
