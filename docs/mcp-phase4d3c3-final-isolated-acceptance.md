# Bambodia MCP — Phase 4D.3C.3 final isolated evidence review

Date: 2026-11-28 (Cambodia time).
Decision: **PASS — isolated Project WRITE acceptance; production WRITE acceptance deferred.** Phase 4E architecture and isolated development may proceed. This is a readiness recommendation, not permission to deploy or perform production business mutations.

## Evidence provenance and immutable references

The operator supplied the successful Phase 4D.3C.2D terminal transcript in the conversation. The reviewer reconciled its reported markers with the exact acceptance sources, commit and six GitHub workflow records. No direct access to the private aaPanel logs, independent rerun or cryptographic attestation of the pasted transcript was available. Retain original private evidence; do not publish raw logs, keys, tokens, database contents or credentials.

| Reference | Value |
| --- | --- |
| Production release | `c1db97f9798cdd0e9291fb943d5a1a697c2b9f48` |
| Production tree | `fab3c6c0611b8f54955673cd2e110ef23994bcf7` |
| Activated OAuth-overlay controller blob | `a302fa364f1f52b6320b77688b7595b91894ca13` |
| Executed corrected acceptance commit | `2d9617b1ccf37276fc3a5322e7464ff191f6d52e` |
| Executed acceptance tree | `ec8b3b0718fb0374f86ce51a83ea6e5d3f975ad1` |
| Parent acceptance commit | `6f08d41c4dfa58abde6f5a77cb6d30eba02190f9` |
| Acceptance branch | `operator/mcp-phase4d3c2-isolated-write-20261128` |

The production main ref and its tree were independently checked against GitHub during this review. The executed acceptance commit adds only five files relative to production main: the four acceptance executables and their documentation. The deployed OAuth overlay is not the same as a clean checkout of main; the operator uses the sealed source-only stage and exact six-file release package. Existing application and CI files remain unchanged.

Server-local evidence references (paths, not shared files): `/root/bambodia-phase4d3c2-evidence.Ty4Fw8yb` and `/root/bambodia-phase4d3c2-corrected.l6l0KVX7/acceptance.log`. Earlier failure evidence: `/root/bambodia-phase4d3c2-evidence.hNXxygPQ`. The successful run confirmed the earlier undefined legacy annotations key at line 860 of the old harness, corrected registry compatibility, and passed all mandatory gates.

| Acceptance artifact (under support/qa/phase4d3c2/) | Git blob |
| --- | --- |
| guard.php | c459bc92a4fa13af2007e13001503770841c13b1 |
| oauth-worker.php | 1b03584ddd569ee4ef934cc1acb06efa47ee7496 |
| BambodiaPhase4D3C2MysqlOAuthTest.php | 5855477ed66085acd593c78e4203fdb5dd8b8583 |
| bambodia-phase4d3c2-isolated-write.sh | b190275fdfa69ce8f8f486fdb9982c1ab465c6d2 |

The related executed-commit documentation blob is d65009cf07a2bee1cfbad6fe52767be76ca1cefe. Its preparation-time wording is historical; this report records the subsequent successful run.

## Actual isolated test results

| Suite | PHPUnit tests | Assertions | Result |
| --- | ---: | ---: | --- |
| MySQL OAuth all fourteen new tools | 1 | 562 | PASS |
| Extended OAuth concurrency and strict confirmation | 1 | 578 | PASS |
| Existing Project WRITE service tests | 8 | 100 | PASS |
| Total | 10 | 1240 | PASS |

24 strict invalid-input cases (six per tool: missing, false or string confirmation; invalid UUID; invalid version; unknown argument) and four authenticated contention scenarios are part of the 578-assertion test, not additional PHPUnit tests. Six standalone MySQL service contention scenarios passed independently, outside the PHPUnit totals.

Four authenticated kernel-request races: duplicate task creation, competing task updates with identical version and distinct UUIDs, duplicate milestone creation, duplicate comment creation. Independent PHP processes use real isolated Passport bearer grants and observed MySQL locking queries. Each tests the deployed four-tool overlay without changing production. The six service-level scenarios include same-UUID task, competing updates, same-UUID milestone, duplicate comment/notification/outbox, cross-participant contention and late transactional rollback with waiting retry. Do not describe all ten as OAuth races or claim the injected rollback was tested through an independent bearer worker.

Verified checks include all 38 tool names and OAuth security scheme copies; exact four annotation hints on fourteen new definitions (legacy annotation presence not retroactively required); successful use of all ten new READ and four new WRITE tools in isolation; READ-scope and disabled-WRITE rejection; participant gates; strict input denials with unchanged relevant fixture table hashes; stale state version rejection; identical replay and differing-payload UUID conflict; ledger/audit linkage; notification and outbox deduplication; late transactional rollback in the service suite. Positive license and milestone READ tests passed in isolation. Prior OAuth lifecycle tests remain separate evidence; not every earlier lifecycle test was rerun here.

The isolation operator reports private MySQL identity and no TCP, independent OAuth keys, locked Composer dependencies, source checks before and after, and disposable runtime/key removal. Composer preparation required dependency-download network access; application acceptance runs in a network/PID/mount namespace, with test array mail transport and a private MySQL socket. Synthetic payment eligibility and notification/outbox records were test data only. The transcript reports no production database access, no production file changes, no external delivery attempted by the operator, no production service restart. 652 unchanged baseline source files, all six accepted overlay files and protected configuration were verified before and after. These are operator evidence, not a new independent server inspection.

## Release CI evidence

All six workflow runs were independently retrieved from GitHub; each completed successfully with the exact production head c1db97f9798cdd0e9291fb943d5a1a697c2b9f48.

| Workflow | Run |
| --- | ---: |
| Quality | 46132893142 |
| MySQL Installer Acceptance | 46132893168 |
| Operational Acceptance | 46132893148 |
| Browser Emulation Acceptance | 46132893110 |
| Isolated Authenticated Journeys | 46132893454 |
| Build Installer | 46132893144 |

Prior Quality: 684 tests / 10305 assertions. Prior MySQL service suite: eight tests / 100 assertions and six service scenarios. These are separate prior execution counts, not added to the 1240 new assertions. The operator-only acceptance files are not a new merged CI workflow.

## Outstanding live acceptance items and risk decision

- Phase 4D.3A: connector discovery 38 tools (25 READ, 13 WRITE) and live authenticated overview confirmed after refresh in the earlier review. Independent raw live server tools/list response and annotations were not captured in that conversation. The isolated registry is fully checked; cached connector discovery is not the raw live registry.
- Phase 4D.3B: conditional pass; 23 of 25 live READ tools successfully executed in the earlier review, including empty authorized lists. Four expected eligibility/authorization-not-found responses investigated. `bambodia_license_get` and `bambodia_milestones_list` remain positive-path live checks pending accessible license UUID and client-owned contract ID respectively. Do not invent IDs or create records just to test. Live cross-account isolation was not independently exercised using two authorized production identities. The isolated tests do not remove these live limitations.
- Phase 4D.3C.4: zero live business WRITE executions for the four new project mutations. Genuine paid customer projects are not disposable test fixtures. Task creation, update and comment can notify another participant and may queue email; deletion or rollback cannot undo an externally delivered message. A suitable deliberately authorized project or draft contract, participant consent and exact action approval would be required for each future live positive-path test. Keep this phase explicitly DEFERRED, not PASS, until genuine safe conditions exist. No artificial production payments, credentials, notification suppression, or destructive cleanup.
- Annotation follow-up: task create/update report openWorldHint=false despite their participant notification and conditional email effects. The new harness correctly verifies deployed behavior, not the appropriateness of the hint. Review conservative annotation changes separately; do not silently change metadata during this evidence review. Idempotency applies to an identical request with the same UUID and payload, not arbitrary new UUIDs.

## Phase 4E entry recommendation

Proceed to Phase 4E Native Invoice Management architecture, existing native-model/route/schema review, permission design and isolated tests. This is an engineering readiness recommendation only. It does not authorize production files, migrations, real invoices, payment state changes, emails, merge or deployment. Define invoice lifecycle and native accounting boundaries before implementation; distinguish invoice, order, receipt, payment and settlement. Preserve all existing 38 definitions and OAuth permission boundaries; use participant-scoped privacy-preserving READ and, if approved, separately scoped explicitly confirmed idempotent WRITE actions. Require isolated acceptance for invoice arithmetic/currency, authorization, numbering, state transitions, concurrency, audit and any email/outbox behavior. Do not assume new invoice endpoints or schema until repository review establishes them.

## Final status

```text
PHASE4D3C2D_OPERATOR_EXECUTION=PASS (operator transcript)
TOTAL_PHPUNIT_TESTS=10
TOTAL_PHPUNIT_ASSERTIONS=1240
STRICT_INPUT_VALIDATION_CASES=24/24
AUTHENTICATED_CONCURRENCY_SCENARIOS=4/4
SERVICE_CONCURRENCY_SCENARIOS=6/6
PRODUCTION_MAIN_VERIFICATION=PASS
ISOLATED_ACCEPTANCE_SOURCE_VERIFICATION=PASS
LIVE_READ_ACCEPTANCE=CONDITIONAL_23_OF_25
LIVE_RAW_REGISTRY_INDEPENDENT_VERIFICATION=PENDING
PRODUCTION_WRITE_ACCEPTANCE=DEFERRED_NOT_TESTED
PHASE4D3C3_EVIDENCE_REVIEW=PASS
PHASE4E_ARCHITECTURE_AND_ISOLATED_DEVELOPMENT=RECOMMENDED
PRODUCTION_DEPLOYMENT_AUTHORIZATION=NOT_GRANTED
```

No business WRITE, production database access, customer notifications, production file/configuration modification, service restart or branch merge was performed by this review. The only planned repository mutation is adding this evidence report to the existing dedicated acceptance branch. Preserve the executed acceptance commit as the immutable test reference.
