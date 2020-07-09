This is an example local playbook for Drupal-VM that accomplishes 2 things.

1. Install zsh inside vagrant.
2. Configure the zsh to go to the configured `ssh_home` when you ssh into the vm.

```yaml
# local.playbook.yml

---
- hosts: all
  become: yes

  vars_files:
    - ../vendor/geerlingguy/drupal-vm/provisioning/vars/main.yml
    - config.yml

  roles:
    - role: gantsign.antigen
      users:
        - username: "{{ ansible_user }}"
          antigen_libraries:
            - name: oh-my-zsh
          antigen_theme:
            name: af-magic
          antigen_bundles:
            # Autosuggestions bundle.
            - name: zsh-autosuggestions
              url: zsh-users/zsh-autosuggestions
  tasks:
    - ping: ~

    - name: Set Zsh SSH home directory.
      lineinfile:
        dest: "/home/{{ drupalvm_user }}/.zshrc"
        state: present
        create: yes
        regexp: "^SSH_HOME="
        line: "SSH_HOME={{ ssh_home }} && [ -e $SSH_HOME ] && cd $SSH_HOME"
      become: no
      when: ssh_home is defined

    - name: Create phpunit symlink.
      file:
        src: "{{ drupal_composer_install_dir }}/vendor/bin/phpunit"
        dest: "/usr/local/bin/phpunit"
        state: link
        force: true
```

```yaml
# local.requirements.yml

---
roles:
  - name: gantsign.antigen
    version: 1.3.2 # Or the current latest version.
```