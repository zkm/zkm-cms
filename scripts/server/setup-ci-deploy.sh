#!/usr/bin/env bash
# One-time server setup for deploys from GitHub Actions (docs/DEPLOY.md). Runs as root
# on the droplet, given the deploy script and the deploy key's public half:
#
#   sudo bash setup-ci-deploy.sh <path to zkm-deploy> <path to the .pub key>
#
# Safe to run again: it replaces the key and reinstalls /usr/local/sbin/zkm-deploy.
#
# What it sets up:
# - a `deploy` user with no password, whose only SSH key is forced to run
#   `sudo -n /usr/local/sbin/zkm-deploy` (no shell, no forwarding, no pty);
# - a sudoers rule letting `deploy` run exactly that command, with no arguments;
# - /var/lib/zkm-deploy for staging and the last few release tarballs.

fail() { echo "FAILED: $1" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "run as root"
[ -d /var/www/zkm-cms ] || fail "no /var/www/zkm-cms here"
script=${1:?usage: setup-ci-deploy.sh <zkm-deploy> <key.pub>}
pubkey_file=${2:?usage: setup-ci-deploy.sh <zkm-deploy> <key.pub>}
[ -f "$script" ] || fail "no $script"
[ "$(wc -l < "$pubkey_file")" = 1 ] || fail "$pubkey_file should hold exactly one line"
pubkey=$(cat "$pubkey_file")
[[ $pubkey =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+(\ [A-Za-z0-9@._-]*)?$ ]] || fail "$pubkey_file is not an ssh-ed25519 public key"

echo "== deploy user"
if ! id deploy >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash --comment "GitHub Actions deploys (forced command)" deploy || fail "useradd"
fi
passwd -l deploy >/dev/null || fail "passwd -l"
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh || fail ".ssh"
printf 'restrict,command="sudo -n /usr/local/sbin/zkm-deploy" %s\n' "$pubkey" > /home/deploy/.ssh/authorized_keys || fail "authorized_keys"
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys || fail "authorized_keys permissions"
restorecon -R /home/deploy/.ssh || fail "restorecon .ssh"

echo "== /usr/local/sbin/zkm-deploy"
cp "$script" /usr/local/sbin/zkm-deploy.new || fail "copy script"
bash -n /usr/local/sbin/zkm-deploy.new || fail "script has a syntax error"
chown root:root /usr/local/sbin/zkm-deploy.new && chmod 755 /usr/local/sbin/zkm-deploy.new || fail "script permissions"
mv /usr/local/sbin/zkm-deploy.new /usr/local/sbin/zkm-deploy && restorecon /usr/local/sbin/zkm-deploy || fail "install script"

echo "== sudoers"
# "" = no arguments allowed.
printf 'deploy ALL=(root) NOPASSWD: /usr/local/sbin/zkm-deploy ""\n' > /etc/sudoers.d/zkm-deploy.new || fail "write sudoers"
chmod 440 /etc/sudoers.d/zkm-deploy.new || fail "sudoers permissions"
visudo -cf /etc/sudoers.d/zkm-deploy.new >/dev/null || { rm -f /etc/sudoers.d/zkm-deploy.new; fail "sudoers rule did not validate"; }
mv /etc/sudoers.d/zkm-deploy.new /etc/sudoers.d/zkm-deploy && restorecon /etc/sudoers.d/zkm-deploy || fail "install sudoers"

echo "== /var/lib/zkm-deploy"
# 711: deploy can reach its own staging directory inside, but can't list or read the rest.
install -d -m 711 -o root -g root /var/lib/zkm-deploy || fail "work dir"
install -d -m 700 -o root -g root /var/lib/zkm-deploy/releases || fail "releases dir"

echo "== checks"
sudo -l -U deploy | grep -F /usr/local/sbin/zkm-deploy || fail "sudo rule not active"
cut -c1-60 /home/deploy/.ssh/authorized_keys
echo "== done"
