# ComplyRobin rotation runner

The rotation runner swaps your API keys when they are due and tells ComplyRobin when it is done. It runs in your own GitHub account (or on your own server, or inside your Laravel app), with your own admin tokens. **Your keys and admin tokens never reach ComplyRobin.** ComplyRobin only says which credential is due and keeps the record of each rotation as evidence.

**Where it makes keys:** OpenAI (service account keys), AWS (IAM user access keys) and Google Cloud (service account keys).

**Where it puts them:** Laravel Cloud secrets, GitHub Actions secrets, AWS Secrets Manager, AWS Parameter Store, Google Secret Manager, a Laravel Forge site's environment, and Vercel environment variables. It can redeploy Laravel Cloud, Forge and Vercel so your app starts using the new key.

Not every provider can be rotated. **Stripe** secret keys can only be rolled in the Stripe Dashboard (Stripe's key API is a private preview open only to accounts Stripe invites), and **GitHub personal access tokens** can only be made by a person on github.com. For GitHub, a GitHub App is the better choice: its tokens last an hour and renew themselves.

## What happens on each run

1. The runner asks ComplyRobin which credentials are due (or which ones someone pressed **Rotate now** on).
2. It asks the provider for a new key for that credential's own service account or IAM user. For OpenAI it makes the service account the first time.
3. It **tries the new key** with one harmless call. A key that doesn't work is deleted again and goes nowhere.
4. It writes the key into each place you listed. Laravel Cloud and GitHub secrets are sealed so only they can open them.
5. It redeploys what you asked it to, and **waits until each deployment has finished**. If one fails, it stops here: the old key keeps working and is not retired.
6. Only then does it retire the older keys, after a grace period (48 hours). OpenAI can set a key to expire, so this happens straight away. AWS and Google Cloud can't, so ComplyRobin asks the runner to switch the old keys off once the 48 hours have passed. Switched-off keys can be switched back on in AWS or Google Cloud if something still needed one; the next rotation deletes them.
7. It tells ComplyRobin how it went: the new key's id and a short hint, never the key.

If any step fails, your old key keeps working, the runner tells ComplyRobin which step failed, and ComplyRobin emails the credential's owner.

A Laravel Cloud or Forge redeploy ships the newest commit on the environment's branch, as a deploy from their dashboards would. A Vercel redeploy rebuilds the current production deployment, so it ships no new code.

## Set up

The quickest way is the setup wizard in ComplyRobin (**Credentials, Rotation runners, Set up a runner**). It shows only the steps for your provider and destination, with every value filled in. This is what it walks you through.

### 1. At the provider

Give each credential **its own** identity at the provider. The runner retires every other key of that identity, so don't share it with anything else.

**OpenAI**

- Create an **admin key** (Organisation settings, Admin keys) with permission to manage API keys. The runner uses it to make and retire the service account's keys.
- That's all. On a credential's first rotation the runner makes a service account just for it in the project you chose, named after the credential (`ComplyRobin CRD-0042`), and its first key is the new key. Your app's current key isn't touched: delete it in OpenAI once the first rotation has worked. (You can choose a service account of your own instead, as long as nothing else uses it.)

**AWS**

- Create an IAM user for this credential, with only the permissions your app needs. AWS lets a user have two access keys: before the first rotation, keep at most one.
- Give the runner AWS access that can run `iam:ListAccessKeys`, `iam:CreateAccessKey`, `iam:UpdateAccessKey` and `iam:DeleteAccessKey` on those users (for example, limited to users under the path `/complyrobin/`). In GitHub Actions the best way is an IAM role GitHub signs in to with OIDC, so there is no long-lived key at all: the workflow below has the step, ready to switch on. Otherwise set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` for the runner. Don't use the user being rotated.
- An AWS key has two parts, the access key ID and the secret. Add a place for each in ComplyRobin.

**Google Cloud**

- Create a service account for this credential, with only the roles your app needs.
- Give the runner access that can manage that service account's keys (the **Service Account Key Admin** role on it). In GitHub Actions the best way is Workload Identity Federation (the workflow below has the step). Otherwise give the runner the JSON key file of an admin service account as `GOOGLE_CLOUD_CREDENTIALS`.
- The new key is the whole JSON key file.

### 2. Where the key goes

| Place | What the runner needs | Redeploys |
| --- | --- | --- |
| Laravel Cloud secret | `LARAVEL_CLOUD_API_TOKEN`: an API token (Organisation settings, API tokens) that can update secrets and start deployments. The runner finds the secret by its name in the environments you chose, and adds it to an environment that doesn't have it yet. | Yes |
| GitHub Actions secret | `GH_SECRETS_TOKEN`: a fine-grained token with **Secrets: read and write** on the repository (and **Environments** for an environment secret). | No: workflows read it on their next run. |
| AWS Secrets Manager | The runner's AWS access, allowed `secretsmanager:PutSecretValue` on the secret. | No: your app reads it the next time it fetches it. |
| AWS Parameter Store | The runner's AWS access, allowed `ssm:PutParameter` (and `kms:Encrypt` with the key, for a customer-managed key). | No |
| Google Secret Manager | The runner's Google Cloud access, with **Secret Manager Secret Version Adder** on the secret. | No |
| Forge site variable | `FORGE_API_TOKEN`: a Forge API token. Only that one line of the site's environment file changes. | Yes |
| Vercel variable | `VERCEL_TOKEN`: a Vercel token with access to the project's team. | Yes, production |

### 3. In ComplyRobin

- Go to **Credentials, Rotation runners** and press **Make it in GitHub**. ComplyRobin makes a private copy of [complyrobin/runner-template](https://github.com/complyrobin/runner-template) in your GitHub account, with the runner's `COMPLYROBIN_TOKEN` already in its secrets. GitHub asks you to let ComplyRobin manage your repositories for this one job; ComplyRobin hands that access back straight away. (Or press **Add runner** and copy the token yourself: it is shown only once.)
- Add the tokens for your services to the repository's secrets (step 4). On its first check-in the runner tests them and sends ComplyRobin the names of what they can see: your OpenAI projects, Laravel Cloud apps and environments, GitHub repositories.
- On the credential, press **Set up automatic rotation** and pick the project and the places the key goes from those lists.

### 4. In GitHub

If ComplyRobin didn't make the repository for you, press **Use this template** on [complyrobin/runner-template](https://github.com/complyrobin/runner-template) and make the new repository **private**. Under **Settings, Secrets and variables, Actions**, add `COMPLYROBIN_TOKEN` (unless ComplyRobin put it there) and the secrets for the services you use (see the table below). Nothing in the workflow needs editing.

Or add [`examples/rotate-keys.yml`](examples/rotate-keys.yml) to `.github/workflows/` in a private repository of your own. The heart of it is:

```yaml
      - uses: complyrobin/runner@v0
        with:
          token: ${{ secrets.COMPLYROBIN_TOKEN }}
          openai-admin-key: ${{ secrets.OPENAI_ADMIN_KEY }}
          laravel-cloud-token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
```

To test it, open **Actions, Rotate keys, Run workflow** and choose `check`, or press **Test** on the runner in ComplyRobin. Either way the results show in ComplyRobin. For extra safety, pin the action to a full commit SHA instead of `@v0`.

## Run it somewhere else

### In a Laravel app

```sh
composer require complyrobin/runner
```

Add `COMPLYROBIN_TOKEN` and the access you need to `.env`. In a Laravel app the runner's own access has its own names, so it never uses your app's `AWS_ACCESS_KEY_ID` (which may be the very key it rotates): `COMPLYROBIN_OPENAI_ADMIN_KEY`, `COMPLYROBIN_AWS_ACCESS_KEY_ID`, `COMPLYROBIN_AWS_SECRET_ACCESS_KEY`, `COMPLYROBIN_GOOGLE_CLOUD_CREDENTIALS`, `COMPLYROBIN_LARAVEL_CLOUD_API_TOKEN`, `COMPLYROBIN_GH_SECRETS_TOKEN`, `COMPLYROBIN_FORGE_API_TOKEN`, `COMPLYROBIN_VERCEL_TOKEN`. See `php artisan vendor:publish --tag=complyrobin-runner-config`.

The runner then runs every hour from your app's scheduler by itself. `php artisan complyrobin:rotate --check` tests it. Set `COMPLYROBIN_SCHEDULE=false` to schedule it yourself.

This keeps your admin tokens inside your app, so a GitHub repository of its own is still the safer home for the runner.

### In a container

```sh
docker run --rm -e COMPLYROBIN_TOKEN -e OPENAI_ADMIN_KEY -e LARAVEL_CLOUD_API_TOKEN ghcr.io/complyrobin/runner
docker run --rm -e COMPLYROBIN_TOKEN … ghcr.io/complyrobin/runner check
```

Run it every hour from cron, a Kubernetes CronJob, an ECS scheduled task or a Cloud Run job.

### Anywhere with PHP

The runner is a PHP 8.3+ command with no dependencies beyond PHP's `curl` and `sodium` extensions (and `openssl` if you use a Google Cloud key file).

```sh
composer require complyrobin/runner
export COMPLYROBIN_TOKEN=…  OPENAI_ADMIN_KEY=…  LARAVEL_CLOUD_API_TOKEN=…
vendor/bin/complyrobin-runner check   # tests the access, rotates nothing
vendor/bin/complyrobin-runner         # rotates what is due
```

### Settings

| Setting | What it is |
| --- | --- |
| `COMPLYROBIN_TOKEN` | The runner token from ComplyRobin (`crr_…`). It also tells ComplyRobin which organisation the runner belongs to. |
| `OPENAI_ADMIN_KEY` | An OpenAI admin key. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` | AWS access that can manage the IAM users' access keys and write the secrets or parameters. The session token only for short-lived access. |
| `GOOGLE_CLOUD_ACCESS_TOKEN` | A Google Cloud access token that can manage service account keys and add secret versions. |
| `GOOGLE_CLOUD_CREDENTIALS` | Instead of a token, the JSON key file of a service account that can. |
| `LARAVEL_CLOUD_API_TOKEN` | A Laravel Cloud API token. |
| `GH_SECRETS_TOKEN` | A GitHub token that can write the repository's Actions secrets. (GitHub doesn't allow secret names that start with `GITHUB_`.) |
| `FORGE_API_TOKEN` | A Laravel Forge API token. |
| `VERCEL_TOKEN` | A Vercel token. |
| `COMPLYROBIN_API_URL` | Optional. Defaults to `https://api.complyrobin.com/v1`. |

Exit codes: `0` everything due was rotated, `1` a rotation (or a check) failed (ComplyRobin has the details), `2` ComplyRobin could not be reached or refused the token, `78` a setting is missing.

## How your keys are protected

- The new key exists only in the runner's memory, from the moment the provider makes it until it is written to each place, and is then wiped.
- Laravel Cloud and GitHub secrets are sealed with libsodium (`crypto_box_seal`) to the service's own public key before they are sent. Every other place receives the key over HTTPS from its own API, with your own token.
- In GitHub Actions the runner tells GitHub to hide the key in logs (`::add-mask::`) the moment it exists, line by line for a key file.
- With each test the runner sends ComplyRobin the names and ids its access can see (OpenAI projects and service accounts, Laravel Cloud apps, environments and secret names, GitHub repositories), so you pick from lists instead of copying ids. Never a value: Laravel Cloud doesn't give secret values back, and ComplyRobin drops anything that looks like a key.
- ComplyRobin is told only a key's id: for AWS a shortened one (`AKIA…WXYZ`), as ComplyRobin keeps nothing shaped like a whole AWS key id.
- Everything the runner prints or reports passes a filter that removes anything shaped like a key or token. ComplyRobin also refuses any report that looks like it carries one.
- Requests go over HTTPS only, follow no redirects, and each token is sent only to its own service. Settings that become part of an address (a region, a repository) are checked first, so a setting can't send a token somewhere else.
- The code has no third-party dependencies, so what you audit here is what runs.

Found a security problem? Report it privately through this repository's Security tab rather than opening a public issue.

## Releasing

Set `Runner::VERSION`, then push a tag such as `v0.2.0`. The release workflow publishes the GitHub release, moves the `v0` tag that customers' workflows use, and publishes `ghcr.io/complyrobin/runner`. Packagist updates by itself.

## Developing

```sh
composer install
vendor/bin/phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
