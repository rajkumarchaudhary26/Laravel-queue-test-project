# QueueWorkTestApp

Lightweight Laravel + Postgres environment behind nginx, with a Vue 3 frontend service pared down for small-footprint deployments.

### Host port summary
- nginx entrypoint: `8080` (container port `80`)
- Vue dev server: `4100` (container port `8090`)
- PHP debugging/testing ports: `4101`, `4102` (container ports `8091`, `8092`)
- Postgres: `5433` (container port `5432`)

### First-time setup
1. Provision the shared network and database volume:
   ```bash
   docker network create queue-network
   docker volume create queue_pgdb_volume
   ```
2. Copy the backend environment file and add your credentials (S3 bucket, access keys, etc.):
   ```bash
   cp phpapp/src/.env.example phpapp/src/.env
   # edit phpapp/src/.env - set DB_*, AWS_*, FILESYSTEM_DISK=s3, QUEUE_CONNECTION=database
   ```
3. Build and start the stack:
   ```bash
   docker compose up -d --build
   ```
4. Install backend dependencies and run migrations:
   ```bash
   docker compose exec queue-backend bash
   cd /var/www/phpapp
   composer install
   php artisan key:generate
   php artisan migrate
   ```
5. Install frontend dependencies:
   ```bash
   docker compose exec queue-frontend sh
   cd /vueapp
   npm install
   npm run dev -- --host 0.0.0.0 --port 8090
   ```
6. Visit `http://localhost:4100` (served through nginx at `http://localhost:8080`) to see the Vue placeholder UI. Database is reachable on `localhost:5433`.

### Backend API overview
- `POST /api/files/upload` – upload one or more files to S3, returning stored metadata.
- `POST /api/files/zip-jobs` – accept an array of `document_ids`, enqueue the asynchronous zip build, and return a job id.
- `GET /api/files/zip-jobs/{id}` – poll job status until `status` becomes `completed`, then use the returned `download_url`.

Supervisor inside the PHP container boots five queue workers automatically (`zip-jobs` queue) alongside php-fpm. No additional steps are required beyond providing S3 credentials and running migrations.
