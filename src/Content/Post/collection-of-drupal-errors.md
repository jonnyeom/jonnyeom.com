---
title: Collection of drupal errors
description: A collection of drupal errors I've run into
date: July 30, 2020
tags:
    - Drupal
---

> **RuntimeException: Failed to start the session because headers have already been sent by "/var/www/usni-d8/vendor/symfony/http-foundation/Response.php" at line 377. in Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage->start() (line 145 of /var/www/usni-d8/vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php)**

Solution:  
When returning a Redirect Response, return the response object.

```php
// BAD example.
$response = new RedirectResponse('https://url/path');
$response->send();
```
```php
// GOOD example.
return new RedirectResponse('https://url/path');
```
