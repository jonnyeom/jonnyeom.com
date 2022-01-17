---
title: Collection of drupal errors
description: A collection of drupal errors I've run into.
date: July 30, 2020
slug: 'collection-of-drupal-errors'
tags:
- Drupal
---


#### Error: Failed to start the session because headers have already been sent
```
RuntimeException: Failed to start the session because headers have already been sent by "/srv/www/project/vendor/symfony/http-foundation/Response.php" at line 377. in Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage->start() (line 145 of /srv/www/project/vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php)
```

#### Solution: Return the response object, instend of `->send()`
When returning a Redirect Response, return the response object.
```php
	// BAD example.
	$response = new RedirectResponse('https://url/path');
	$response->send();

	// GOOD example.
	return new RedirectResponse('https://url/path');
```

<div class="content-spacer"></div>

<div class="box has-background-light is-size-5 has-text-centered has-text-weight-semibold">
<progress class="progress is-danger" max="100">wip</progress>
  note — This collection is WIP
</div>
