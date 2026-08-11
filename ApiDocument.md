# LonePawn API Document

This document is the frontend-facing OpenAPI reference for the LonePawn server API.

All JSON API responses use the standard envelope:

```json
{
  "success": true,
  "message": "Request completed successfully.",
  "data": {},
  "statusCode": 200
}
```

Validation errors are returned at `data.errors`. Frontend code should check the HTTP status and `success`, then read endpoint data from `data`.

```yaml
openapi: 3.1.0
info:
  title: LonePawn Server API
  version: 1.0.0
  description: >
    End-to-end API contract for LonePawn frontend and desktop clients.
    JSON endpoints return the standard ApiResponse envelope. HTML, PDF,
    image, and CSV/download endpoints return their native content types.
servers:
  - url: /api
    description: Current Laravel API base path
tags:
  - name: License
  - name: Tenant Auth
  - name: Tenant Profile
  - name: Tenant Users
  - name: Customers
  - name: Collateral
  - name: Loan Contracts
  - name: Interest Payments
  - name: Redemptions
  - name: Accounting
  - name: Expenses
  - name: Debts
  - name: Settings
  - name: Default Data
  - name: Slip Documents
  - name: Online Sync
  - name: Tenant Public Assets

paths:
  /license/validate:
    post:
      tags: [License]
      summary: Validate a tenant license key
      security: []
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [license_key]
              properties:
                license_key:
                  type: string
                  minLength: 16
                  maxLength: 16
      responses:
        '200':
          $ref: '#/components/responses/LicenseValidationSuccess'
        '422':
          $ref: '#/components/responses/ValidationOrBusinessError'

  /tenant/login/public-spa:
    post:
      tags: [Tenant Auth]
      summary: Login from a public SPA using tenant code
      security: []
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [tenant_code, email, password]
              properties:
                tenant_code:
                  type: string
                  maxLength: 32
                email:
                  type: string
                  format: email
                password:
                  type: string
      responses:
        '200':
          $ref: '#/components/responses/AuthSessionSuccess'
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/login/subdomain-spa:
    post:
      tags: [Tenant Auth]
      summary: Login after tenant has been resolved by subdomain or tenant header
      security: []
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [email, password]
              properties:
                email:
                  type: string
                  format: email
                password:
                  type: string
      responses:
        '200':
          $ref: '#/components/responses/AuthSessionSuccess'
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/sso/consume:
    post:
      tags: [Tenant Auth]
      summary: Consume a tenant SSO token
      security: []
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [tenant_code, token]
              properties:
                tenant_code:
                  type: string
                  maxLength: 32
                token:
                  type: string
                  maxLength: 255
      responses:
        '200':
          $ref: '#/components/responses/AuthSessionSuccess'
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/resolve-tenant:
    get:
      tags: [Tenant Profile]
      summary: Resolve the current tenant context
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          description: Current tenant details
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantResponse'

  /tenant/me:
    get:
      tags: [Tenant Profile]
      summary: Get the authenticated tenant user
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          description: Current tenant user
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantUserResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'

  /tenant/logout:
    post:
      tags: [Tenant Auth]
      summary: Logout the authenticated tenant user
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/me/change-password:
    put:
      tags: [Tenant Profile]
      summary: Change current tenant user password
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/PasswordChangeRequest'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/users:
    get:
      tags: [Tenant Users]
      summary: List tenant users
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedTenantUsers'
    post:
      tags: [Tenant Users]
      summary: Create tenant user
      parameters:
        - $ref: '#/components/parameters/AcceptJson'
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/TenantUserCreateRequest'
      responses:
        '201':
          description: Tenant user created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantUserResponse'
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/users/{tenantUserCode}:
    get:
      tags: [Tenant Users]
      summary: Show tenant user
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantUserCode'
      responses:
        '200':
          description: Tenant user
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantUserResponse'
    put:
      tags: [Tenant Users]
      summary: Update tenant user
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantUserCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/TenantUserUpdateRequest'
      responses:
        '200':
          description: Tenant user updated
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantUserResponse'
    delete:
      tags: [Tenant Users]
      summary: Delete tenant user
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantUserCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/users/{tenantUserCode}/permissions:
    put:
      tags: [Tenant Users]
      summary: Update tenant user permissions
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantUserCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              additionalProperties:
                type: boolean
      responses:
        '200':
          description: Tenant user with permissions
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/TenantUserResponse'

  /tenant/users/{tenantUserCode}/reset-to-defaultpassword:
    put:
      tags: [Tenant Users]
      summary: Reset tenant user password to default
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantUserCode'
      requestBody:
        required: false
        content:
          application/json:
            schema:
              type: object
              properties:
                logoutFromAll:
                  type: boolean
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/customers:
    get:
      tags: [Customers]
      summary: List tenant customers
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
        - name: search
          in: query
          schema:
            type: string
            maxLength: 120
      responses:
        '200':
          $ref: '#/components/responses/PaginatedCustomers'
    post:
      tags: [Customers]
      summary: Create or return existing tenant customer
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/CustomerWriteRequest'
      responses:
        '200':
          description: Existing customer returned
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/CustomerResponse'
        '201':
          description: Customer created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/CustomerResponse'

  /tenant/customers/{tenantCustomerCode}:
    get:
      tags: [Customers]
      summary: Show tenant customer
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantCustomerCode'
      responses:
        '200':
          description: Customer
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/CustomerResponse'
    put:
      tags: [Customers]
      summary: Update tenant customer
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantCustomerCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              allOf:
                - $ref: '#/components/schemas/CustomerWriteRequest'
                - type: object
                  properties:
                    update_key:
                      type: integer
                      minimum: 0
      responses:
        '200':
          description: Customer updated
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/CustomerResponse'
    delete:
      tags: [Customers]
      summary: Delete tenant customer
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/TenantCustomerCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/collateral-items:
    get:
      tags: [Collateral]
      summary: List collateral items
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
        - name: search
          in: query
          schema:
            type: string
            maxLength: 120
      responses:
        '200':
          $ref: '#/components/responses/PaginatedCollateralItems'

  /tenant/collateral-items/{itemCode}:
    get:
      tags: [Collateral]
      summary: Show collateral item
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/ItemCode'
      responses:
        '200':
          description: Collateral item
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/CollateralItemResponse'
    delete:
      tags: [Collateral]
      summary: Delete collateral item
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/ItemCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/loan-contract-slips:
    get:
      tags: [Loan Contracts]
      summary: List loan contract slips
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedLoanContracts'
    post:
      tags: [Loan Contracts]
      summary: Create loan contract slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/LoanContractCreateRequest'
          multipart/form-data:
            schema:
              $ref: '#/components/schemas/LoanContractMultipartCreateRequest'
      responses:
        '201':
          description: Loan contract slip created
          headers:
            Idempotent-Replay:
              schema:
                type: string
              description: Present when an idempotent response is replayed.
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/LoanContractResponse'
        '409':
          $ref: '#/components/responses/IdempotencyConflict'

  /tenant/loan-contract-slips/{slipNo}:
    get:
      tags: [Loan Contracts]
      summary: Show loan contract slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
      responses:
        '200':
          description: Loan contract slip
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/LoanContractResponse'
    delete:
      tags: [Loan Contracts]
      summary: Delete loan contract slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/interest-payments:
    get:
      tags: [Interest Payments]
      summary: List interest payment history
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedInterestPayments'

  /tenant/interest-payments/{slipNo}/calculate:
    get:
      tags: [Interest Payments]
      summary: Calculate interest for a slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
      responses:
        '200':
          description: Interest calculation
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/InterestCalculationResponse'

  /tenant/interest-payments/{slipNo}/pay:
    post:
      tags: [Interest Payments]
      summary: Pay interest for a slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/InterestPaymentRequest'
      responses:
        '200':
          description: Interest payment result
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/GenericObjectResponse'

  /tenant/redemptions:
    get:
      tags: [Redemptions]
      summary: List redemptions
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedRedemptions'
    post:
      tags: [Redemptions]
      summary: Create pawn redemption
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/RedemptionCreateRequest'
      responses:
        '201':
          description: Redemption created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/GenericObjectResponse'

  /tenant/redemptions/{slipNo}/calculate:
    get:
      tags: [Redemptions]
      summary: Calculate redemption amount for a slip
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
      responses:
        '200':
          description: Redemption calculation
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/GenericObjectResponse'

  /tenant/redemption-records/{slipNumber}:
    get:
      tags: [Redemptions]
      summary: Show redemption record by slip number
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - name: slipNumber
          in: path
          required: true
          schema:
            type: string
      responses:
        '200':
          description: Redemption record
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/GenericObjectResponse'

  /tenant/accounting:
    get:
      tags: [Accounting]
      summary: List accounting transactions
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedAccounting'

  /tenant/accounting/incoming:
    get:
      tags: [Accounting]
      summary: List incoming accounting transactions
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedAccounting'

  /tenant/accounting/outgoing:
    get:
      tags: [Accounting]
      summary: List outgoing accounting transactions
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedAccounting'

  /tenant/accounting/ledger:
    get:
      tags: [Accounting]
      summary: Build an accounting ledger
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/StartDate'
        - $ref: '#/components/parameters/EndDate'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          description: Accounting ledger
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/GenericObjectResponse'
        '500':
          $ref: '#/components/responses/ServerError'

  /tenant/accounting/ledger/download:
    get:
      tags: [Accounting]
      summary: Download accounting ledger
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/StartDate'
        - $ref: '#/components/parameters/EndDate'
      responses:
        '200':
          description: CSV or streamed ledger download
          content:
            text/csv:
              schema:
                type: string
                format: binary
            application/octet-stream:
              schema:
                type: string
                format: binary
        '422':
          $ref: '#/components/responses/ValidationError'
        '500':
          $ref: '#/components/responses/ServerError'

  /tenant/expenses:
    get:
      tags: [Expenses]
      summary: List expenses
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedExpenses'
    post:
      tags: [Expenses]
      summary: Create expense
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ExpenseWriteRequest'
          multipart/form-data:
            schema:
              allOf:
                - $ref: '#/components/schemas/ExpenseWriteRequest'
                - type: object
                  properties:
                    image_reference:
                      type: string
                      format: binary
      responses:
        '201':
          description: Expense created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ExpenseResponse'

  /tenant/expenses/{expenseCode}:
    get:
      tags: [Expenses]
      summary: Get expense details with a five-minute reference image URL
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/ExpenseCode'
      responses:
        '200':
          description: Expense details
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ExpenseDetailResponse'
    put:
      tags: [Expenses]
      summary: Update expense metadata or reference image; amount is immutable
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/ExpenseCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ExpenseUpdateRequest'
          multipart/form-data:
            schema:
              $ref: '#/components/schemas/ExpenseMultipartUpdateRequest'
      responses:
        '200':
          description: Expense updated
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ExpenseResponse'
    delete:
      tags: [Expenses]
      summary: Delete expense
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/ExpenseCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/debts:
    get:
      tags: [Debts]
      summary: List debts
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/PerPage'
      responses:
        '200':
          $ref: '#/components/responses/PaginatedDebts'
    post:
      tags: [Debts]
      summary: Create debt
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/IdempotencyKey'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/DebtWriteRequest'
      responses:
        '201':
          description: Debt created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DebtResponse'

  /tenant/debts/{debtCode}:
    put:
      tags: [Debts]
      summary: Update debt
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DebtCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/DebtUpdateRequest'
      responses:
        '200':
          description: Debt updated
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DebtResponse'
    delete:
      tags: [Debts]
      summary: Delete debt
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DebtCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/debts/{debtCode}/paid:
    post:
      tags: [Debts]
      summary: Mark debt as paid
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DebtCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [amount_paid]
              properties:
                amount_paid:
                  type: number
                  minimum: 0.01
      responses:
        '200':
          description: Debt paid
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DebtResponse'

  /tenant/branding/slip-layouts:
    get:
      tags: [Settings]
      summary: Get tenant slip document layouts
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'
    put:
      tags: [Settings]
      summary: Update tenant slip document layouts
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                slip_header_layout:
                  type: object
                  additionalProperties: true
                slip_footer_layout:
                  type: object
                  additionalProperties: true
                update_key:
                  type: integer
                  minimum: 0
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings:
    get:
      tags: [Settings]
      summary: Get tenant settings
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/TenantSettingsSuccess'
    put:
      tags: [Settings]
      summary: Update tenant settings in one request
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/TenantSettingsUpdateRequest'
      responses:
        '200':
          $ref: '#/components/responses/TenantSettingsSuccess'

  /tenant/settings/branding:
    put:
      tags: [Settings]
      summary: Update tenant branding
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/TenantBrandingUpdateRequest'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings/contact:
    put:
      tags: [Settings]
      summary: Update tenant contact
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/TenantContactUpdateRequest'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings/default-user-password:
    put:
      tags: [Settings]
      summary: Update default tenant user password
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [default_tenant_user_password]
              properties:
                default_tenant_user_password:
                  type: string
                update_key:
                  type: integer
                  minimum: 0
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings/material-types:
    post:
      tags: [Settings]
      summary: Create material type from settings
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        $ref: '#/components/requestBodies/DefaultDataCreate'
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings/interest-types:
    post:
      tags: [Settings]
      summary: Create interest type from settings
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        $ref: '#/components/requestBodies/DefaultDataCreateWithDuration'
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/settings/expense-types:
    post:
      tags: [Settings]
      summary: Create expense type from settings
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        $ref: '#/components/requestBodies/DefaultDataCreate'
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/material-types:
    get:
      tags: [Default Data]
      summary: List material types
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'
    post:
      tags: [Default Data]
      summary: Create current tenant material type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        $ref: '#/components/requestBodies/RequiredDefaultDataCreate'
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/material-types/{code}:
    delete:
      tags: [Default Data]
      summary: Delete current tenant material type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DefaultDataCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/interest-types:
    get:
      tags: [Default Data]
      summary: List interest types
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'
    post:
      tags: [Default Data]
      summary: Create current tenant interest type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [name, code]
              properties:
                name:
                  type: string
                  maxLength: 255
                code:
                  type: string
                  maxLength: 255
                durationInDays:
                  type: integer
                  minimum: 0
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/interest-types/{code}:
    delete:
      tags: [Default Data]
      summary: Delete current tenant interest type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DefaultDataCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/expense-types:
    get:
      tags: [Default Data]
      summary: List expense types
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'
    post:
      tags: [Default Data]
      summary: Create current tenant expense type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        $ref: '#/components/requestBodies/RequiredDefaultDataCreate'
      responses:
        '201':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/expense-types/{code}:
    delete:
      tags: [Default Data]
      summary: Delete current tenant expense type
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/DefaultDataCode'
      responses:
        '200':
          $ref: '#/components/responses/MessageSuccess'

  /tenant/slip-documents/config:
    get:
      tags: [Slip Documents]
      summary: Get slip document layout config
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'

  /tenant/loan-contract-slips/{slipNo}/document/preview:
    get:
      tags: [Slip Documents]
      summary: Preview slip document as HTML
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
        - $ref: '#/components/parameters/PaperType'
        - $ref: '#/components/parameters/Orientation'
      responses:
        '200':
          description: HTML preview
          content:
            text/html:
              schema:
                type: string
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/loan-contract-slips/{slipNo}/document/download:
    get:
      tags: [Slip Documents]
      summary: Download slip document PDF
      parameters:
        - $ref: '#/components/parameters/TenantCode'
        - $ref: '#/components/parameters/SlipNo'
        - $ref: '#/components/parameters/PaperType'
        - $ref: '#/components/parameters/Orientation'
      responses:
        '200':
          description: PDF document
          content:
            application/pdf:
              schema:
                type: string
                format: binary
        '422':
          $ref: '#/components/responses/ValidationError'

  /tenant/online-sync:
    post:
      tags: [Online Sync]
      summary: Push offline sync logs
      parameters:
        - $ref: '#/components/parameters/TenantCode'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/OnlineSyncPushRequest'
      responses:
        '200':
          $ref: '#/components/responses/GenericSuccess'
        '207':
          description: Partial sync failure
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiObjectResponse'

  /tenant/{tenantCode}/logo:
    get:
      tags: [Tenant Public Assets]
      summary: Get tenant logo image
      security: []
      parameters:
        - name: tenantCode
          in: path
          required: true
          schema:
            type: string
      responses:
        '200':
          description: Tenant logo image
          content:
            image/png:
              schema:
                type: string
                format: binary
            image/jpeg:
              schema:
                type: string
                format: binary
            image/webp:
              schema:
                type: string
                format: binary

  /user:
    get:
      tags: [Tenant Profile]
      summary: Laravel Sanctum authenticated user route
      responses:
        '200':
          description: Authenticated user object
          content:
            application/json:
              schema:
                type: object
                additionalProperties: true

components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
      bearerFormat: Sanctum token

  parameters:
    AcceptJson:
      name: Accept
      in: header
      required: false
      schema:
        type: string
        default: application/json
    TenantCode:
      name: X-Tenant-Code
      in: header
      required: true
      schema:
        type: string
      description: Tenant context header used by tenant-resolve middleware.
    IdempotencyKey:
      name: Idempotency-Key
      in: header
      required: false
      schema:
        type: string
        maxLength: 120
      description: Reuses a previous successful response for the same payload.
    PerPage:
      name: per_page
      in: query
      required: false
      schema:
        type: integer
        minimum: 1
        maximum: 100
        default: 15
    StartDate:
      name: start_date
      in: query
      required: true
      schema:
        type: string
        format: date
    EndDate:
      name: end_date
      in: query
      required: true
      schema:
        type: string
        format: date
    SlipNo:
      name: slipNo
      in: path
      required: true
      schema:
        type: string
    PaperType:
      name: paper_type
      in: query
      required: true
      schema:
        type: string
    Orientation:
      name: orientation
      in: query
      required: false
      schema:
        type: string
        enum: [portrait, landscape]
        default: portrait
    TenantUserCode:
      name: tenantUserCode
      in: path
      required: true
      schema:
        type: string
    TenantCustomerCode:
      name: tenantCustomerCode
      in: path
      required: true
      schema:
        type: string
    ItemCode:
      name: itemCode
      in: path
      required: true
      schema:
        type: string
    ExpenseCode:
      name: expenseCode
      in: path
      required: true
      schema:
        type: string
    DebtCode:
      name: debtCode
      in: path
      required: true
      schema:
        type: string
    DefaultDataCode:
      name: code
      in: path
      required: true
      schema:
        type: string

  requestBodies:
    DefaultDataCreate:
      required: true
      content:
        application/json:
          schema:
            type: object
            required: [name]
            properties:
              name:
                type: string
                maxLength: 120
              code:
                type: string
                maxLength: 80
    DefaultDataCreateWithDuration:
      required: true
      content:
        application/json:
          schema:
            type: object
            required: [name]
            properties:
              name:
                type: string
                maxLength: 120
              code:
                type: string
                maxLength: 80
              duration_in_days:
                type: integer
                minimum: 1
    RequiredDefaultDataCreate:
      required: true
      content:
        application/json:
          schema:
            type: object
            required: [name, code]
            properties:
              name:
                type: string
                maxLength: 255
              code:
                type: string
                maxLength: 255

  responses:
    GenericSuccess:
      description: Standard success response
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/GenericObjectResponse'
    MessageSuccess:
      description: Standard message-only success response
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/ApiNullableResponse'
    AuthSessionSuccess:
      description: Tenant auth session
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/AuthSessionResponse'
    LicenseValidationSuccess:
      description: License validation result
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/LicenseValidationResponse'
    TenantSettingsSuccess:
      description: Tenant settings
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/TenantSettingsResponse'
    PaginatedTenantUsers:
      description: Paginated tenant users
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedCustomers:
      description: Paginated customers
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedCollateralItems:
      description: Paginated collateral items
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedLoanContracts:
      description: Paginated loan contract slips
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedInterestPayments:
      description: Paginated interest payments
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedRedemptions:
      description: Paginated redemptions
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedAccounting:
      description: Paginated accounting transactions
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedExpenses:
      description: Paginated expenses
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    PaginatedDebts:
      description: Paginated debts
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/PaginatedResponse'
    ValidationError:
      description: Validation failed
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/ValidationErrorResponse'
    ValidationOrBusinessError:
      description: Validation or business error
      content:
        application/json:
          schema:
            oneOf:
              - $ref: '#/components/schemas/ValidationErrorResponse'
              - $ref: '#/components/schemas/ApiObjectResponse'
    Unauthenticated:
      description: Unauthenticated
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/ApiNullableResponse'
    IdempotencyConflict:
      description: Idempotency key was reused with a different payload
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/IdempotencyConflictResponse'
    ServerError:
      description: Server error
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/ApiObjectResponse'

  schemas:
    ApiObjectResponse:
      type: object
      required: [success, message, data, statusCode]
      properties:
        success:
          type: boolean
        message:
          type: string
        data:
          type: object
          additionalProperties: true
        statusCode:
          type: integer
    ApiNullableResponse:
      type: object
      required: [success, message, data, statusCode]
      properties:
        success:
          type: boolean
        message:
          type: string
        data:
          nullable: true
        statusCode:
          type: integer
    GenericObjectResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
    ValidationErrorResponse:
      type: object
      required: [success, message, data, statusCode]
      properties:
        success:
          type: boolean
          const: false
        message:
          type: string
          example: Validation failed.
        data:
          type: object
          required: [errors]
          properties:
            errors:
              type: object
              additionalProperties:
                type: array
                items:
                  type: string
        statusCode:
          type: integer
          const: 422
    IdempotencyConflictResponse:
      type: object
      required: [success, message, data, statusCode]
      properties:
        success:
          type: boolean
          const: false
        message:
          type: string
        data:
          type: object
          properties:
            code:
              type: string
              example: IDEMPOTENCY_KEY_CONFLICT
        statusCode:
          type: integer
          const: 409
    PaginatedResponse:
      type: object
      required: [success, message, data, statusCode]
      properties:
        success:
          type: boolean
          const: true
        message:
          type: string
        data:
          type: object
          properties:
            current_page:
              type: integer
            per_page:
              type: integer
            total:
              type: integer
            items:
              type: array
              items:
                type: object
                additionalProperties: true
          additionalProperties: true
        statusCode:
          type: integer

    AuthSessionResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          type: object
          properties:
            token:
              type: string
            token_type:
              type: string
              example: Bearer
            user:
              type: object
              additionalProperties: true
            tenant:
              type: object
              additionalProperties: true
          additionalProperties: true
    LicenseValidationResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          type: object
          properties:
            valid:
              type: boolean
            tenant_code:
              type: string
            license:
              type: object
              additionalProperties: true
          additionalProperties: true
    TenantResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/Tenant'
    TenantUserResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/TenantUser'
    CustomerResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/Customer'
    CollateralItemResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/CollateralItem'
    LoanContractResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/LoanContractSlip'
    InterestCalculationResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          type: object
          properties:
            slip_update_key:
              type: integer
            total_interest_amount:
              type: number
            interest_breakdown:
              type: array
              items:
                $ref: '#/components/schemas/InterestBreakdown'
          additionalProperties: true
    ExpenseResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/Expense'
    ExpenseDetailResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/ExpenseDetail'
    DebtResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          $ref: '#/components/schemas/Debt'
    TenantSettingsResponse:
      allOf:
        - $ref: '#/components/schemas/ApiObjectResponse'
      properties:
        data:
          type: object
          properties:
            branding:
              type: object
              additionalProperties: true
            contact:
              type: object
              additionalProperties: true
            tenant_setting:
              type: object
              properties:
                default_tenant_user_password:
                  type: string

    Tenant:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        tenant_code:
          type: string
        name:
          type: string
        subdomain:
          type: string
          nullable: true
        status:
          type: string
      additionalProperties: true
    TenantUser:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        name:
          type: string
        nrc:
          type: string
        email:
          type: string
          format: email
        phone:
          type: string
        address:
          type: string
          nullable: true
        role_id:
          type: integer
          nullable: true
        status:
          type: string
        update_key:
          type: integer
        creator_name:
          type: string
          nullable: true
        has_image_reference:
          type: boolean
      additionalProperties: true
    Customer:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        name:
          type: string
        email:
          type: string
          nullable: true
        phone:
          type: string
          nullable: true
        address:
          type: string
          nullable: true
        trust_score:
          type: integer
        note:
          type: string
          nullable: true
      additionalProperties: true
    CollateralItem:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        type:
          type: string
        item_type:
          type: string
        name:
          type: string
        description:
          type: string
          nullable: true
        estimated_value:
          type: number
        item_status:
          type: string
        quantity:
          type: integer
        image_url:
          type: string
          format: uri
          nullable: true
          description: Five-minute temporary URL; populated only by the collateral detail endpoint.
        image_url_expires_at:
          type: string
          format: date-time
          nullable: true
        has_image_reference:
          type: boolean
      additionalProperties: true
    LoanContractSlip:
      type: object
      properties:
        id:
          type: integer
        slip_no:
          type: string
        customer:
          $ref: '#/components/schemas/Customer'
        collateral_items:
          type: array
          items:
            $ref: '#/components/schemas/CollateralItem'
        loan_amount:
          type: number
        interest_rate:
          type: number
        interest_type_id:
          type: integer
        status:
          type: string
        update_key:
          type: integer
      additionalProperties: true
    Expense:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        description:
          type: string
        amount:
          oneOf:
            - type: number
            - type: string
        expense_type_id:
          type: integer
          nullable: true
        update_key:
          type: integer
      additionalProperties: true
    Debt:
      type: object
      properties:
        id:
          type: integer
        code:
          type: string
        description:
          type: string
        amount:
          oneOf:
            - type: number
            - type: string
        tag:
          type: string
          nullable: true
        is_paid:
          type: boolean
        update_key:
          type: integer
      additionalProperties: true
    InterestBreakdown:
      type: object
      required: [id, update_key, interest_amount]
      properties:
        id:
          type: integer
        update_key:
          type: integer
        interest_amount:
          type: number
        start_date:
          type: string
          format: date
          nullable: true
        end_date:
          type: string
          format: date
          nullable: true

    PasswordChangeRequest:
      type: object
      required: [current_password, password, password_confirmation]
      properties:
        current_password:
          type: string
        password:
          type: string
        password_confirmation:
          type: string
    TenantUserCreateRequest:
      type: object
      required: [name, nrc, email, phone]
      properties:
        name:
          type: string
          maxLength: 120
        nrc:
          type: string
          maxLength: 255
        email:
          type: string
          format: email
        phone:
          type: string
          maxLength: 30
        address:
          type: string
          nullable: true
          maxLength: 100
        role_id:
          type: integer
          nullable: true
        status:
          type: string
          default: active
    TenantUserUpdateRequest:
      allOf:
        - $ref: '#/components/schemas/TenantUserCreateRequest'
        - type: object
          properties:
            update_key:
              type: integer
              minimum: 0
      required: []
    CustomerWriteRequest:
      type: object
      required: [name]
      properties:
        name:
          type: string
          maxLength: 120
        email:
          type: string
          format: email
          nullable: true
        phone:
          type: string
          maxLength: 30
          nullable: true
        address:
          type: string
          nullable: true
        trust_score:
          type: integer
          minimum: 0
          maximum: 255
        note:
          type: string
          nullable: true
    CollateralItemCreateRequest:
      type: object
      required: [type, name]
      properties:
        type:
          type: string
          enum: [Jewellery, Normal, jewellery, normal]
        name:
          type: string
          maxLength: 120
        description:
          type: string
          nullable: true
        brand_name:
          type: string
          nullable: true
          maxLength: 80
        estimated_value:
          type: number
          minimum: 0
        material_type_id:
          type: integer
          nullable: true
        kyat:
          type: number
          minimum: 0
        pal:
          type: number
          minimum: 0
        yway:
          type: number
          minimum: 0
        item_status:
          type: string
          maxLength: 30
        contains_gemstones:
          type: boolean
        gemstone_details:
          type: object
          nullable: true
          additionalProperties: true
        quantity:
          type: integer
          minimum: 1
        minimum_retail_price:
          type: number
          minimum: 0
    LoanContractCreateRequest:
      type: object
      required: [customer, collateral_items, loan_amount, interest_rate, interest_type_id, expiry_quota, expiry_quota_type]
      properties:
        customer:
          $ref: '#/components/schemas/CustomerWriteRequest'
        collateral_items:
          type: array
          minItems: 1
          items:
            $ref: '#/components/schemas/CollateralItemCreateRequest'
        loan_amount:
          type: number
          minimum: 0.01
        interest_rate:
          type: number
          minimum: 0.01
        interest_type_id:
          type: integer
        notes:
          type: string
          nullable: true
        expiry_quota:
          type: integer
          minimum: 1
        expiry_quota_type:
          type: string
          enum: [Day, Week, Month, Year, day, week, month, year]
        created_by:
          type: integer
          nullable: true
    CollateralItemMultipartCreateRequest:
      allOf:
        - $ref: '#/components/schemas/CollateralItemCreateRequest'
        - type: object
          properties:
            image_reference:
              type: string
              format: binary
    LoanContractMultipartCreateRequest:
      allOf:
        - $ref: '#/components/schemas/LoanContractCreateRequest'
        - type: object
          properties:
            collateral_items:
              type: array
              minItems: 1
              items:
                $ref: '#/components/schemas/CollateralItemMultipartCreateRequest'
    InterestPaymentRequest:
      type: object
      required: [slip_update_key, payment_amount, interest_breakdown]
      properties:
        slip_update_key:
          type: integer
          minimum: 0
        payment_amount:
          type: number
          minimum: 0.01
        record_debt:
          type: boolean
        interest_breakdown:
          type: array
          items:
            $ref: '#/components/schemas/InterestBreakdown'
    RedemptionCreateRequest:
      type: object
      required: [slip_no, calculated_total, payment_amount, interests, debts]
      properties:
        slip_no:
          type: string
          maxLength: 60
        calculated_total:
          type: number
          minimum: 0
        payment_amount:
          type: number
          minimum: 0
        interests:
          type: array
          items:
            $ref: '#/components/schemas/InterestBreakdown'
        debts:
          type: array
          items:
            type: object
            required: [id, update_key, amount]
            properties:
              id:
                type: integer
              update_key:
                type: integer
              amount:
                type: number
        redemption_date:
          type: string
          format: date
          nullable: true
        notes:
          type: string
          nullable: true
        created_by:
          type: integer
          nullable: true
    ExpenseWriteRequest:
      type: object
      required: [description, amount]
      properties:
        description:
          type: string
        amount:
          type: number
          minimum: 0.01
        expense_type_id:
          type: integer
          nullable: true
    ExpenseUpdateRequest:
      type: object
      properties:
        description:
          type: string
        expense_type_id:
          type: integer
          nullable: true
        update_key:
          type: integer
          minimum: 0
        remove_image_reference:
          type: boolean
      additionalProperties: false
    ExpenseMultipartUpdateRequest:
      type: object
      properties:
        _method:
          type: string
          enum: [PUT]
        description:
          type: string
        expense_type_id:
          type: integer
          nullable: true
        update_key:
          type: integer
          minimum: 0
        remove_image_reference:
          type: boolean
        image_reference:
          type: string
          format: binary
    ExpenseDetail:
      allOf:
        - $ref: '#/components/schemas/Expense'
        - type: object
          properties:
            image_reference_url:
              type: string
              format: uri
              nullable: true
            image_reference_url_expires_at:
              type: string
              format: date-time
              nullable: true
    DebtWriteRequest:
      type: object
      required: [description, amount]
      properties:
        amount:
          type: number
          minimum: 0.01
        description:
          type: string
        slip_id:
          type: integer
          nullable: true
        tag:
          type: string
          nullable: true
          maxLength: 120
        is_paid:
          type: boolean
        accepted_by:
          type: integer
          nullable: true
    DebtUpdateRequest:
      allOf:
        - $ref: '#/components/schemas/DebtWriteRequest'
        - type: object
          properties:
            update_key:
              type: integer
              minimum: 0
      required: []
    TenantSettingsUpdateRequest:
      type: object
      properties:
        branding:
          $ref: '#/components/schemas/TenantBrandingUpdateRequest'
        contact:
          $ref: '#/components/schemas/TenantContactUpdateRequest'
        tenant_setting:
          type: object
          properties:
            default_tenant_user_password:
              type: string
            update_key:
              type: integer
              minimum: 0
    TenantBrandingUpdateRequest:
      type: object
      properties:
        primary_color:
          type: string
          maxLength: 30
          nullable: true
        secondary_color:
          type: string
          maxLength: 30
          nullable: true
        accent_color:
          type: string
          maxLength: 30
          nullable: true
        update_key:
          type: integer
          minimum: 0
    TenantContactUpdateRequest:
      type: object
      properties:
        address:
          type: string
          nullable: true
        phone:
          type: string
          maxLength: 40
          nullable: true
        city:
          type: string
          maxLength: 120
          nullable: true
        country:
          type: string
          maxLength: 120
          nullable: true
        update_key:
          type: integer
          minimum: 0
    OnlineSyncPushRequest:
      type: object
      required: [syncLogs]
      properties:
        syncLogs:
          type: array
          maxItems: 100
          items:
            type: object
            required: [tableName, activityType]
            properties:
              id:
                type: integer
                nullable: true
              tableName:
                type: string
                maxLength: 100
              activityType:
                type: string
                maxLength: 30
              recordId:
                type: string
                maxLength: 100
                nullable: true
              recordData:
                type: string
                nullable: true
              createdAt:
                type: string
                maxLength: 50
                nullable: true

security:
  - bearerAuth: []
```
