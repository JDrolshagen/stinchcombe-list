#!/usr/bin/env bash
set -euo pipefail

PROJECT_ID="${PROJECT_ID:-stinchcombe-list}"
REGION="${REGION:-northamerica-northeast1}"
SERVICE_NAME="${SERVICE_NAME:-stinchcombe-list}"
DOMAIN="${DOMAIN:-stinchcombelist.com}"
SQL_INSTANCE="${SQL_INSTANCE:-stinchcombe-list}"
ARTIFACT_REPOSITORY="${ARTIFACT_REPOSITORY:-stinchcombe-list}"
BUCKET_NAME="${BUCKET_NAME:-${PROJECT_ID}-drupal-files}"
SERVICE_ACCOUNT_NAME="${SERVICE_ACCOUNT_NAME:-stinchcombe-drupal}"
SERVICE_ACCOUNT="${SERVICE_ACCOUNT_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
IMAGE="${REGION}-docker.pkg.dev/${PROJECT_ID}/${ARTIFACT_REPOSITORY}/drupal:latest"
SQL_CONNECTION="${PROJECT_ID}:${REGION}:${SQL_INSTANCE}"
DOMAIN_REGEX="${DOMAIN//./\\.}"

gcloud config set project "${PROJECT_ID}"

gcloud services enable \
  artifactregistry.googleapis.com \
  cloudbuild.googleapis.com \
  iam.googleapis.com \
  run.googleapis.com \
  secretmanager.googleapis.com \
  sqladmin.googleapis.com \
  storage.googleapis.com

if ! gcloud iam service-accounts describe "${SERVICE_ACCOUNT}" >/dev/null 2>&1; then
  gcloud iam service-accounts create "${SERVICE_ACCOUNT_NAME}" \
    --display-name="Stinchcombe List Drupal"
fi

for role in roles/cloudsql.client roles/secretmanager.secretAccessor roles/storage.objectAdmin; do
  gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
    --member="serviceAccount:${SERVICE_ACCOUNT}" \
    --role="${role}" \
    --quiet >/dev/null
done

if ! gcloud artifacts repositories describe "${ARTIFACT_REPOSITORY}" \
  --location="${REGION}" >/dev/null 2>&1; then
  gcloud artifacts repositories create "${ARTIFACT_REPOSITORY}" \
    --repository-format=docker \
    --location="${REGION}" \
    --description="Stinchcombe List Drupal containers"
fi

if ! gcloud storage buckets describe "gs://${BUCKET_NAME}" >/dev/null 2>&1; then
  gcloud storage buckets create "gs://${BUCKET_NAME}" \
    --location="${REGION}" \
    --uniform-bucket-level-access
fi

create_random_secret() {
  local secret_name="$1"
  if ! gcloud secrets describe "${secret_name}" >/dev/null 2>&1; then
    openssl rand -base64 48 | tr -d '\n' | gcloud secrets create "${secret_name}" \
      --replication-policy=user-managed \
      --locations="${REGION}" \
      --data-file=- >/dev/null
  fi
}

create_random_secret "${SERVICE_NAME}-database-password"
create_random_secret "${SERVICE_NAME}-hash-salt"
create_random_secret "${SERVICE_NAME}-cron-key"
create_random_secret "${SERVICE_NAME}-admin-password"

create_input_secret() {
  local secret_name="$1"
  local prompt="$2"
  local secret_value=""

  if gcloud secrets describe "${secret_name}" >/dev/null 2>&1; then
    return
  fi

  if [[ ! -t 0 ]]; then
    printf 'Secret %s does not exist. Run this script interactively to supply %s.\n' \
      "${secret_name}" "${prompt}" >&2
    exit 1
  fi

  read -r -s -p "Enter ${prompt}: " secret_value
  printf '\n'
  if [[ -z "${secret_value}" ]]; then
    printf '%s cannot be empty.\n' "${prompt}" >&2
    exit 1
  fi

  printf '%s' "${secret_value}" | gcloud secrets create "${secret_name}" \
    --replication-policy=user-managed \
    --locations="${REGION}" \
    --data-file=- >/dev/null
  unset secret_value
}

create_input_secret "${SERVICE_NAME}-sendgrid-api-key" "SendGrid API key"

if ! gcloud sql instances describe "${SQL_INSTANCE}" >/dev/null 2>&1; then
  gcloud sql instances create "${SQL_INSTANCE}" \
    --database-version=MYSQL_8_0 \
    --tier=db-g1-small \
    --region="${REGION}" \
    --availability-type=zonal \
    --storage-type=SSD \
    --storage-size=30 \
    --storage-auto-increase \
    --backup-start-time=09:00 \
    --enable-bin-log \
    --maintenance-window-day=SUN \
    --maintenance-window-hour=10
fi

if ! gcloud sql databases describe drupal --instance="${SQL_INSTANCE}" >/dev/null 2>&1; then
  gcloud sql databases create drupal --instance="${SQL_INSTANCE}"
fi

DB_PASSWORD="$(gcloud secrets versions access latest --secret="${SERVICE_NAME}-database-password")"
if gcloud sql users list --instance="${SQL_INSTANCE}" --format='value(name)' | grep -Fxq drupal; then
  gcloud sql users set-password drupal \
    --instance="${SQL_INSTANCE}" \
    --password="${DB_PASSWORD}"
else
  gcloud sql users create drupal \
    --instance="${SQL_INSTANCE}" \
    --password="${DB_PASSWORD}"
fi
unset DB_PASSWORD

gcloud builds submit --tag "${IMAGE}" .

gcloud run deploy "${SERVICE_NAME}" \
  --image="${IMAGE}" \
  --region="${REGION}" \
  --platform=managed \
  --allow-unauthenticated \
  --service-account="${SERVICE_ACCOUNT}" \
  --execution-environment=gen2 \
  --cpu=2 \
  --memory=4Gi \
  --concurrency=20 \
  --timeout=300 \
  --min=1 \
  --max=1 \
  --port=80 \
  --set-cloudsql-instances="${SQL_CONNECTION}" \
  --set-env-vars="DRUPAL_DB_USER=drupal,DRUPAL_DB_NAME=drupal,DRUPAL_DB_SOCKET=/cloudsql/${SQL_CONNECTION},DRUPAL_DEPLOYMENT_IDENTIFIER=cloudrun-${REGION}" \
  --set-secrets="DRUPAL_DB_PASSWORD=${SERVICE_NAME}-database-password:latest,DRUPAL_HASH_SALT=${SERVICE_NAME}-hash-salt:latest,DRUPAL_CRON_KEY=${SERVICE_NAME}-cron-key:latest,SENDGRID_API_KEY=${SERVICE_NAME}-sendgrid-api-key:latest" \
  --add-volume="mount-path=/var/www/html/web/sites/default/files,type=cloud-storage,bucket=${BUCKET_NAME},readonly=false,mount-options=uid=33;gid=33;file-mode=0664;dir-mode=0775;implicit-dirs"

SERVICE_URL="$(gcloud run services describe "${SERVICE_NAME}" \
  --region="${REGION}" --format='value(status.url)')"

gcloud run services update "${SERVICE_NAME}" \
  --region="${REGION}" \
  --update-env-vars="DRUPAL_TRUSTED_HOSTS_PATTERN=^(?:${SERVICE_NAME}-[a-z0-9-]+\\.${REGION}\\.run\\.app|(?:[a-z0-9-]+\\.)?${DOMAIN_REGEX})$"

gcloud run jobs deploy "${SERVICE_NAME}-maintenance" \
  --image="${IMAGE}" \
  --region="${REGION}" \
  --service-account="${SERVICE_ACCOUNT}" \
  --execution-environment=gen2 \
  --cpu=1 \
  --memory=1Gi \
  --task-timeout=30m \
  --max-retries=1 \
  --command=/var/www/html/vendor/bin/drush \
  --args=cron,-y \
  --set-cloudsql-instances="${SQL_CONNECTION}" \
  --set-env-vars="DRUPAL_DB_USER=drupal,DRUPAL_DB_NAME=drupal,DRUPAL_DB_SOCKET=/cloudsql/${SQL_CONNECTION},DRUPAL_TRUSTED_HOSTS_PATTERN=^(?:${SERVICE_NAME}-[a-z0-9-]+\\.${REGION}\\.run\\.app|(?:[a-z0-9-]+\\.)?${DOMAIN_REGEX})$" \
  --set-secrets="DRUPAL_DB_PASSWORD=${SERVICE_NAME}-database-password:latest,DRUPAL_HASH_SALT=${SERVICE_NAME}-hash-salt:latest,DRUPAL_CRON_KEY=${SERVICE_NAME}-cron-key:latest,SENDGRID_API_KEY=${SERVICE_NAME}-sendgrid-api-key:latest" \
  --add-volume="mount-path=/var/www/html/web/sites/default/files,type=cloud-storage,bucket=${BUCKET_NAME},readonly=false,mount-options=uid=33;gid=33;file-mode=0664;dir-mode=0775;implicit-dirs"

printf 'SERVICE_URL=%s\n' "${SERVICE_URL}"
