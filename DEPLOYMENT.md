# Production deployment

RepoChronos targets a single PHP-FPM server behind HTTPS Nginx, with Supabase PostgreSQL as its database. Python supplies Lizard. A small browser script updates repository sections in place.

## Prepare the host

Use PHP 8.5, Composer 2, Python 3.10+ with venv/pip, Nginx and PHP-FPM. Enable pdo_pgsql, mbstring, curl, dom/xml, fileinfo, openssl, tokenizer, session and OPcache.

Put the code at /var/www/repochronos or adjust the example paths. The web document root must be public. The PHP-FPM user needs write access to storage and bootstrap/cache.

    python3 -m venv .venv
    .venv/bin/python -m pip install -r requirements.txt
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    composer check-platform-reqs --no-dev

Copy .env.production.example to .env and fill in the Supabase session-pooler credentials. Download the database CA from Supabase Database Settings and store it outside public. Set DB_SSLROOTCERT to that absolute path. Set PYTHON_BINARY to the absolute .venv/bin/python path.

Set APP_URL to the real HTTPS address, APP_DEBUG=false and SESSION_SECURE_COOKIE=true. Generate APP_KEY once with php artisan key:generate for a new installation. Retain the existing APP_KEY when updating an installation.

Restrict .env access to the deployment and PHP-FPM users. Keep environment files, certificates and logs outside the document root. The single-server setup uses persistent file sessions and file cache. Preserve storage across code releases.

## Configure the web server

Use deploy/nginx.conf inside your domain's HTTPS server block. Adjust the root and PHP-FPM socket. Configure your TLS certificate in the enclosing server block. Apply deploy/php.ini through PHP-FPM.

Import and analysis requests allow 180 seconds. The Nginx example allows 200 seconds. Set PHP-FPM request_terminate_timeout to at least 200 seconds. Test the final configuration with nginx -t before reloading Nginx and PHP-FPM.

The template handles TLS directly in Nginx. Hosting behind another proxy requires the provider's trusted-proxy and HTTPS-forwarding configuration.

The public-root and cache setup follow [Laravel's deployment guidance](https://laravel.com/framework/docs/deployment).

## Release an update

Back up Supabase before database changes. Install dependencies and configure the target environment, then run:

    php artisan config:clear
    php artisan repochronos:doctor --production
    php artisan migrate --force
    php artisan optimize
    php artisan repochronos:doctor --production
    php artisan repochronos:verify --supabase

On a fresh database the first doctor check can fail its schema check until migrations run. Resolve every other failure first. Preserve history and use forward migrations.

The doctor command checks production settings, HTTPS, secure cookies, verified Supabase TLS, writable directories, the database schema and Lizard. It returns a nonzero status if any check fails and does not print credentials.

Keep ALLOW_SAMPLE_DATA=false. Routine deployment and db:seed create no example users or application records. Register personal accounts and connect real GitHub repositories. Old sample/verification records remain archived in the database and are excluded from normal application access and repository reports.

## Smoke check

1. Open /up through the real HTTPS domain. A healthy response verifies application boot and repository-table access.
2. Log in, switch sections and test Back/Forward.
3. Check history, comparisons, bug status and dashboard data.
4. Test a GitHub import and an analysis batch on a designated verification repository.
5. Inspect application and PHP-FPM logs for errors.

Run automated tests only against a designated test project using .env.testing. Concurrency tests commit labeled verification records so independent PHP processes can see them.

## Verification in this workspace

An isolated release was installed with development dependencies excluded. Platform requirements passed. Configuration, event, route and Blade caches built successfully. All production doctor checks passed with production settings and verified Supabase TLS. The release also passed the Supabase SQL verification command.

A public Nginx/PHP-FPM host and real HTTPS domain were unavailable here. Validate the server configuration on the chosen host. No hosting destination has been selected or published.
