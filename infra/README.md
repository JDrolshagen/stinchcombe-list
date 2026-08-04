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
prompts for the SendGrid API key and stores it as
`stinchcombe-list-sendgrid-api-key`; the value is not written to the repository
or to Drupal's configuration database.

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
revision, runs Drupal cache rebuilds and database updates through the
maintenance job, and verifies `https://stinchcombelist.com/`.

The Google Cloud identity provider only accepts tokens from
`JDrolshagen/stinchcombe-list` on `refs/heads/main`; no long-lived service
account key is stored in GitHub.
