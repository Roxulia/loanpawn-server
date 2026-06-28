# LonePawn Server

Backend server for the LonePawn pawnshop system.

This project is built with Laravel 12 and PHP 8.2. It contains the platform layer for tenant onboarding and licensing, the tenant-scoped core module for branch operations, and the pawn module for slip, item, interest, and redemption records.

## Purpose

LonePawn Server manages platform-level tenant data and tenant-scoped pawnshop data in one backend codebase.

Current domain coverage in this repository includes:

- Platform users and platform admins
- Tenant registration requests
- Tenant records, branding, settings, and license status logs
- Tenant users, customers, debts, expenses, and accounting
- Pawn loan contract slips
- Pawn normal items and jewellery items
- Slip item mappings
- Interest payments and redemptions

## Stack

- PHP 8.2
- Laravel 12
- Laravel Sanctum
- Laravel Reverb
- PHPUnit 11

## Project Structure

- `app/Models/PlatformModule`: platform-level models such as tenant, license, package, feature, and payment request
- `app/Models/CoreModule`: tenant-scoped operational models such as customer, user, role, debt, expense, accounting, branding, setting, and audit log
- `app/Models/PawnModule`: pawn domain models such as slip, item, interest payment, and redemption
- `app/Services/PlatformModule`: platform service layer for authentication and tenant resolution
- `app/Repository`: repository layer used by platform services
- `app/Http/Middleware`: request middleware including tenant resolution and platform role checks
- `database/migrations`: schema source of truth
- `schema.txt`: schema snapshot based on current migrations

## Multi-Tenant Rule

The server uses tenant-aware data separation.

- Tenant context is resolved by `X-Tenant-Code` request header
- Subdomain can also be used during tenant resolution
- Tenant context is stored in `TenantContext` for the current request lifecycle
- By current project convention, tenant-scoped and pawn-scoped tables use `tenant_id`

Main tenant entry point:

- `client_tenants`

Reference:

- `schema.txt` documents the intended foreign key rule and current table snapshot from migrations

## Authentication

Configured guards:

- `platformuser`
- `platformadmin`
- `web`

Current password reset flow for platform accounts:

- OTP is generated and stored as a hashed token
- OTP email is sent through `PlatformPasswordResetOtpMail`
- OTP expiry is configured in `config/auth.php`

## Current Modules

### Platform Module

Responsible for platform ownership and tenant lifecycle data.

- Platform user authentication
- Platform admin authentication
- Tenant lookup by subdomain and tenant code
- Tenant requests
- Tenant licenses
- Manual payment requests and attachments
- Package and feature models
- Tenant and license status logs

### Core Module

Responsible for tenant-scoped operational records.

- Tenant users and roles
- Tenant customers
- Tenant settings and branding
- Tenant debts
- Tenant expenses
- Tenant accounting
- Tenant audit logs
- Shared lookup tables for expense type, material type, and interest type

### Pawn Module

Responsible for pawnshop transaction records.

- Pawn loan contract slips
- Pawn slip item relations
- Pawn normal items
- Pawn jewellery items
- Pawn interest payments
- Pawn redemptions

## Local Setup

Install dependencies:

```bash
composer install
npm install
```

Create environment file and app key:

```bash
copy .env.example .env
php artisan key:generate
```

Run database migrations:

```bash
php artisan migrate
```

Run development services:

```bash
composer run dev
```

The development command starts separate workers for the `default`, `scheduled`, and `mail` queues, along with `php artisan schedule:work`.

## Cache and Queue Backends

Redis is optional. At runtime the application checks whether Redis is reachable:

- If Redis is reachable, cache and queued work use Redis.
- If Redis is not reachable, cache and queued work fall back to the database backend.

Default local environment values keep database as the configured fallback:

```dotenv
CACHE_STORE=database
QUEUE_CONNECTION=database
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Optional Redis queue/cache connection names can be configured when needed:

```dotenv
REDIS_CACHE_CONNECTION=cache
REDIS_QUEUE_CONNECTION=default
```

When Redis is available, run queue workers against the Redis connection:

```bash
php artisan queue:work redis --queue=default
php artisan queue:work redis --queue=scheduled
php artisan queue:work redis --queue=mail --tries=3
```

When Redis is unavailable, run queue workers against the database connection:

```bash
php artisan queue:work database --queue=default
php artisan queue:work database --queue=scheduled
php artisan queue:work database --queue=mail --tries=3
```

Production deployments should invoke Laravel's scheduler every minute and run the matching Redis or database queue workers shown above:

```bash
* * * * * cd /path/to/lonepawn-server && php artisan schedule:run >> /dev/null 2>&1
```

Run test suite:

```bash
composer test
```

## Notes

- The repository still contains a mix of old and new naming in some migrations and schema references
- `schema.txt` is useful for reviewing the current data model quickly
- Some routes are still in early setup state, while the domain model and migrations are further along
