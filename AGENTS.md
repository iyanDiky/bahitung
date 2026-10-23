# AI development instructions — bahitung
You are working on **bahitung**, a multi-user household finance app. Read `docs/DECISIONS.md`, `docs/BUSINESS_RULES.md`, `docs/API_CONTRACT.md`, `docs/PRD-v3-source.md` and `docs/POSTGRESQL_NOTES.md` before editing business logic. Reference `design/*.png` when slicing UI.

## Stack
- Frontend: Vue 3 + TypeScript + Vite + Tailwind CSS v4 + Pinia + Vue Router, Lucide Vue Next.
- Backend: Laravel REST API, PostgreSQL; Controller -> Form Request -> Service -> Repository interface + implementation; authorization in Policies + services, DI via container.
- API base `/api/v1`. All currency IDR. **Decimal amounts transported as strings** (no JS floating point money calculations for persisted data).

## Hard rules
1. Every resource and permission must be scoped to the active household. Membership role is household-specific.
2. All wallets belong to a household; no private wallets in MVP.
3. Roles: owner, admin, member, viewer; member may correct own transaction any time with immutable reversal + audit.
4. Transfers: two balanced wallet movements; optional admin fee is linked expense. Do not book transfer principal as income/expense.
5. Budgets: warn on overspend, never block solely due to budget; explicit copy previous month action.
6. Persisted ledger rows immutable. Reversal adds counter-movements; balance projections are atomically updated and reconcilable.
7. Use database transactions, deterministic lock order, idempotency keys for financial writes. Avoid balance-negative states, including retroactive corrections.
8. Do not silently switch from PostgreSQL to MySQL. Existing DBML was authored for MySQL and is a **conceptual reference**; review PostgreSQL migration semantics.
9. Do not implement fake authentication in production. Frontend starter uses mock data only until API integration.
10. Validate build/test before declaring a feature done. Keep mock logic separate from API services.

## Phase priority
1. Vue app shell + dashboard (current mock UI)
2. Additional mock UI pages per designs
3. Laravel bootstrap and auth/household membership
4. Ledger + wallets + transactions with tests
5. Budgets + goals, analytics, integration

Before modifications, briefly state intended files, then implement and test. Avoid speculative new premium/paid features (reference mockups have such decoration only).
