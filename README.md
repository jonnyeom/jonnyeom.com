# jonnyeom

My Upgraded Website! (WIP)

## Development Roadmap

### Features
* ~~A live Homepage!~~
* ~~In Progress Pages!~~
* ~~Individual Blog Pages~~
* ~~/blog page~~
* ~~A working mobile menu~~
* About Page
* Cleanup
* Real Post

## Built With

* [Symfony 4](https://symfony.com/doc/current/index.html) - The web framework used
* [Bulma](https://bulma.io/documentation) - The frontend framework used
* [Webpack!](https://webpack.js.org/concepts) - For building assets

## Authors

* **Jonathan Eom**

<br>
<br>

## Local Development Notes

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes.  
See the Development Roadmap for notes on features to come.

### Prerequisites

* [Composer](https://getcomposer.org/)
* [NPM (or any Node package manager)](https://getbootstrap.com/docs)

### Installing

#### Minimal Setup

Install PHP Dependencies.

```
composer install
```

Run the built in dev server.

```
./bin/console server:run
```

Go to http://127.0.0.1:8000 in your browser.

#### Full Setup

Setup your .env file.

```
cp .example.env.local .env.local
```

You can now setup your database, run the migrations and have this app up and running.

## Frontend Assets | Available Scripts

Install Node Dependencies.

```
npm install
```

In the project directory, you can run:

### `npm run dev`

This will recompile the frontend assets and watch for sass file changes.

### `npm run build`

This will recompile the fronend asssets, optimized for production.

### `This will generate thumbnails for project images`

This will generate thumbnails for project images
