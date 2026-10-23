# PostgreSQL migration checklist
`ERD-v3-original-mysql.dbml` was exported as MySQL concept design. Do not directly apply MySQL DDL to PostgreSQL.
- Laravel default `pgsql` connection. Use `bigIncrements()`/`foreignId()` in migrations; use `timestampsTz()` / `timestampTz()` where appropriate.
- Map enum concepts to constrained `varchar`/Laravel enum validation or PostgreSQL enum with carefully planned migrations.
- Monetary values `numeric(19,2)`, non-negative checks on balance projections and positive checks on transaction amounts; no float.
- Use composite indexes, unique `(household_id, idempotency_key)`, strong FK constraints, plus application household scope checks.
- `balance_movements`: exactly one of wallet_id and goal_id set (CHECK); never UPDATE/DELETE posted entries.
- `wallet_balances` and `goal_balances`: materialized projections, never independent source of truth; update under row locks inside DB transaction.
- Add durable idempotency response storage if implementing repeated HTTP responses precisely; request key + payload hash alone may not be sufficient.
- Prevent race conditions using `SELECT ... FOR UPDATE` and consistently sorted lock order.
- Understand backdated transaction invariants: a correction that does not result in negative current balances may still violate historical balances. Design and test before financial module production use.
- Database timezone UTC, household timezone for business dates and monthly reporting.
- Consider deletion policies and audit retention before deploying.
