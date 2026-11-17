# ComplyRobin rotation runner

The rotation runner swaps your API keys when they are due and tells ComplyRobin when it is done. It runs in your own GitHub account (or on your own server), with your own admin tokens. **Your keys and admin tokens never reach ComplyRobin.** ComplyRobin only says which credential is due and keeps the record of each rotation as evidence.

Today it rotates **OpenAI** service account keys and writes them into **Laravel Cloud** secrets. More sources and destinations will follow.

## What happens on each run

1. The runner asks ComplyRobin which credentials are due (or which ones someone pressed **Rotate now** on).
2. It asks OpenAI for a new key for that credential's service account.
3. It seals the key with Laravel Cloud's public key and writes it into each Laravel Cloud secret you listed. Only Laravel Cloud can open it.
4. It redeploys the environments you listed, so your app starts using the new key.
5. It sets the service account's older keys to expire after a grace period (48 hours), so nothing breaks while the deploy finishes.
6. It tells ComplyRobin how it went: the new key's id and last four characters, never the key.

If any step fails, your old key keeps working, the runner tells ComplyRobin which step failed, and ComplyRobin emails the credential's owner.

## Set up

### 1. In OpenAI

- Create a **service account just for this credential** in the project (Project settings, Service accounts). The runner retires every other key of that service account, so don't share it with anything else.
- Create an **admin key** (Organisation settings, Admin keys) with permission to manage API keys. The runner uses it to make and retire the service account's keys.

### 2. In Laravel Cloud

- Create the secret your app reads (for example `OPENAI_API_KEY`) and attach it to your environments.
- Create an **API token** (Organisation settings, API tokens) that can update secrets and start deployments.

### 3. In ComplyRobin

- Go to **Credentials, Rotation runners** and press **Add runner**. Copy the token: it is shown only once.
- On the credential, press **Set up automatic rotation** and fill in the OpenAI project and service account ids and the Laravel Cloud secret and environment ids.

### 4. In GitHub

Use a private repository. Under **Settings, Secrets and variables, Actions**, add three repository secrets: `COMPLYROBIN_RUNNER_TOKEN`, `OPENAI_ADMIN_KEY` and `LARAVEL_CLOUD_API_TOKEN`. Then add `.github/workflows/rotate-keys.yml`:

```yaml
name: Rotate keys

on:
  schedule:
    - cron: "17 * * * *"   # every hour; ComplyRobin decides what is actually due
  workflow_dispatch:

permissions: {}

concurrency:
  group: rotate-keys
  cancel-in-progress: false

jobs:
  rotate:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: complyrobin/runner@v0
        with:
          organisation: your-organisation
          runner-token: ${{ secrets.COMPLYROBIN_RUNNER_TOKEN }}
          openai-admin-key: ${{ secrets.OPENAI_ADMIN_KEY }}
          laravel-cloud-token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
```

`organisation` is the part of your ComplyRobin address after the domain. To test the tokens without rotating anything, add `command: check` and run the workflow by hand.

For extra safety, pin the action to a full commit SHA instead of `@v0`.

## Run it somewhere else

The runner is a PHP 8.3+ command with no dependencies beyond PHP's `curl` and `sodium` extensions.

```sh
composer require complyrobin/runner

export COMPLYROBIN_RUNNER_TOKEN=…  COMPLYROBIN_ORGANISATION=your-organisation
export OPENAI_ADMIN_KEY=…  LARAVEL_CLOUD_API_TOKEN=…
vendor/bin/complyrobin-runner check   # tests the tokens, rotates nothing
vendor/bin/complyrobin-runner         # rotates what is due
```

In a Laravel app, schedule it hourly in `routes/console.php`:

```php
Schedule::exec(base_path('vendor/bin/complyrobin-runner'))->hourly()->withoutOverlapping();
```

| Setting | What it is |
| --- | --- |
| `COMPLYROBIN_RUNNER_TOKEN` | The runner token from ComplyRobin (`crr_…`). |
| `COMPLYROBIN_ORGANISATION` | Your organisation's address name in ComplyRobin. |
| `OPENAI_ADMIN_KEY` | An OpenAI admin key. |
| `LARAVEL_CLOUD_API_TOKEN` | A Laravel Cloud API token. |
| `COMPLYROBIN_API_URL` | Optional. Defaults to `https://api.complyrobin.com/v1`. |

Exit codes: `0` everything due was rotated, `1` a rotation failed (ComplyRobin has the details), `2` ComplyRobin could not be reached or refused the token, `78` a setting is missing.

## How your keys are protected

- The new key exists only in the runner's memory, from the moment OpenAI makes it until it is sealed for Laravel Cloud, and is then wiped.
- It is sent only to Laravel Cloud, sealed with libsodium (`crypto_box_seal`) to your Laravel Cloud organisation's public key.
- In GitHub Actions the runner tells GitHub to hide the key in logs (`::add-mask::`) the moment it exists.
- Everything the runner prints or reports passes a filter that removes anything shaped like a key or token. ComplyRobin also refuses any report that looks like it carries one.
- Requests go over HTTPS only, follow no redirects, and each token is sent only to its own service.
- The code has no third-party dependencies, so what you audit here is what runs.

Found a security problem? Report it privately through this repository's Security tab rather than opening a public issue.

## Developing

```sh
composer install
vendor/bin/phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
