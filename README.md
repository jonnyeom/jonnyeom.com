[Check it out](https://www.jonnyeom.com/)!

### Built with

* [Symfony](https://symfony.com/doc/current/index.html)
* [Bulma](https://bulma.io/documentation)
* [Symfony Reprise](https://github.com/symfony/reprise) 1.3 with Vite

### Local development

Use PHP 8.4 with the extensions required by Composer, Node 22.22.3, and pnpm 11.7.0.
Install PHP dependencies first: the Symfony UX JavaScript packages are loaded from `vendor/`.

```sh
composer install
pnpm install --frozen-lockfile
pnpm build
```

Configure the PostgreSQL connection in `.env.local` and apply migrations with
`php bin/console doctrine:migrations:migrate`.

Run the built in dev server.

```
symfony serve
```

In another terminal, start the Vite dev server with HMR:

```sh
pnpm dev
```

Reprise integrates Vite with Twig and registers the Stimulus controllers in
`assets/controllers.json` and `assets/controllers/`. React components in
`assets/react/controllers/` are registered through Symfony UX React.

For production assets, stop the dev server and run `pnpm build`. Reprise writes
`public/build/entrypoints.json` and `public/build/manifest.json`; Twig uses
`reprise_entry_script_tags()` and `reprise_entry_link_tags()` to render the entries.
The `submodulePost` entry builds the blog stylesheet without a JavaScript file.

Run the application tests after building assets:

```sh
vendor/bin/phpunit
```

Optimize images for project images

#### `./node_modules/imagemin-cli/cli.js --plugin.pngquant.quality={0.6,0.8} src/images/projects/* --out-dir=public/images/projects`

This will optimize images for project images
