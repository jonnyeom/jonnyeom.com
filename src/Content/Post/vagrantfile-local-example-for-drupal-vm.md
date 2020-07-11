---
title: Example local Vagrantfile for Drupal-VM
description: An example Vagrantfile.local file for Drupal-VM
date: July 9, 2020
tags:
    - Drupal
---

When using this, replace `project` with the `vagrant_machine_name` used in your config.yml file for Drupal-VM.

```
  # Vagrantfile.local

  config.vm.define "project" do |project|
    # Lets run a bash command!
    project.trigger.after :up do |trigger|
      trigger.warn = "Copying development settings.local.php."
      trigger.run = {inline: "bash -c 'cp web/sites/default/example-dev.settings.local.php web/sites/default/settings.local.php'"}
    end
  end
```

This uses [Vagrant triggers](https://www.vagrantup.com/docs/triggers) to run commands.
