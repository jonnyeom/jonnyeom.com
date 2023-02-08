---
title: 'Use multiple php versions on macOS / Linux'
description: Reference how to use multiple php versions (php 7, php7.4 and php 8, php 8.0, php 8.1, php 8.2) on mac / macOS and Linux.
slug: 'reference-use-multiple-php-versions'
date: January 10, 2022
last-updated: true
tags:
- php
---

> Why follow this guide?
> I use php everyday and this is the most simple and flexible solution I found.

# The end goal
- Have multiple versions of php installed
- Use php 8.2 when you run `php`
- Use php 8.1 when you run `php81`
- Use php 8.0 when you run `php80`
- Use php 7.4 when you run `php7`
- (bonus) Run composer with php 8.1 when you run `composer81`
- (bonus) Run composer with php 7.4 when you run `composer7`


# The Steps
## MacOS
I prefer this solution over brew solutions with `link` and `unlink`.

- Install both the default php package 8.1 and the older php7.4 / php8.0 packages
    ```bash
    # Install php (defaults to version 8.2).
    brew install php

    # Install older php 8.0.
    brew install php@8.1

    # Install older php 8.0.
    brew install php@8.0

    # Add brew tap for old php versions.
    brew tap shivammathur/php

    # Install older php 7.4.
    brew install shivammathur/php/php@7.4
    ```
- Make php 8.2 the default
    ```bash
    # Lets start clean and unlink all php versions
    brew unlink php
    brew unlink php@7.4
    brew unlink php@8.0
    brew unlink php@8.1

    # Link php 8.2
    brew link php
    ```

- Create extra symlinks for php 7.4, 8.0, and 8.1
    ```bash
    # Create a symlinks in /usr/local/bin. You will likely have to run sudo.
    ln -s /opt/homebrew/opt/php@8.1/bin/php /usr/local/bin/php81
    ln -s /opt/homebrew/opt/php@8.0/bin/php /usr/local/bin/php80
    ln -s /opt/homebrew/opt/php@7.4/bin/php /usr/local/bin/php7
    ```

- (bonus) Setup composer shortcuts to run composer with specific php versions.
    ```bash
    # .bashrc or .zshrc or equivalent

    alias composer7='php7 /usr/local/bin/composer'
    alias composer81='php81 /usr/local/bin/composer'
    ```

- Remove any manual php `$PATH` setups in your `.bashrc` or `.zshrc` (or equivalent). It may look like this.
    ```bash
    # .bashrc or .zshrc or equivalent

    # Remove any lines like this!
    export PATH="/opt/homebrew/opt/php@8.1/sbin:$PATH"
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
    # Setup php 7 as the version to be used when you run "php7".
    sudo ln -s /usr/bin/php74 /usr/local/bin/php7
    ```

# The Result
- **Output of `php -v`**
    ```bash
    $ php -v
    PHP 8.2.2 (cli) (built: Feb  5 2023 12:38:16) (NTS)
    Copyright (c) The PHP Group
    Zend Engine v4.2.2, Copyright (c) Zend Technologies
    with Zend OPcache v8.2.2, Copyright (c), by Zend Technologies
    ```

- **Output of `php7 -v`**
    ```bash
    $ php7 -v
    PHP 7.4.33 (cli) (built: Jan 21 2023 06:43:54) ( NTS )
    Copyright (c) The PHP Group
    Zend Engine v3.4.0, Copyright (c) Zend Technologies
    with Zend OPcache v7.4.33, Copyright (c), by Zend Technologie
    ```

- **Output of `php80 -v`**
    ```bash
    $ php81 -v
    PHP 8.0.27 (cli) (built: Jan 12 2023 04:00:24) ( NTS )
    Copyright (c) The PHP Group
    Zend Engine v4.0.27, Copyright (c) Zend Technologies
    with Zend OPcache v8.0.27, Copyright (c), by Zend Technologies
    ```

- **Output of `php81 -v`**
    ```bash
    $ php81 -v
    PHP 8.1.15 (cli) (built: Feb  3 2023 12:35:11) (NTS)
    Copyright (c) The PHP Group
    Zend Engine v4.1.15, Copyright (c) Zend Technologies
    with Zend OPcache v8.1.15, Copyright (c), by Zend Technologies
    ```
