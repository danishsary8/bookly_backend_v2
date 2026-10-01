// Railway infrastructure for the Bookly API: one PostgreSQL database and three services built from the
// same Dockerfile (web, queue worker, scheduler). Apply with:  railway config plan  then  railway config apply
// Secrets are NOT in this file: they are Railway shared variables (Project Settings -> Shared Variables),
// referenced below as ctx.shared.NAME. See docs/DEPLOYMENT.md.
import { defineRailway, github, group, postgres, project, service } from "railway/iac";

const REPO = "danishsary8/bookly_backend_v2";

export default defineRailway((ctx) => {
  const db = postgres("postgres");

  // Settings every process needs (the image already sets APP_ENV=production, APP_DEBUG=false, LOG_CHANNEL=json_stdout).
  const common = {
    APP_NAME: "Bookly",
    APP_KEY: ctx.shared.APP_KEY,
    APP_URL: ctx.shared.APP_URL,
    DB_CONNECTION: "pgsql",
    DB_URL: db.env.DATABASE_URL, // private network, never leaves Railway
    CACHE_STORE: "database", // shared by all processes: rate limits, OTP counters
    QUEUE_CONNECTION: "database",
    SESSION_DRIVER: "array", // Bearer-token API, no browser sessions
    TRUSTED_PROXIES: "REMOTE_ADDR",
    CORS_ALLOWED_ORIGINS: ctx.shared.CORS_ALLOWED_ORIGINS,
    SHOP_TIMEZONE: "Asia/Phnom_Penh",
    MAIL_MAILER: "resend",
    RESEND_API_KEY: ctx.shared.RESEND_API_KEY,
    MAIL_FROM_ADDRESS: ctx.shared.MAIL_FROM_ADDRESS,
    MAIL_FROM_NAME: "Bookly",
    SENTRY_LARAVEL_DSN: ctx.shared.SENTRY_LARAVEL_DSN,
    GOOGLE_CLIENT_ID: ctx.shared.GOOGLE_CLIENT_ID,
    GOOGLE_CLIENT_SECRET: ctx.shared.GOOGLE_CLIENT_SECRET,
    FACEBOOK_CLIENT_ID: ctx.shared.FACEBOOK_CLIENT_ID,
    FACEBOOK_CLIENT_SECRET: ctx.shared.FACEBOOK_CLIENT_SECRET,
  };

  const source = github(REPO, { branch: "main" });
  const build = { builder: "DOCKERFILE" as const, dockerfilePath: "Dockerfile" };

  // Public API. Migrations run once per deploy before the new version takes traffic; if they fail,
  // the old version keeps running. Railway only switches over when /api/v1/health answers.
  const web = service("web", {
    source,
    build,
    preDeploy: "php artisan migrate --force",
    healthcheck: "/api/v1/health",
    healthcheckTimeout: 120,
    deploy: { restartPolicyType: "ON_FAILURE", restartPolicyMaxRetries: 10 },
    env: { ...common, CONTAINER_ROLE: "web", LOG_REQUESTS: "true" },
  });

  // Sends verification/reset emails and low-stock alerts from the jobs table.
  const worker = service("worker", {
    source,
    build,
    deploy: { restartPolicyType: "ALWAYS" },
    env: { ...common, CONTAINER_ROLE: "worker" },
  });

  // Runs routes/console.php schedules (daily expired-token cleanup).
  const scheduler = service("scheduler", {
    source,
    build,
    deploy: { restartPolicyType: "ALWAYS" },
    env: { ...common, CONTAINER_ROLE: "scheduler" },
  });

  return project("bookly", {
    resources: [group("Bookly API", [db, web, worker, scheduler])],
  });
});
