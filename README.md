# Composer kkRepo Upload

GitHub Docker action for publishing Composer packages to a **hosted kkRepo repository**.

The action creates a ZIP, uploads it through kkRepo's Components API, then checks that
the exact version appears in Composer metadata and that its downloaded ZIP has the
same SHA-256 as the uploaded file. A rejected upload or a failed verification **fails
the job**. A green job means the publication was verified at that point in time.

## Switch from the Nexus action

The old `action-composer-nexus-upload@v1.1.1` uses `PUT .../packages/upload/...`.
kkRepo rejects that endpoint with HTTP 405. That action can still report `Finished`
because it checks transport errors without checking HTTP response codes.

This action uses:

```text
POST https://YOUR-SERVER/service/rest/v1/components?repository=YOUR-HOSTED-REPOSITORY
Content-Type: multipart/form-data
Fields: asset (ZIP), name, version
```

Your existing repository URL, username and password can stay the same. Existing
`extra.nexus-upload.ignore` exclusions and `.nexus` ignore settings are supported.
The original Nexus action is a separate project and is not modified.

### Before using it from other repositories

Publish this project to `hopplin/action-composer-kkrepo-upload`, then push a full
version tag such as `v1.0.0`. The release workflow runs verification, creates the
GitHub release and maintains the corresponding `v1` alias automatically. **The
examples below require that repository and a successful release workflow.** For
reproducible workflows, pin the action to the full commit SHA of your release.

## Automatic releases

Commit and push the release workflow before tagging the commit you want to release.
When the tests on `main` pass, create and push a new, annotated version tag:

```bash
git switch main
git pull --ff-only
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

The tag's commit must contain `.github/workflows/release.yml`. Pushing a tag on a
commit from before that workflow was added cannot trigger it. Use an unused
version number; published fixed-version tags must not be moved.

For each stable tag in the form `vMAJOR.MINOR.PATCH`, the workflow:

1. Builds the action image and runs the shared verification workflow against the
   tagged commit, including the upload and release-automation integration tests.
2. Creates a GitHub release for that existing tag, with generated release notes.
3. Creates or advances the lightweight major tag, e.g. `v1`, to the highest
   **published stable** release within that major version.

Use `@v1.0.0` for the fixed version or `@v1` to receive compatible releases. No
separate release is created for `v1`. Backfilled older releases do not roll `v1`
backward or replace a newer release's GitHub **Latest** designation. Unpublished
tags do not advance the major alias. Different major versions have separate aliases.

The workflow accepts stable versions only, without leading zeros, prerelease
suffixes or build suffixes. Examples: `v1.0.0`, `v1.0.1`, `v2.0.0`. A matching but
invalid tag fails validation rather than publishing. A bare `v1` push does not
trigger the release workflow.

Only the release job receives `contents: write`; verification runs read-only. The
normal `GITHUB_TOKEN` is sufficient: no personal access token or registry password
is required. Repository/organization policies must allow that permission and
creation/update of major tags. Tag protection may require an exemption for the
release workflow. Do not create GitHub releases for movable tags such as `v1`,
especially when immutable releases are enabled.

Publication jobs are serialized. If publication is interrupted, rerun the failed
workflow in GitHub Actions. An already published release is reused without
creating a duplicate; the major alias is repaired if needed. Existing drafts or
prereleases using the same stable tag must be resolved manually before rerunning.
The major-tag update uses a lease so a concurrent manual change is not blindly
overwritten.

No Docker registry push or Marketplace publication is needed. Consuming workflows
build the image directly from the versioned Dockerfile. Local verification never
publishes a real GitHub release.

## Quick start

In your application repository, configure:

| Setting | Type | Example |
| --- | --- | --- |
| `NEXUSURL` | Actions variable | `https://nexus.hoppl.in/repository/arnold-packages/` |
| `NEXUSUSER` | Actions secret | kkRepo user with upload and read access |
| `NEXUSPWD` | Actions secret | kkRepo password |

Keeping the old setting names makes migration easier; the destination is kkRepo.
Grant access to the **hosted** repository. Do not target a group or proxy. An
administrator account is not required.

```yaml
name: Publish Composer package

on:
  push:
    tags:
      - 'v*'

permissions:
  contents: read

jobs:
  publish:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      # Build your assets here before packaging, if required.
      - name: Publish and verify Composer package
        id: publish
        uses: hopplin/action-composer-kkrepo-upload@v1
        with:
          repository: ${{ vars.NEXUSURL }}
          username: ${{ secrets.NEXUSUSER }}
          password: ${{ secrets.NEXUSPWD }}
      - name: Show verified version
        env:
          PUBLISHED_VERSION: ${{ steps.publish.outputs.version }}
        run: printf 'Published %s\n' "$PUBLISHED_VERSION"
```

For the log example `arnold/bussysteme` at tag `v2.2.6`, the action publishes that
exact version to `arnold-packages`, verifies its metadata and downloads its ZIP
again. Previously rejected versions must be published again.

## Inputs

| Input | Required | Description |
| --- | --- | --- |
| `repository` | Yes | Full hosted repository URL ending in `/repository/NAME/`; trailing slash is optional. A reverse-proxy prefix such as `/nexus/repository/NAME/` is supported. |
| `username` | Yes | kkRepo user with upload **and read** access. |
| `password` | Yes | kkRepo password, supplied through an Actions secret. |
| `version` | No | Exact version to publish. If omitted, inferred from a branch/tag **push**. |
| `package-path` | No | Directory containing `composer.json`, relative to the checkout. Defaults to the checkout root. |
| `ignore` | No | Additional exclusions, one glob or PHP regular expression per line. |
| `github-event-name` | No | Defaults to `github.event_name`; used for version detection. |
| `github-ref-type` | No | Defaults to `github.ref_type`; used for version detection. |
| `github-ref-name` | No | Defaults to `github.ref_name`; used for version detection. |
| `github-token` | No | Accepted for old workflow compatibility, but not used by the uploader. |

HTTPS is required. Plain HTTP is accepted only for loopback hosts in local tests.
URLs containing embedded credentials, a query or a fragment are rejected.

### Version handling

- An explicit `version` wins over the Git ref and over `composer.json`.
- A tag push uses the tag unchanged, e.g. `v2.2.6`.
- A branch push uses `dev-BRANCH`, e.g. `dev-main`.
- All `/` characters are replaced by `-`, e.g. `feature/search/ui` becomes
  `dev-feature-search-ui`.
- Pull-request, manual and scheduled runs need an explicit `version`.
- Versions must contain only letters, digits, `.`, `_`, `+` or `-`, start with a
  letter or digit, and be at most 200 characters. kkRepo applies its own Composer
  metadata validation as well.

The action sets `version` in the **archived** `composer.json` to exactly the form
field sent to kkRepo. The checked-out source file is never changed. This prevents
kkRepo rejecting an upload because an older version is hard-coded in the source.

**kkRepo does not overwrite an existing Composer package version.** This also
applies to branch versions: a second publication of `dev-main` can be rejected.
Use unique explicit versions, e.g. `dev-main-COMMIT_SHA`, if you publish every
branch commit. Do not automatically retry failed uploads: a connection error can
occur after the server accepted the package. Check kkRepo first in that case.

### Explicit version and package subdirectory

```yaml
- name: Publish package
  uses: hopplin/action-composer-kkrepo-upload@v1
  with:
    repository: ${{ vars.NEXUSURL }}
    username: ${{ secrets.NEXUSUSER }}
    password: ${{ secrets.NEXUSPWD }}
    package-path: packages/Arnold.Bussysteme
    version: v2.2.6
    ignore: |
      *.zip
      *.map
      tests/
```

The package directory must resolve inside the checkout; symlink escapes and
absolute `package-path` values are rejected. When using `actions/checkout` with
its `path` input, include that directory in `package-path`.

## Package contents and exclusions

Packaging uses the built checkout, so generated CSS/JS assets are included unless
excluded. Build those assets **before** running this action.

The following are excluded by default:

- Directories named `.git`, `.github`, `.svn`, `.idea`, `vendor` or `node_modules`.
- Files named `composer.lock`, `.nexus`, `.kkrepo`, `.gitignore`, `auth.json`,
  `.env.local` or `.env.*.local`.
- All symbolic links, including links to files outside the checkout.

Other files, including ordinary `.env` files, are included unless explicitly
excluded. Review the package's exclusions before publishing sensitive files.

The generated ZIP lives in a private temporary directory, not in the checkout,
and is removed on normal success or failure. It cannot include itself. ZIP entry
timestamps are normalized to make repeated packaging of the same contents stable.
The archive is limited to 20,000 files, matching the deployed kkRepo archive limit.
Server/proxy upload-size and timeout limits still apply.

`composer.json` is **always included**, even with an exclusion such as `*.json`.
Existing ZIP files in the checkout are not implicitly excluded. Add `*.zip` to
avoid uploading previous release archives, such as `Arnold-Bussysteme.zip`.

### Configure in composer.json

```json
{
  "name": "arnold/bussysteme",
  "extra": {
    "kkrepo-upload": {
      "ignore": [
        "*.zip",
        "*.css.map",
        "tests/",
        "/^Resources\\/Private\\/Drafts\\//"
      ]
    }
  }
}
```

Patterns from `extra.kkrepo-upload.ignore`, legacy `extra.nexus-upload.ignore`,
`.kkrepo` / `.nexus` lines of the form `ignore=PATTERN`, and the `ignore` input
are combined. Legacy configuration files are read only for exclusions, never for
credentials. A non-string ignore value or invalid regular expression fails the job.

- Globs match the relative path; patterns without `/` also match file basenames.
- Directory patterns such as `tests/` exclude that directory and its descendants.
- A pattern starting with `/` is a PHP regular expression, e.g. `/^tests\\//`.
- Paths in patterns are relative to `package-path`, using `/` separators.

## What success verifies

1. Required settings, repository URL, workspace path and package name are valid.
2. A nonempty ZIP is created with the requested version in `composer.json`.
3. kkRepo accepts the multipart `POST` with an HTTP 2xx response.
4. The exact version appears in the hosted repository's `p2/vendor/package.json`
   metadata (`~dev.json` for development versions).
5. The published `dist.url` points inside the configured hosted repository.
6. Downloading that URL produces the same SHA-256 as the uploaded ZIP.

Verification uses authenticated requests. Passwords are not passed as process
arguments and are not logged. Redirects are not followed, preventing credentials
being forwarded to a different host. TLS verification remains enabled.

Transient **GET** failures (including temporarily missing metadata/downloads) are
retried at most twice after the first attempt. The upload **POST is never retried**
automatically. Each request has a 15-second connection timeout and a 300-second
overall timeout. Verification can download the archive up to three times on
transient errors, so account for the additional bandwidth.

## Outputs

Outputs are written only after successful verification:

| Output | Value |
| --- | --- |
| `package-name` | Composer package name, e.g. `arnold/bussysteme`. |
| `version` | Exact published version, e.g. `v2.2.6`. |
| `archive-sha256` | SHA-256 of the verified ZIP. |
| `dist-url` | Verified hosted archive URL. |

## Troubleshooting

| Result | Check |
| --- | --- |
| HTTP 400 | Valid `composer.json`, Composer hosted target, server validation logs. |
| HTTP 401 / 403 | Credentials and repository upload/read permissions. |
| HTTP 404 | Repository URL, permissions and published metadata. |
| HTTP 405 | Components API/reverse-proxy routing; do not use `/packages/upload/`. |
| HTTP 409 | Package version already exists; use a new version. |
| HTTP 413 | ZIP size and nginx/kkRepo upload limits. |
| HTTP 3xx | Configure the final HTTPS repository URL; the action does not follow redirects. |
| Upload accepted, verification failed | The package may already exist. Inspect kkRepo before retrying or deleting anything. |
| SHA-256 mismatch | Uploaded and served bytes differ; inspect storage and reverse-proxy behavior. |

Read hosted metadata directly, for example:

```text
https://nexus.hoppl.in/repository/arnold-packages/p2/arnold/bussysteme.json
```

Verification checks the **hosted** repository, not Composer's local cache or a
group repository. Group ordering, cached client metadata and application lock
files may still affect when consuming projects see a published version.

## Local verification

Requirements: PHP 8.3+ with `curl`, `zip` and `proc_open`, or Docker. Tests use an
ephemeral loopback HTTP server and temporary fixtures. No credentials or real
kkRepo installation are needed; no external package is published by the tests.
Release automation tests additionally require Python 3 and Git; they replace the
GitHub CLI with a fixture and push only to temporary local Git repositories.

```bash
php -l src/Upload.php
php -l kkrepo-upload.php
sh -n entrypoint.sh
php tests/run.php
python3 tests/test_release.py
```

Run the same integration suite using the actual Docker runtime:

```bash
docker build -t action-composer-kkrepo-upload:test .
docker run --rm --network none --entrypoint php \
  --env KKREPO_TEST_ENTRYPOINT=/entrypoint.sh \
  --mount "type=bind,source=$PWD,target=/tests,readonly" \
  --workdir /tests \
  action-composer-kkrepo-upload:test tests/run.php
```

The suite checks actual multipart uploads, ZIP content and version rewriting,
legacy/new exclusions, source preservation, tag/branch versions, development
metadata, output values, HTTP errors, transient GET retries, corrupt downloads,
missing versions, redirect rejection, credential redaction and workspace escapes.
The shared GitHub workflow builds the image and runs these checks on branch
pushes, pull requests, manual runs and calls from the release workflow. Version
tag pushes are verified through the release workflow, avoiding duplicate test runs.

## License

GPL-3.0, retaining the license of the original Nexus action. See [LICENSE](LICENSE).
