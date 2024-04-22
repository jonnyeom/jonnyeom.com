#!/bin/bash
echo "Compiling assets to public"
php bin/console asset-map:compile
php bin/console debug:asset-map
