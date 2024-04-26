#!/bin/bash

# See https://elements.heroku.com/buildpacks/weibeld/heroku-buildpack-run.

echo "Compiling assets to public"
php bin/console asset-map:compile
php bin/console debug:asset-map
