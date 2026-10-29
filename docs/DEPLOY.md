# Deploying

Releases deploy from GitHub Actions when a `vX.Y.Z` tag is pushed on `main`.
`scripts/deploy.sh` still works for a deploy from your own machine.

## Releasing

```
git switch main && git pull
git tag v1.2.0 && git push origin v1.2.0
```

`.github/workflows/deploy.yml` then:

1. runs the full test workflow (`tests.yml`) on the tag;
2. checks that the tag is `vX.Y.Z` and on `main`, and builds it with `scripts/build-release.sh`
   (the same build as `deploy.sh`: no dev dependencies, the admin app compiled);
3. saves the tarball and its SHA-256 on a GitHub Release, marked pre-release;
4. waits for approval on the `production` environment (Actions → the run → **Review deployments**);
5. sends the tarball over SSH to the server, where `zkm-deploy` installs it;
6. checks that the live home page answers 200 and marks the release as the latest.

On the server, `zkm-deploy` (`scripts/server/zkm-deploy`, installed at `/usr/local/sbin/`):
unpacks the tarball as the unprivileged `deploy` user, checks it has a `RELEASE` file and the app's
key files, and rsyncs it into `/var/www/zkm-cms` with the same rules as `deploy.sh` (zkm:apache,
files 640, directories 750; `.env`, `writable/` and `public/uploads/` untouched apart from the
uploads `.htaccess`). Then it restores SELinux labels, runs migrations and `cache:clear`,
and checks the home page through the local Apache. `/var/www/zkm-cms/RELEASE` names what's live.
Each run is logged to the journal: `journalctl -t zkm-deploy`.

## Rolling back

Actions → **Deploy** → **Run workflow**, and under *Use workflow from* pick the tag to go back to.
That skips the tests and build and redeploys the tarball saved on that tag's release, after
approval as usual.

Without GitHub, on the server: the last five tarballs are in `/var/lib/zkm-deploy/releases/`.

```
sudo /usr/local/sbin/zkm-deploy < /var/lib/zkm-deploy/releases/v1.1.0.tar.gz
```

Rolling back code doesn't undo migrations. If a release added one that the older code can't
live with, roll the database back first (`php spark migrate:rollback`) or restore a backup.

## How the access is limited

The repository is public, so the deploy key is set up to do as little as possible:

- **The key only deploys.** It belongs to a `deploy` user with no password, and its
  `authorized_keys` line is `restrict,command="sudo -n /usr/local/sbin/zkm-deploy"`: whatever the
  client asks to run, the server runs that script, with no shell, pty or port forwarding.
- **sudo allows that one command, with no arguments.** The script reads only the tarball on stdin.
- **The secrets live on the `production` environment**, which only `v*` tags may use and which
  needs your approval. Pull requests, forks and branch pushes can't read them.
- **The host key is pinned** (`DEPLOY_KNOWN_HOSTS`), so the runner won't talk to an impostor.

Someone who stole the key could still deploy their own code to the site; that is what a deploy
key is for. They couldn't get a shell or root.

## One-time setup

Commands are fish. `<server>` is your SSH alias for the droplet, `<droplet IP>` its own address
(not the reserved IP, which moves on a rollback to another droplet), `<owner>/<repo>` this repository.

**1. A key just for deploys**, on your machine:

```
ssh-keygen -t ed25519 -N '' -C zkm-cms-github-deploy -f ~/.ssh/zkm-cms-deploy
```

**2. The server side**, from a checkout of this repository:

```
scp scripts/server/zkm-deploy scripts/server/setup-ci-deploy.sh ~/.ssh/zkm-cms-deploy.pub <server>:/tmp/
ssh -t <server> 'sudo bash /tmp/setup-ci-deploy.sh /tmp/zkm-deploy /tmp/zkm-cms-deploy.pub; rm -f /tmp/setup-ci-deploy.sh /tmp/zkm-deploy /tmp/zkm-cms-deploy.pub'
```

It creates the `deploy` user, its forced-command key, the sudoers rule and `/var/lib/zkm-deploy`,
and ends with `== done`. Run it again to replace the key or to install a changed `zkm-deploy`.

**3. The `production` environment**: you approve each deploy, and only `v*` tags may use it.
`<your user id>` is `gh api user -q .id`.

```
echo '{"reviewers":[{"type":"User","id":<your user id>}],"deployment_branch_policy":{"protected_branches":false,"custom_branch_policies":true}}' | gh api -X PUT repos/<owner>/<repo>/environments/production --input -
gh api -X POST repos/<owner>/<repo>/environments/production/deployment-branch-policies -f name='v*' -f type=tag
```

**4. The secrets**, read straight from files so they never show on screen:

```
gh secret set DEPLOY_SSH_KEY --env production < ~/.ssh/zkm-cms-deploy
gh secret set DEPLOY_HOST --env production --body '<droplet IP>'
ssh-keygen -F '<droplet IP>' | grep -v '^#' | gh secret set DEPLOY_KNOWN_HOSTS --env production
```

The last one copies the host key from your own `known_hosts`, which you've already checked by
connecting. Then delete the private key from your machine (`rm ~/.ssh/zkm-cms-deploy`); GitHub
has the only copy it needs, and a new one is steps 1, 2 and 4 again.

**5. A first release.** Tag the current `main` and approve the run. Its log lists every file
rsync changed. Expect many on the first run (vendor files carry new timestamps), but no
deletions outside code you've removed.

A later `scripts/deploy.sh --go` deletes `RELEASE`, since the build it sends has none: the server
then runs an untagged build.
