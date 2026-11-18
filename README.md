# ComplyRobin rotation runner

The rotation runner swaps your API keys when they are due and tells ComplyRobin when it is done. It runs in your own GitHub account (or on your own server), with your own admin tokens. **Your keys and admin tokens never reach ComplyRobin.** ComplyRobin only says which credential is due and keeps the record of each rotation as evidence.

It rotates keys from **OpenAI**, **AWS** (IAM user access keys) and **Google Cloud** (service account keys), and writes them into **Laravel Cloud** secrets. More destinations will follow.

Not every provider can be rotated. **Stripe** secret keys can only be rolled in the Stripe Dashboard (Stripe's key API is a private preview open only to accounts Stripe invites), and **GitHub personal access tokens** can only be made by a person on github.com. For GitHub, a GitHub App is the better choice: its tokens last an hour and renew themselves.

## What happens on each run

1. The runner asks ComplyRobin which credentials are due (or which ones someone pressed **Rotate now** on).
2. It asks the provider for a new key for that credential's own service account or IAM user.
3. It seals the key with Laravel Cloud's public key and writes it into each Laravel Cloud secret you listed. Only Laravel Cloud can open it.
4. It redeploys the environments you listed, so your app starts using the new key.
5. It retires the older keys after a grace period (48 hours), so nothing breaks while the deploy finishes. OpenAI can set a key to expire, so this happens straight away. AWS and Google Cloud can't, so ComplyRobin asks the runner to switch the old keys off once the 48 hours have passed (on its first check-in after that). Switched-off keys can be switched back on in AWS or Google Cloud if something still needed one; the next rotation deletes them.
6. It tells ComplyRobin how it went: the new key's id and a short hint, never the key.

If any step fails, your old key keeps working, the runner tells ComplyRobin which step failed, and ComplyRobin emails the credential's owner.

## Set up

### 1. At the provider

Give each credential **its own** identity at the provider. The runner retires every other key of that identity, so don't share it with anything else.

**OpenAI**

- Create a service account for this credential in the project (Project settings, Service accounts).
- Create an **admin key** (Organisation settings, Admin keys) with permission to manage API keys. The runner uses it to make and retire the service account's keys.

**AWS**

- Create an IAM user for this credential, with only the permissions your app needs. AWS lets a user have two access keys: before the first rotation, keep at most one.
- Give the runner AWS access that can run `iam:ListAccessKeys`, `iam:CreateAccessKey`, `iam:UpdateAccessKey` and `iam:DeleteAccessKey` on those users (for example, limited to users under the path `/complyrobin/`). In GitHub Actions the best way is an IAM role GitHub signs in to with OIDC, so there is no long-lived key at all: add an [`aws-actions/configure-aws-credentials`](https://github.com/aws-actions/configure-aws-credentials) step before the runner. Otherwise set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` for the runner. Don't use the user being rotated.
- In Laravel Cloud an AWS key is two secrets, such as `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`. Add both in ComplyRobin: one for the access key id, one for the secret.

**Google Cloud**

- Create a service account for this credential, with only the roles your app needs.
- Give the runner access that can manage that service account's keys (the **Service Account Key Admin** role on it). In GitHub Actions the best way is Workload Identity Federation: add a [`google-github-actions/auth`](https://github.com/google-github-actions/auth) step with `token_format: access_token` and pass its `access_token` to the runner. Otherwise give the runner the JSON key file of an admin service account as `google-cloud-credentials`.
- The new key is the whole JSON key file, written into one Laravel Cloud secret (for example `GOOGLE_CREDENTIALS_JSON`).

### 2. In Laravel Cloud

- Create the secrets your app reads (for example `OPENAI_API_KEY`) and attach them to your environments.
- Create an **API token** (Organisation settings, API tokens) that can update secrets and start deployments.

### 3. In ComplyRobin

- Go to **Credentials, Rotation runners** and press **Add runner**. Copy the token: it is shown only once.
- On the credential, press **Set up automatic rotation**, choose the provider and fill in its ids (OpenAI project and service account, AWS IAM user name, or Google Cloud service account email) and the Laravel Cloud secret and environment ids.

### 4. In GitHub

Use a private repository. Under **Settings, Secrets and variables, Actions**, add the repository secrets you need: `COMPLYROBIN_RUNNER_TOKEN`, `LARAVEL_CLOUD_API_TOKEN` and, for OpenAI, `OPENAI_ADMIN_KEY`. Then add `.github/workflows/rotate-keys.yml`:

```yaml
name: Rotate keys

on:
  schedule:
    - cron: "17 * * * *"   # every hour; ComplyRobin decides what is actually due
  workflow_dispatch:

permissions:
  id-token: write          # only for signing in to AWS or Google Cloud with OIDC; otherwise use {}

concurrency:
  group: rotate-keys
  cancel-in-progress: false

jobs:
  rotate:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      # Only if you rotate AWS keys:
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: arn:aws:iam::123456789012:role/complyrobin-runner
          aws-region: us-east-1

      # Only if you rotate Google Cloud keys:
      - uses: google-github-actions/auth@v2
        id: google
        with:
          workload_identity_provider: projects/123456789/locations/global/workloadIdentityPools/github/providers/github
          service_account: complyrobin-runner@your-project.iam.gserviceaccount.com
          token_format: access_token

      - uses: complyrobin/runner@v0
        with:
          organisation: your-organisation
          runner-token: ${{ secrets.COMPLYROBIN_RUNNER_TOKEN }}
          openai-admin-key: ${{ secrets.OPENAI_ADMIN_KEY }}
          google-cloud-access-token: ${{ steps.google.outputs.access_token }}
          laravel-cloud-token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
```

Leave out the providers you don't use. `organisation` is the part of your ComplyRobin address after the domain. To test the access without rotating anything, add `command: check` and run the workflow by hand.

For extra safety, pin the action to a full commit SHA instead of `@v0`.

## Run it somewhere else

The runner is a PHP 8.3+ command with no dependencies beyond PHP's `curl` and `sodium` extensions (and `openssl` if you use a Google Cloud key file).

```sh
composer require complyrobin/runner

export COMPLYROBIN_RUNNER_TOKEN=…  COMPLYROBIN_ORGANISATION=your-organisation
export OPENAI_ADMIN_KEY=…  LARAVEL_CLOUD_API_TOKEN=…   # and the AWS or Google Cloud settings you need
vendor/bin/complyrobin-runner check   # tests the access, rotates nothing
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
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` | AWS access that can manage the IAM users' access keys. The session token only for short-lived access. |
| `GOOGLE_CLOUD_ACCESS_TOKEN` | A Google Cloud access token that can manage service account keys. |
| `GOOGLE_CLOUD_CREDENTIALS` | Instead of a token, the JSON key file of a service account that can. |
| `LARAVEL_CLOUD_API_TOKEN` | A Laravel Cloud API token. |
| `COMPLYROBIN_API_URL` | Optional. Defaults to `https://api.complyrobin.com/v1`. |

Exit codes: `0` everything due was rotated, `1` a rotation failed (ComplyRobin has the details), `2` ComplyRobin could not be reached or refused the token, `78` a setting is missing.

## How your keys are protected

- The new key exists only in the runner's memory, from the moment the provider makes it until it is sealed for Laravel Cloud, and is then wiped.
- It is sent only to Laravel Cloud, sealed with libsodium (`crypto_box_seal`) to your Laravel Cloud organisation's public key.
- In GitHub Actions the runner tells GitHub to hide the key in logs (`::add-mask::`) the moment it exists, line by line for a key file.
- ComplyRobin is told only a key's id: for AWS a shortened one (`AKIA…WXYZ`), as ComplyRobin keeps nothing shaped like a whole AWS key id.
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
