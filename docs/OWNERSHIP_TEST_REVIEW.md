# Ownership V2 test review through OWN-12

The user accepted OWN-07 and authorized writing tests through OWN-12. This review records written expectations, not passing behavior. Production code is unchanged. The user accepted these test expectations on 2026-10-22 after reviewing this document; they did not perform a full line-by-line code review. PHPUnit, workers, commands, queues, database queries and migrations have not been executed for this batch.

## OWN-12a coverage reconciliation

Earlier OWN-05 tests cover exact quantities/costs, typed commands, public DTOs/pages and primitive movement deltas. OWN-06 covers canonical fully paid purchase acquisition, source/tenant validation, immutable lots, replay and UNIQUE identity. OWN-07 covers receipt capacity, exact serialized membership, disposal/return/reacquisition and eligibility. The following cases extend those accepted expectations; prior cases remain regression coverage.

| Unit | Written cases | Expected behavior |
| --- | --- | --- |
| 08a | OwnershipSaleTest: exact reservation | Reserve four of ten; reject 6.001; reserve remaining six without physical issue. |
| 08b | OwnershipSaleTest: source release | Partial/full same-source release; excess, unrelated and repeated releases preserve all business rows. |
| 08c | OwnershipSaleTest: consumption and caller rollback | Reduce owned/reserved together; cannot consume another source; Inventory issue and Ownership consumption roll back together. OWN-07 piece history covers exact episode closure. |
| 08d | OwnershipReturnTest: immediate reduction | Direct reduction cannot strand existing reservations. Existing Sales callers reserve before reducing; coordinated development must move them to reserved consumption. No new positive unreserved workflow is invented. |
| 08e | OwnershipPledgeTest and accepted OwnershipEligibilityTest | Sale/pledge share free received-backed capacity; ownership, custody and cash remain distinct. |
| 08f | OwnershipPledgeTest: pledge release | Partial/full release stays source-bound; another loan cannot supply remaining quantity. |
| 08g | OwnershipPledgeTest: forfeiture | Fully pledged ten can be forfeited despite zero free quantity; wrong source, excess or wrong piece rejects; history stays immutable. |
| 08h | OwnershipReturnTest and accepted OwnershipPieceHistoryTest | Original purchase return reduces free linked original goods; original sale return restores the original lot/pieces; repeated, wrong-source and excess returns reject. These are internal primitives, not public financial return workflows. |
| 09a | OwnershipHttpTest: access matrix/manual gate | Real middleware enforces both features and list permission; inactive manual acquisition denies direct writes despite create permission or privileged payload flags. |
| 09b | OwnershipHttpTest: candidate read/forbidden write/foreign path | List-only can read candidates; manage permits linking; foreign path is 404. OWN-07 covers accessible invalid association 422; OWN-05 command tests ignore authority fields. |
| 09c | OwnershipReadTest: 105 items, 26 lots, holding/search/currency, invalid filters | Stable pagination exceeds old caps; depleted and awaiting-receipt records remain accessible. Invalid filters reject; item and lot pages are independent. OWN-05 covers default/max page metadata. |
| 09d | OwnershipReadTest: history and receipt-scoped candidates | Newest occurrence/ID first with source/reserved delta/recording provenance; candidate pages select pieces from the chosen receipt only. |
| 09e | OwnershipReadTest: measured query counts/serialization | Compare warmed one-item and 25-item pages; serialization executes zero queries; summaries omit embedded lot/piece collections. This is not an EXPLAIN or timing result. |
| 09f | OwnershipPhotoTest | Real private test filesystem and signer reuse Inventory photo URLs/expiry; missing/foreign reference returns null; raw storage fields are absent. Browser placeholder remains manual confirmation. |
| 09g | OwnershipHttpTest: link/version/replay/refresh | Required key; successful logical mutation increments once; original replay precedes stale validation; fresh stale key 409; refreshed version links remaining quantity. |
| 10a | OwnershipAtomicityTest and OwnershipSaleTest rollback | Caller failure removes acquisition, receipt, link, movement, audit, completion and physical issue effects together. |
| 10b | OwnershipAtomicityTest failure provider/internal replay; OwnershipHttpTest public replay | Observed real movement/audit/completion write injections roll back and permit same-key retry. Internal replay returns normally so its caller can continue. |
| 10c | OwnershipReplayScopeTest; accepted OwnershipReplayTest | Changed payload 409; lost-response replay has no duplicate mutation; tenant/operation scopes are distinct. |
| 10d | OwnershipConcurrencyTest: source acquisition/piece linking | Independent processes contend on actual locks; only one source acquisition/current piece membership remains. |
| 10e | OwnershipConcurrencyTest: sale versus pledge | Competing final fungible quantity or exact piece cannot both be reserved. |
| 10f | OwnershipConcurrencyTest: reversed input | Reversed item/lot/source lists complete within worker bounds and produce correct balances. |
| 10g | OwnershipConcurrencyTest: link before sale | Waiting sale sees the committed newly linked membership, rather than a stale pre-lock snapshot. OWN-07 rejects the same unlinked state without a concurrent commit. |
| 11a | OwnershipMonthlyTest: first checkpoint | Header and lot totals agree with independent full-ledger sums, including waiting and zero lots; business rows unchanged. |
| 11b | OwnershipMonthlyTest: duplicate/next month | Duplicate publication changes no checkpoint or version; next month carries the previous base plus uncovered deltas. |
| 11c | OwnershipMonthlyTest transition provider; OwnershipMonthlyReadTest new acquisition | Acquisition, reserve, release, consume, pledge, release pledge and forfeiture after checkpoint agree with full-ledger SQL. |
| 11d | OwnershipMonthlyTest watermark; OwnershipMonthlyReadTest cutoff/null recording time | Current-month rows below watermark and later IDs remain visible once. Null legacy recording times never become covered. |
| 11e | OwnershipMonthlyTest first/missing month | First deployment uses the ledger; a skipped month falls back to an older base plus the complete tail. |
| 11f | OwnershipMonthlyReadTest timezone/cutoff; OwnershipMonthlyTest invalid months/failure/enclosing transaction | Tenant-local exclusive cutoff remains pinned after timezone change; context restored on success/failure; malformed/current/future periods and nested publication reject. |
| 11g | OwnershipMonthlyTest source and piece cases | Checkpoints never authorize another sale's or loan's release; exact current memberships remain live. |
| 11h | OwnershipConcurrencyTest summary-direction provider | Append-first and summary-first each observe a real connection lock wait and retain each live delta once. |
| 11i | OwnershipConcurrencyTest duplicate summaries; OwnershipMonthlyTest injected second-item failure | One header per item/month; failed item has neither header nor children; retry preserves already committed items. |
| 11j | OwnershipMonthlyJobTest | Root pins tenant month, one child dispatch, scheduled queue/overlap, real tenant publication/recovery command, scheduler registration, existing success/error logging. Dispatch/logging boundaries are explicitly mocked where stated. |
| 11k | OwnershipMonthlyReadTest SQL capture | Operational and incremental SUM queries reference checkpoint, watermark and server recording cutoff; full-ledger correctness is checked independently. Execution plans/timing remain OWN-33. |

## Proposed interfaces and schema dependencies

These tests deliberately call missing production behavior. Missing interfaces/tables are implementation or harness blockers, not assertion failures, skipped coverage or passing tests.

- Continue the previously reviewed `OwnershipReceiptLinkRequest::fromValidated(payload, key)` and `OwnershipService::linkReceipt(ownedCode, lotCode, request)` contracts. Worker adapters use the same factory and service signatures.
- Read entrypoints proposed here are `itemPage`, `movementPage`, `receiptLinkCandidates`, plus the previously proposed `lotPage`. Filters are `OwnershipListFilter`, `OwnershipLotFilter`, `OwnershipMovementFilter` and `OwnershipCandidateFilter`, each using `fromValidated` and typed page responses. Names and mappings may be adjusted at implementation review without weakening behavior.
- Quantity actions use exact strings and selected `serializedItemCodes`, including release/consume/pledge/forfeit. Pledge resolver-output fixtures use the earlier proposed `OwnershipPledgeSource`; they do not prove Part 8 authorization, cash or lender custody.
- Monthly service follows existing Inventory conventions: `OwnershipMonthlySummaryService::summarizeTenant(tenantId, month)` and `completedMonthForTenant`. Job is proposed as `SummarizeMonthlyOwnershipMovementsJob`; recovery command as `ownership:summarize-monthly`. Tests do not run either during writing.
- Proposed header mapping: `ownership_monthly_checkpoints` with `tenant_id`, `owned_item_id`, `month`, `timezone`, `cutoff_at`, `movement_watermark`, owned/reserved/pledged totals and completion. Child mapping: `ownership_monthly_checkpoint_balances` with header/lot references and exact three balances. Child snapshots scope through tenant-owned headers, not an invented child tenant column. Final names/constraints/cascade cleanup belong to OWN-15 review.
- Existing `ownership_movements.created_at` is the server recording timestamp used by SQL coverage; public DTOs call it `recorded_at`. The tests require no additional raw timestamp column. Occurrence time is independent of checkpoint inclusion.
- `CommittedOwnershipFixture` shares the existing exact-tenant cleanup and worker lifecycle between committed base classes. `OwnershipMonthlyTestCase` combines that lifecycle with real quantity helpers. Acquisition assertions use the selected frozen server clock so later-month fixtures do not incorrectly require October 15 recording time.

## Review decisions and limits

The old immediate reduction is still called by Sales after reservation. OWN-08d tests forbid bypassing those commitments; development must coordinate those callers with reserved consumption. A supported positive unreserved caller was not found, so no new bypass API is specified. The existing method may be retired during coordinated activation; the regression expectation can then move to caller wiring rather than retaining an unused method solely for the test.

Injected mutation/audit/completion failures require observing the selected actual write. Completion injection matches the `completed` binding, not the earlier processing-key INSERT. Full snapshots include audit and return source rows. Failed idempotency diagnostics may remain under the existing policy, but completed/processing keys may not survive failed mutations.

Concurrency tests use committed disposable fixtures, real worker processes, observed MySQL lock waits and bounded waits. They must run only against the prepared isolated schema with metadata permissions. They assert business rejections separately from unexpected worker exceptions; missing code or lock-inspection permissions cannot count as a successful contention result.

## OWN-12 status

Static verification on 2026-10-22: PHP syntax passed for 44 Ownership PHP files; 22 concrete feature classes loaded through Composer without booting Laravel or invoking tests; extracted committed-fixture lifecycle matched its staged parent body; `git diff --check` passed. The new batch adds 58 public test methods across 12 feature classes, with provider cases in addition. These checks establish parse/load consistency only.

- **12a:** Child-by-child reconciliation is written above. The user accepted the batch expectations and interface mappings through this document; the large OWN-03 matrix remains provisional as agreed.
- **12b:** Pending. Writing authorization does not authorize execution. Schema, feature registration, services, DTOs, source resolvers, photo/read wiring, checkpoints and jobs are still production prerequisites. No runtime pass/failure totals are available.
- **12c:** Accepted by the user on 2026-10-22 after reading this review document. This accepts intended behavior, not runtime correctness or measured speed. Pending database execution is not waived.

Development may proceed only according to the checklist's reviewed next stage. This batch does not implement OWN-13 gates, OWN-14 helpers or OWN-15 schema.
