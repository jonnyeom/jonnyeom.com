---
title: 'Example script to run PHP CodeSniffer for only changed files'
description: An example script that runs PHP CodeSniffer for only the changed files in a branch. Includes a sample gitlab-ci job to run the script.
date: March 17, 2022
slug: 'example-script-to-run-phpcs-on-only-changed-files'
tags:
- code-example
- ci
---

## The Script
### What the Script does
- Gets changed files, comparing the current branch to the `origin/develop` branch.
- Checks for changes only in the `web/themes` or `web/modules` directories. To change this behavior, change the
  argument for `grep` below
- Runs `phpcs` with the `Drupal` standard.

```bash
# phpcs-check-changed.sh

#!/bin/bash

echo 'Printing phpcs warnings for only Changed Files';

# Gitlab-CI passes the current commit hash has as $CI_COMMIT_SHORT_SHA
# @Todo Get the current commit hash yourself if not using Gitlab-CI.
changed_files=$(git diff --name-only --diff-filter=d origin/develop..$CI_COMMIT_SHORT_SHA | grep -E "web/themes|web/modules")

if [[ -z $changed_files ]]
then
 echo "There are no files to check."
 exit 0
fi

./vendor/bin/phpcs --colors --standard=Drupal --extensions=php,module,inc,install,test,profile,theme $changed_files
```

## Sample Gitlab CI Job to run the script
```yaml
# gitlab-ci.yml

...

phpcs-warnings:
  stage: Check
  script:
    - scripts/gitlab-ci/phpcs-check-changed.sh
```
