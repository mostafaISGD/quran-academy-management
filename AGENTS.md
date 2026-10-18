# AGENTS.md — Quran Academy

>/rules for any coding agent (or human) working in this repo.
> Roadmap: `ROADMAP.md` — implement **one PR per session**, in order.
> Branch: `phase-N/pr-N.M-slug` · Commit: `feat(scope):` / `fix(scope):`

## Stack (verified)

**Backend** `backend/` — Laravel 11 · PHP 8.2+ · PostgreSQL · Redis · Sanctum 4 · spatie/laravel-permission 6 · PHPUnit 11
**Frontend** `frontend/` — Next.js 16.3 · React 19.2 · TypeScript 5 · Tailwind 4 (`@theme` in `app/globals.css`) · Vitest 3

### Before writing ANY frontend code

1. Read `frontend/AGENTS.md`.
2. Read the relevant guide in `frontend/node_modules/next/dist/docs/` — **this Next version has breaking changes**; do not code from memory of older Next.

---

## Security

1. **Every route inside `auth:sanctum` carries `permission:` or is on the justified exception list** in `RouteProtectionTest::EXCEPTIONS`. Undefined permission = denied (current `CheckPermission` behaviour — keep it).
2. **Never return `$e->getMessage()` to the client.** No secrets, no hard-coded passwords outside seeders.
3. Sensitive operations (refund, role change, reopening a period) go to `audit_logs` and need a specific permission.
4. `permission` must stay **inside** the `auth:sanctum` group. Putting the two in one array or moving `permission` outside the group makes `$request->user()` null and returns a **false 403** to legitimate users. `PermissionsSyncTest` + `RouteProtectionTest` guard this — both read the *resolved* middleware, not the declared string.
5. `frontend/lib/api.ts` stores the token in `localStorage` — **this is tracked debt**, replaced by HttpOnly cookies in PR 1.1/1.2. Until then, do not add new token storage.

## Money

6. **Money is integer in the smallest unit** (`bigint`). `float` is forbidden in any money path.
   - ⚠️ **Current state (PR 2.1 pending):** the cast in use is `app/Casts/Piastres.php`, which is **hard-coded to exponent 2 / EGP** (`intdiv($abs, 100)`) and still calls `round((float) $value * 100)` in `set()`. It returns a `string` from `get()` and a `float` from `fromDatabase()`. Treat it as the thing PR 2.1 replaces, not as the pattern to imitate.
   - Any aggregate (`sum()`, `value()`, `selectRaw`) bypasses the cast — it must go through `Piastres::fromDatabase()`, or you mix units (100× error).
7. **Financial records are never edited or deleted.** Correction is a new reversing entry (`reversal_of_id`).
8. Every write touching more than one table: `DB::transaction` + `lockForUpdate` on the contended row. Payments need a unique `idempotency_key`.
9. An invoice's `paid_amount` / `balance_due` / `status` are written by `InvoiceService` alone. Account balance = opening balance + sum of `account_movements`.
10. "Overdue" is **computed** from `due_date`, never stored as a fact.
11. `currency` columns already exist on ~20 financial tables. Multi-currency is real — do not collapse to EGP.

## Lessons

12. A lesson's status changes through `LessonService::transition()` alone (PR 3.1). Policy effects come from `lesson_policies` and are snapshotted on the lesson.
13. User-facing messages in **Arabic**; code, identifiers and commit scopes in **English**.
14. No file over ~400 lines in new code. Logic lives in services, not controllers.
15. **A new migration for every change** — never edit an old migration. `migrate:fresh --seed` is fine locally (no real data).
16. Demo seeders must refuse to run in production (PR 0.4 adds the guard).
17. **Every PR keeps the frontend working.** Any API contract change updates `frontend/lib/api.ts` and the affected pages in the same PR.
18. **Write in Arabic with a UTF-8-aware editor.** Do not use PowerShell `Get-Content`/`WriteAllText` round-trips on these files — they corrupt Arabic comments into mojibake and silently poison the diff. Use the editor tools.

## Testing

```bash
# backend — 427 tests, SQLite in-memory via .env.testing
cd backend && php artisan test

# frontend
cd frontend && npm run lint && npx tsc --noEmit && npm test
```

- Fixtures live in `tests/BuildsFixtures.php` — **extend it, don't duplicate it**.
- `tests/TestCase.php` gives `authHeaders()`, `getJsonAs()`, `postJsonAs()`. Use `authHeaders()` — it calls `forgetGuards()`, without which a second login in the same process inherits the first user and produces a bogus 403.
- Compare money with `assertEquals((float)…)`, never `assertSame` — JSON has no int/float distinction.
- Concurrency/lock tests need PostgreSQL: mark `#[Group('pgsql')]`. No group is configured in `phpunit.xml` yet (PR 7.1 adds CI).

### Known pre-existing failures (not yours)

- `npm run lint` — **10 errors** in `lib/use-page.ts`, `components/EmployeeProfile.tsx`, `components/TeacherPicker.tsx`, `components/TeacherScheduleEditor.tsx`, `components/ui/PromptDialog.tsx` (mostly `react-hooks/refs`). Debt from the `usePage` refactor (0789c18, 8121fe6). Do not attribute these to your PR; do not silently fix them either.

---

## Definition of Done (every PR)

- [ ] A test that **failed before** the fix and passes after it — the problem is proven, not assumed.
- [ ] All existing tests still pass. **No test deleted or disabled to make the PR pass.** If a test needs a permission added to its fixture, say so in the PR description — that is a behaviour change, not a fix.
- [ ] No route without a permission (matrix test green).
- [ ] The migration is reversible (`down()` works).
- [ ] `npm run build` passes if the API contract was touched.
- [ ] No out-of-scope changes and no temp files (`git status` clean apart from intended files).
- [ ] PR description: what changed, how it was tested, risks, and **what was not verified**.
- [ ] Tick the `- [x]` box for the PR in `ROADMAP.md` Section 5.

## Working with the roadmap

The roadmap was written from a **read-only review of Sprint 1**. The repo has since advanced. Before implementing a PR, verify its claims with `grep`/read — do not assume the roadmap describes current code. Known drift as of Phase 0:

- **Phase 0 was entirely undone.** All 8 critical findings were live (verified).
- Phase 1 is ~15% done: `permissions:sync` + `PermissionsSyncTest` exist and a single-source catalog is in place — but it lives in `SeedRolesPermissions::PERMISSIONS`, a **seeder constant**, not `config/permissions.php`. There is **no `app/Policies/` directory**; authorization is 100% route middleware + ad-hoc controller checks.
- Phase 2 is ~40% present as **schema + one `FinancialLedgerService`**, not as the four separate services the roadmap specifies.
- Phases 3–7 are essentially untouched.