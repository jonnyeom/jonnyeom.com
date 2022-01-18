---
title: 'Reference: Using multiple php versions on macOS / Linux'
description: Reference on using multiple php versions (php 7 and php 8). macOS and Manjaro / Arch Linux.
date: February 15, 2021
last-updated: true
tags:
- php
---

# The end goal
- Have multiple versions of php installed.
- Use php 7.x when you run `php`
- Use php 8.x when you run `php8` .


# The Steps
## MacOS
Most brew solutions with `link` and `unlink` is not preferred solution.
-  Install both default php package and older php7.4 package.
   ```sh
   # install php package (defaults to version 8)
   brew install php

   # install older php 7.4
   brew install php@7.4
   ```
- Link the php7.4 package as the default
  ```sh
  # Unlink php8.
  brew unlink php

  # Double check php7.4 is linked (this will set php7.4 as the default)
  brew link php@7.4
  ```
- Create a symlink for php 8
  ```sh
  # Creating a symlink in /usr/local/bin
  ln -s /usr/local/opt/php/bin/php php8
  ```
- Remove any manual php `$PATH` setups in your `.bashrc` or `.zshrc` (or equivelent). It may look like this.
  ```sh
  # .bashrc or .zshrc or equivelent

  # Remove this line!
  export PATH="/usr/local/opt/php@7.4/bin:$PATH"
  ```


## Linux (using pamac)
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
    sudo ln -s /usr/bin/php /usr/local/bin/php8

    # setup php 7.4 as the default version to be used
    sudo ln -s /usr/bin/php74 /usr/local/bin/php
    ```

# The Result
- **Output of `php -v`**
    ```sh
    > php -v
    PHP 7.4.27 (cli) (built: Dec 16 2021 18:14:46) ( NTS )
    Copyright (c) The PHP Group
    Zend Engine v3.4.0, Copyright (c) Zend Technologies
      with Zend OPcache v7.4.27, Copyright (c), by Zend Technologies
    ```

- **Output of `php8 -v`**
    ```sh
    > php8 -v
    PHP 8.1.1 (cli) (built: Jan  8 2022 08:25:03) (NTS)
    Copyright (c) The PHP Group
    Zend Engine v4.1.1, Copyright (c) Zend Technologies
      with Zend OPcache v8.1.1, Copyright (c), by Zend Technologies
    ```
