---
title: 'Use multiple php versions on macOS / Linux'
description: Reference on using multiple php versions (php 7 and php 8). macOS and Manjaro / Arch Linux.
slug: 'reference-use-multiple-php-versions'
date: January 10, 2022
last-updated: true
tags:
- php
---

# The end goal
- Have multiple versions of php installed
- Use php 7.x when you run `php`
- Use php 8.x when you run `php8`


# The Steps
## MacOS
I prefer this over brew solutions with `link` and `unlink`.

- Install both the default php package and the older php7.4 package
    ```bash
    # Install php (defaults to version 8).
    brew install php

    # Install older php 7.4.
    brew install php@7.4
    ```
- Make php 7.4 the default
    ```bash
    # Unlink php8.
    brew unlink php

    # Double check php 7.4 is linked (this will set php7.4 as the default)
    brew link php@7.4
    ```
- Create an extra symlink for php 8
    ```bash
    # Create a symlink in /usr/local/bin.
    ln -s /usr/local/opt/php/bin/php /usr/local/bin/php8
    ```
- Remove any manual php `$PATH` setups in your `.bashrc` or `.zshrc` (or equivelent). It may look like this.
    ```bash
    # .bashrc or .zshrc or equivelent

    # Remove this line!
    export PATH="/usr/local/opt/php@7.4/bin:$PATH"
    ```


## Linux (using pamac)
- Install both default php package and older php7.4 package. The [ArchWiki](https://wiki.archlinux.org/index.php/PHP) will always have the latest instructions but this what I did at the time.
    ```bash
    # Install php (defaults to version 8).
    pamac install php

    # Install older php 7.4.
    pamac install php74
    ```
- Setup symlinks.
    ```bash
    # Setup php 8 as the version to be used when you run "php8".
    sudo ln -s /usr/bin/php /usr/local/bin/php8

    # Setup php 7.4 as the default version to be used.
    sudo ln -s /usr/bin/php74 /usr/local/bin/php
    ```

# The Result
- **Output of `php -v`**
    ```bash
    $ php -v
    PHP 7.4.27 (cli) (built: Dec 16 2021 18:14:46) ( NTS )
    Copyright (c) The PHP Group
    Zend Engine v3.4.0, Copyright (c) Zend Technologies
    with Zend OPcache v7.4.27, Copyright (c), by Zend Technologies
    ```

- **Output of `php8 -v`**
    ```bash
    $ php8 -v
    PHP 8.1.1 (cli) (built: Jan  8 2022 08:25:03) (NTS)
    Copyright (c) The PHP Group
    Zend Engine v4.1.1, Copyright (c) Zend Technologies
    with Zend OPcache v8.1.1, Copyright (c), by Zend Technologies
    ```
