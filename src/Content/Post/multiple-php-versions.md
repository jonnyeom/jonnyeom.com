---
title: 'Reference: Using multiple php versions on Linux'
description: Reference on using multiple php versions on Linux. Manjaro / Arch Linux (php 7 and php 8).
date: February 15, 2021
last-updated: true
tags:
- php
---

# The end goal
- Have multiple versions of php installed.
- Use php 8.x when you run `php`
- Use php 7.x when you run `php7` .


## The Steps
- Install both default php package and older php7.4 package. The [ArchWiki](https://wiki.archlinux.org/index.php/PHP) will always have the latest instructions but this what I did at the time.
    ```sh
    # install php package (defaults to version 8)
    pamac install php

    # install older php 7.4
    pamac install php74
    ```
- Setup symlinks to use the wanted versions from terminal.
    ```sh
    # setup php 8 as the version to be used when you run "php8"
    sudo ln -s /usr/bin/php /usr/local/bin/php

    # setup php 7.4 as the default version to be used
    sudo ln -s /usr/bin/php74 /usr/local/bin/php7
    ```

## The Result
- **Output of `php -v`**
    ```sh
    > php -v
    PHP 8.1.1 (cli) (built: Jan  8 2022 08:25:03) (NTS)
    Copyright (c) The PHP Group
    Zend Engine v4.1.1, Copyright (c) Zend Technologies
      with Zend OPcache v8.1.1, Copyright (c), by Zend Technologies
    ```

- **Output of `php7 -v`**
    ```sh
    > php7 -v
    PHP 7.4.27 (cli) (built: Dec 16 2021 18:14:46) ( NTS )
    Copyright (c) The PHP Group
    Zend Engine v3.4.0, Copyright (c) Zend Technologies
      with Zend OPcache v7.4.27, Copyright (c), by Zend Technologies
    ```
