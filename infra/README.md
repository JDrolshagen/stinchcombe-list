# Google Cloud deployment

`provision-gcp.sh` provisions the Stinchcombe List Drupal stack in Google
Cloud's Montréal region (`northamerica-northeast1`). It creates or updates:

- a regional Artifact Registry Docker repository;
- a Cloud SQL for MySQL 8 instance with automated backups and PITR;
- a regional Cloud Storage bucket mounted as Drupal's public files directory;
- Secret Manager secrets and a least-privilege runtime service account;
- the public Cloud Run service and its maintenance job.

Run the script from the repository root in an authenticated Google Cloud Shell:

```bash
bash infra/provision-gcp.sh
```

The script is designed to be safe to rerun. Secret values are generated only
when their named secrets do not already exist. On the first run it securely
prompts for the SendGrid API key, Cloudflare zone ID, and a Cloudflare API token
with Cache Purge permission. They are stored as Secret Manager secrets; values
are not written to the repository or Drupal's configuration database.

The provisioning script binds the two Cloudflare secrets to the service and
maintenance job. The production workflow detects those bindings so it can use
exact-URL purging during acceptance tests. If either binding is absent, Drupal
keeps automatic Cloudflare purging runtime-disabled while the module and
non-secret configuration remain deployed. Create the secrets and rerun the
provisioning script to activate it:

```bash
gcloud secrets create stinchcombe-list-cloudflare-zone-id \
  --project=stinchcombe-list --replication-policy=automatic --data-file=-
gcloud secrets create stinchcombe-list-cloudflare-api-token \
  --project=stinchcombe-list --replication-policy=automatic --data-file=-
```

The Cloudflare token needs only the target zone's Cache Purge permission. Full
zone purges are disabled. Stale discovery files can be purged and retested with:

```powershell
./scripts/Test-LlmDiscovery.ps1 -PurgeStale
```

## SendGrid credential migration

Cloud Run injects `SENDGRID_API_KEY` from Google Secret Manager. Drupal's
Google Cloud settings override `sendgrid_integration.settings:apikey` with that
environment variable at runtime.

After the Secret Manager binding and the updated application image are live,
remove the legacy database copy once with the maintenance job:

```bash
gcloud run jobs execute stinchcombe-list-maintenance \
  --project=stinchcombe-list \
  --region=northamerica-northeast1 \
  --args=config:delete,sendgrid_integration.settings,apikey,-y \
  --wait
```

SendGrid also requires the Drupal site email address
(`info@stinchcombelist.com`) to be a verified Single Sender or part of an
authenticated domain. Secret Manager protects the credential but does not
replace that SendGrid-side verification.

## Continuous deployment

Pushes to `main` run `.github/workflows/deploy-production.yml`. The workflow
authenticates through Workload Identity Federation, builds an immutable
commit-tagged image, pushes it to Artifact Registry, deploys a new Cloud Run
revision, runs database updates and the allowlisted managed configuration
through `scripts/maintenance.sh`, executes the external LLM-discovery acceptance
suite, and verifies that the service and job use the same immutable image tag.

The Google Cloud identity provider only accepts tokens from
`JDrolshagen/stinchcombe-list` on `refs/heads/main`; no long-lived service
account key is stored in GitHub.
