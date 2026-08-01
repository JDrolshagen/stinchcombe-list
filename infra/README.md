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
when their named secrets do not already exist.

## Continuous deployment

Pushes to `main` run `.github/workflows/deploy-production.yml`. The workflow
authenticates through Workload Identity Federation, builds an immutable
commit-tagged image, pushes it to Artifact Registry, deploys a new Cloud Run
revision, and verifies `https://stinchcombelist.com/`.

The Google Cloud identity provider only accepts tokens from
`JDrolshagen/stinchcombe-list` on `refs/heads/main`; no long-lived service
account key is stored in GitHub.
