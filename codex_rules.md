# Codex Rules – OneMoreBit Multi-Tenant Standard

## Core Principle

This is a multi-tenant SaaS system.

Every piece of code must:
1. Respect tenant isolation
2. Follow layered architecture
3. Keep business logic inside services
4. Avoid shortcuts that break scalability

---

## Architecture Rules

### Layer Structure (Strict)

Controllers → Services → Repositories → Database

Additional layers:
- Exceptions
- Messages
- RequestDataObjects (DTOs)
- ResponseDataObjects (DTOs)

---

### Controller Rules

Controllers must:
- Handle request validation
- Call services
- Return responses

Controllers must NOT:
- Contain business logic
- Access database directly
- Perform complex conditional logic

---

### Service Rules

Services are the core of the system.

Services must:
- Contain all business logic
- Handle conditional flows
- Coordinate multiple services
- Use transactions when needed

Services must NOT:
- Directly access database (except via repository)
- Contain raw query logic

---

### Repository Rules

Repositories must:
- Handle database access only
- Contain query logic
- Return models or null

Repositories must NOT:
- Contain business logic
- Call other services

---

### Service Dependency Rule

Each service:
- MUST have only one primary repository

If other data is needed:
- Use other services, NOT their repositories

Example:
TenantService → TenantRepository (allowed)
TenantService → TenantUserService (allowed)
TenantService → TenantUserRepository (NOT allowed)

---

## Data Object Rules

### RequestDataObjects

- Used as input to services
- Must be structured and typed
- Must contain only required fields

### ResponseDataObjects

- Used as output from services
- Must shape API response data
- Must NOT expose raw models directly

---

## Exception Rules

- Every business error must use a custom exception
- Each exception must include:
  - HTTP status
  - error code
  - message

Example:
TENANT_NOT_FOUND
TENANT_ACCESS_DENIED
DUPLICATE_VALUE_FOUND

---

## Message Rules

- All user-facing messages must use:
  - message code
  - message value

Do NOT hardcode random strings inside services or controllers.

---

## Transaction Rules

- Any multi-step DB operation MUST use transactions

Example:
- Creating tenant
- Creating license
- Creating tenant admin
- Creating logs

Must be wrapped in:
DB::transaction(...)

---

## Tenant Rules (CRITICAL)

### Tenant Resolution

Tenant must be resolved using:
- X-Tenant-Code header
- subdomain (if exists)

Handled via middleware.

### Tenant Context

- Tenant must be stored in TenantContext
- Must be accessible globally during request lifecycle

### Mandatory Rule

Every tenant-owned query must:
- Filter by tenant

Never trust frontend tenant input blindly.

---

## Middleware Rules

Middleware must:
- Resolve tenant before request processing
- Inject tenant into context
- Reject request if tenant is invalid

---

## Authentication Rules

### Platform Users
- Use platform auth context

### Tenant Users
- Use tenant auth context

These MUST be separated.

---

## Service Design Rules

### Public Services

Services used by controllers must:
- Return ResponseDataObjects

Example:
TenantDetailService → used by Controller

---

### Internal Services

Internal services may:
- Return models
- Be used by other services

Example:
TenantService → returns Tenant model

---

## Naming Rules

- Use business-based naming
- Avoid unclear abbreviations

Examples:
- TenantService ✅
- TSvc ❌

---

## Code Behavior Rules

### Must Do

- Keep logic reusable
- Keep code readable
- Follow existing patterns
- Use DTOs properly
- Throw exceptions for invalid states

### Must NOT Do

- No logic in controllers
- No direct DB queries outside repositories
- No cross-tenant data access
- No mixing platform and tenant logic
- No duplicated logic across services

---

## Multi-Tenant Data Rules

### Global Data
- No tenant_id
- Shared across all tenants

### Tenant Data
- MUST include tenant_id
- MUST be scoped per tenant

### Mixed Data Pattern

Use:
- tenant_id = null → system data
- tenant_id != null → tenant data

DO NOT duplicate system data into tenant tables.

---

## Agent Behavior Rules (Important for Codex)

When generating code, you MUST:

1. Follow layer structure strictly
2. Use services for logic
3. Use repositories for DB access
4. Use DTOs for input/output
5. Use exceptions for errors
6. Respect tenant isolation in every query
7. Never bypass architecture rules
8. Prefer existing patterns over new ones

---

## Final Rule

If a solution breaks architecture rules but "works":

It is WRONG.

Always choose:
- scalable
- maintainable
- tenant-safe

over:
- quick
- hacky
- shortcut solutions
