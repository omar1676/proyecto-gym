# Release manifest — Gimnera Restaurants R02

## Identity

- Version: `0.19.0-restaurants-menu-catalog-rc1`.
- Latest schema: `migracion_v36.sql`.
- Base branch: `feature/restaurants-foundation` at `bee9be9293c6c22564245f71ec5734d11374e778`.
- Candidate branch: `feature/restaurants-menu-catalog`.
- Deployment status: local release candidate only; **not deployed**.

## Scope

R02 adds the service/domain foundation for Restaurant catalogs, categories,
products, optional variants, modifier groups, exact prices, scoped
availability, declared allergen metadata and private image metadata. It keeps
the Restaurant domain separate from Gym products and sales.

R02 does **not** implement orders, tables, operational QR, kitchen/KDS,
printers, payments, delivery integrations, stock, recipes, ingredient costing,
tax/fiscal compliance, public HTTP routes or Restaurant UI.

## Runtime contract

- PHP target: `8.3.6`.
- MariaDB target: `10.11.14`.
- Runtime schema range: v27–v36.
- Migrator maximum: v36; an older v35 runtime/migrator must reject v36.
- Document root remains `public/`.

The exact final suite count, lint inventory, database version, backup/restore
hashes and RTO are evidence produced after the final clean commit. They are not
predeclared here.

## Data and security contract

- All R02 fixtures are synthetic (`Gimnera Food Demo`).
- No Jama data, imagery or inferred business rules are included.
- No card data, biometric data, access hardware or third-party credentials.
- Prices use integer minor units; no floating-point persistence.
- Tenant/account/brand relationships have service and composite-FK defenses.
- Critical writes use required audit, idempotency and/or optimistic versions.
- Ingredient, allergen legal taxonomy, effective-price precedence and
  Restaurant RBAC remain pending Jama/domain validation.

## Reproducible artifact

Build with:

`php ops/build_release.php --output-dir=<isolated-directory>`

The final gate builds twice from the same clean commit and compares ZIP and
manifest hashes. Runtime mutable directories, tests and local configuration are
not part of the release.

## Mandatory exclusions

The artifact must exclude `.git`, `.env`, credentials, keys, tokens, sessions,
logs, uploads, backups, dumps, test fixtures and runtime mutable files.

## Known pending decisions

- `DOMAIN_DECISION_PENDING_JAMA`: price precedence and catalog scheduling.
- `RESTAURANT_RBAC_PENDING_JAMA`: operational Restaurant roles and scopes.
- Variant-specific modifier groups, recipes, ingredients and stock.
- Legal/fiscal and allergen validation outside this engineering phase.
