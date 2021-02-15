---
title: 
description: Using multiple php versions on Manjaro / Arch Linux (7.4 and 8)
date: February 15, 2021
tags:
    - php
---

#### The end goal
- Have multiple versions of php installed.
- Use php 7.4 as the main version
- Use php 8 when you run `php8` from the terminal.

#### Solution
- Install both default php package and older php7.4 package. The [ArchWiki](https://wiki.archlinux.org/index.php/PHP) will always have the latest instructions but this what I did at the time.
    ```bash
    # install php package (defaults to version 8)
    pamac install php
    # install older php 7.4
    pamac install php74
    ```
- Setup symlinks to use the wanted versions from terminal.
    ```sh
    # setup php 7.4 as the default version to be used
    sudo ln -s /usr/bin/php74 /usr/local/bin/php
    # setup php 8 as the version to be used when you run "php8"
    sudo ln -s /usr/bin/php /usr/local/bin/php8

#### The result
**Output of `php -v`**
> PHP 7.4.15 (cli) (built: Feb 15 2021 16:51:30) ( NTS )  
  Copyright (c) The PHP Group  
  Zend Engine v3.4.0, Copyright (c) Zend Technologies

**Output of `php8 -v`**
> PHP 8.0.2 (cli) (built: Feb  2 2021 18:26:02) ( NTS )  
  Copyright (c) The PHP Group  
  Zend Engine v4.0.2, Copyright (c) Zend Technologies
