[Check it out](https://www.jonnyeom.com/)!

### Built with

* [Symfony](https://symfony.com/doc/current/index.html)
* [Bulma](https://bulma.io/documentation)

### Available scripts

Run the built in dev server.

```
symfony serve
```

Vite dev server with HMR

#### `yarn dev`

Vite Build

#### `yarn build`

Optimize images for project images

#### `./node_modules/imagemin-cli/cli.js --plugin.pngquant.quality={0.6,0.8} src/images/projects/* --out-dir=public/images/projects`

This will optimize images for project images

## Heroku ➜ Render migration checklist

1. Create a **Web Service** in Render using this repository and set Runtime to **Docker** (Render will use `Dockerfile`).
2. Set required environment variables in Render:
   * `APP_ENV=prod`
   * `APP_SECRET`
   * `DATABASE_URL` (Render PostgreSQL Internal URL)
   * Any third-party API keys currently in Heroku config vars (e.g. Strava credentials).
3. Add a managed PostgreSQL instance in Render, then run migrations:
   * `php bin/console doctrine:migrations:migrate --no-interaction`
4. Confirm Symfony cache/log directories are writable (`var/cache`, `var/log`) and app boot succeeds.
5. Verify front-end assets are built (`public/build`) from the Docker build stage.
6. Configure health checks in Render (for example `/`).
7. Point your custom domain to Render and wait for TLS provisioning.
8. Run smoke tests:
   * Homepage loads
   * Blog routes load
   * API endpoint(s) respond
   * Strava connect flow works
9. Keep Heroku app running during DNS propagation for rollback safety.
10. After successful cutover and monitoring window, decommission Heroku resources.

## Deploying on Render with Docker

This repository now includes a production Dockerfile suitable for Render.

### Local build/run

```bash
docker build -t jonnyeom-render .
docker run --rm -p 10000:10000 \
  -e PORT=10000 \
  -e APP_ENV=prod \
  -e APP_SECRET=change-me \
  -e DATABASE_URL=postgresql://app:pass@host:5432/app?serverVersion=15&charset=utf8 \
  jonnyeom-render
```

Then visit `http://localhost:10000`.
