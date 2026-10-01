# Book-Shop Backend V2

Laravel 13 + PostgreSQL REST API (`/api/v1/`) for a bookstore e-commerce platform. Rebuild of the legacy PHP backend with a 3NF schema.

- Project context, conventions, requirements: `docs/PROJECT_CONTEXT.md`
- Progress log and open questions: `docs/WORKLOG.md`
- What to do next: `docs/NEXT_STEP.md`

## Run locally
```
composer install
cp .env.example .env && php artisan key:generate
# create Postgres user bookshop/bookshop and databases bookshop_v2, bookshop_v2_test
php artisan migrate
php artisan test
php artisan serve
```
