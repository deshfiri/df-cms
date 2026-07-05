# DFCP COMS — Testing Guide

## Quick Start

```bash
php artisan test
```

## Test Credentials (after seeding)

| Role        | Email                  | Password |
|-------------|------------------------|----------|
| Super Admin | admin@dfcp.com         | password |
| Manager     | manager@dfcp.com       | password |
| Marketing   | marketing@dfcp.com     | password |
| Accounts    | accounts@dfcp.com      | password |

## Feature Tests

### Authentication
- Login with valid credentials → redirects to dashboard
- Login with invalid credentials → shows error
- Access protected routes without login → redirects to login

### Client Module
- Create client with all required fields
- DFID auto-generated if blank
- Workflow stages auto-created on client creation
- Progress calculated as (completed / total stages) × 100

### Workflow
- Toggle stage updates progress in real-time (AJAX)
- Adding a new stage adds it to all existing clients

### Payments
- Multiple payments per client
- Payment summary shows total paid amount

### Import
- Upload valid XLSX → preview columns → map fields → import
- Duplicate DFID detection
- Invalid rows counted in failed_rows

### Export
- Excel, CSV, and PDF formats all downloadable
- Filtered export respects current filters

## Run Tests

```bash
# All tests
php artisan test

# Specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# With coverage
php artisan test --coverage
```
