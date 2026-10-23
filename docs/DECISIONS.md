# bahitung — approved decisions
Name: **bahitung** (previous docs call it RumahFin; treat as historical naming). Status: UI mockup approved, backend not implemented.
- Household multi-user, one user may join multiple households; shared household wallets only.
- Owner / Admin / Member / Viewer scoped per active household.
- Member can correct/void own operations at any time if balance and historical consistency permit; audit and reversal mandatory.
- Expense may exceed category budget; show warning but allow if wallet has sufficient balance.
- Copy last month's budget manually then edit, never automatic recurring copy.
- Transfer admin fee is separate linked expense in same atomic transfer operation.
- Goals hold allocated funds, do not count twice in assets.
- UX inspiration: Budggt demo, but UI must be own original bahitung identity with electric blue accent (#2563EB, #3B82F6) and Indonesian labels.
- Deploy later; local first. Docker PostgreSQL exposed **only to localhost:5433**.
