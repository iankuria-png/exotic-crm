# GEMINI.md

## Project Overview
**Exotic CRM** is a robust Laravel-based system designed for managing sales workflows, client relationships, and complex billing orchestration. It serves as the authoritative business logic provider for an ecosystem that includes WordPress sites, multiple payment gateways (primarily in the East African market), and operator-facing workspaces.

The project is currently undergoing a significant architectural refactor to decouple billing logic into a dedicated domain (`app/Billing`) while maintaining backward compatibility with legacy services.

### Core Responsibilities
- **Sales & Operations:** Managing leads, deals, clients, and agent goals.
- **Billing Orchestration:** Handling payment imports, reconciliation, STK push, and payment links.
- **System Integration:** Bridging data between the CRM and WordPress (provisioning, credentials, and wallet sync).
- **Campaign Management:** Executing renewal campaigns and push notification strategies.

## Technologies & Stack
- **Backend:** PHP 8.1+ / Laravel 10.
- **Frontend:** React 19 / Vite 6 / Tailwind 4 / React Router 7 / TanStack React Query 5.
- **Database:** MySQL/PHpMyadmin (inferred from Laravel standard).
- **Key Libraries:**
  - `kopokopo/k2-connect-php`: Payment gateway integration.
  - `phpoffice/phpspreadsheet`: Excel/CSV processing for payment imports.
  - `laravel/sanctum`: API authentication.
  - `laravel/socialite`: OAuth integrations.
  - `recharts`: Data visualization in the frontend.

## Building and Running

### Prerequisites
- PHP 8.1+ & Composer
- Node.js & npm
- Local database (MySQL)

### Setup Commands
```bash
# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate
php artisan db:seed # If seeders are available

# Frontend development
npm run dev

# Frontend production build
npm run build
```

### Testing
```bash
# Run PHPUnit tests (Feature and Unit)
php artisan test
# or
vendor/bin/phpunit

# Run Browser tests (Playwright)
npm run test:browser
```

### Key Artisan Commands
The project includes several custom `crm:` commands for operational tasks:
- `crm:import-payments`: Process payment imports from CSV/XLSX.
- `crm:reconcile-pending-payments`: Verify and reconcile stale payments (Paystack/Pesapal).
- `crm:run-renewals`: Execute renewal campaigns.
- `crm:sync-clients`: Sync profiles from WordPress to CRM.
- `crm:sync-sb-users`: Link Support Board users to CRM clients.
- `crm:dispatch-scheduled-pushes`: Manage push campaign execution.

## Development Conventions

### Architecture & Style
- **Service Layer:** Core logic resides in `app/Services`. Avoid putting complex logic directly in controllers.
- **Billing Refactor:** New billing abstractions should be placed in `app/Billing/*` (Contracts, Providers, Routing, etc.).
- **Feature Flags:** The system uses extensive feature flagging for billing (see `AppServiceProvider.php` and `config/billing.php`).
- **Coding Standard:** Follow PSR-12/12. Use `laravel/pint` for automated linting.
- **Naming:** Follow standard Laravel conventions (PascalCase for Models/Controllers, camelCase for variables/methods).

### Workflow & Safety
- **Surgical Edits:** ALWAYS use the `replace` tool for existing code. DO NOT use `write_file` to replace entire components. This prevents accidental deletion of comments and unrelated logic.
- **Verification Gate:** Every implementation directive MUST end with a verification step. This includes running the relevant `php artisan test` and `npm run lint`. Do not consider a task complete until the tests are green.
- **Backward Compatibility:** Maintain legacy service behavior during the billing migration until explicit cutover.
- **Sandbox vs. Live:** Always respect the sandbox/live distinction for payment providers.
- **Locked Files:** High-traffic files like `Settings.jsx`, `Payments.jsx`, and core billing services are considered "single-writer" hotspots.
- **Documentation:** The `docs/` directory contains critical architectural decisions (ADRs) and implementation plans. Consult them before major changes.

## Key Directories
- `app/Billing`: New billing domain logic.
- `app/Services`: Existing business logic services.
- `app/Models`: Eloquent models representing the core business entities.
- `resources/js`: React SPA frontend.
- `docs/`: Extensive documentation and roadmap files.
- `tests/Feature`: Integration and functional tests.
- `tests/browser`: Playwright browser automation tests.