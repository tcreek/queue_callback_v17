# Stable release readiness — November 12, 2026

Decision: release **17.1.0 stable**, approved by the owner on November 12, 2026.

The owner previously reported successful agent-first, caller-first and blocklist
live tests, then confirmed that the remaining requested tests were completed and
instructed promotion to release. This includes the requested silent-blocking,
voicemail/no-answer retry, backup/archive restore and installation/upgrade checks.
These are owner-reported results; automated evidence is listed separately below.
No additional call recordings or individual result logs were supplied.

## Verified environment

| Component | Observed version |
| --- | --- |
| Asterisk | 22.10.1 |
| FreePBX Framework | 17.0.33.2 |
| Core | 17.0.18.54 |
| Queues | 17.0.4 |
| PHP | 8.2.35 |
| MariaDB | 11.8.6 |
| Module before promotion | 17.0.5.1 Beta 5.1 |
| Release version | 17.1.0 |

Versions were read from the running host. These checks do not certify other
FreePBX distributions or versions.

## Automated evidence

All 18 test invocations passed: the 17 PHP test scripts in `tests/`, with
`callback_entrypoints_test.php` run in both `functions-first` and `hooks-first`
modes. All 51 PHP/AGI source and test files passed syntax checks.

Coverage includes:

- Installed AGI discovery/bootstrap and request/result protocols.
- Request validation, confirmation settings and failure handling.
- Customer-first and agent-first generated dialplan and return confirmation.
- Queue priority, agent readiness, shared capacity, duplicate suppression,
  concurrent claims, atomic call-file publication and spool failure recovery.
- Completion, retry cooldown and archive reconciliation.
- Route selection and route time conditions.
- Repeatable fresh/partial schema setup and additive upgrades using temporary
  tables; packaged AGI/sound copying and cron text generation using fixtures.
- Blocklist grammar, queue/global scope, silent blocking and report forms.
- Blocked audit logging, schema migration, report filtering and logging failure.
- Every enabled live blocklist rule: 32 rules, 64 installed-AGI checks across
  applicable queues and number formats, using temporary request tables. Each
  produced a terminal Blocked record with zero attempts and a silent-block flag.
- Manifest coverage of every runtime helper, view, AGI and packaged recording.
- Backup/restore snapshots, transactional rollback, legacy missing sections,
  empty lists, request history/IDs and FreePBX adapter integration.

Tests used connection-local temporary database tables and temporary spool/files.
They did not place calls, alter live requests, uninstall the live module or run
a full FreePBX installation lifecycle.

## Live evidence

| Date | Test | Result | Evidence |
| --- | --- | --- | --- |
| November 12, 2026 | One agent-first callback | Passed | Owner reported that it called the agent first and worked |
| November 12, 2026 | Caller-first callback | Passed | Owner reported a successful test |
| November 12, 2026 | Blocklist | Passed before silent-blocking change | Owner tested blocked numbers, then requested removal of destination/announcement |

| November 12, 2026 | Remaining requested release tests | Completed, owner-reported | Owner confirmed tests completed and requested stable release |

The owner's final completion confirmation covers the remaining requested tests.
Automated tests do not independently prove live audio or physical call behavior.

## Packaging fixes completed during review

All 21 packaged recordings and native Backup/Restore adapters are declared in
`module.xml`. The removed destination-selector view is no longer packaged.
`tests/callback_manifest_test.php` checks missing assets and duplicate entries.

The archive is built from the manifest plus `module.xml`, with a `qcallback/`
root directory. Hidden workspace directories and deployment data are excluded.
Archive contents, embedded version, PHP syntax and SHA-256 are verified after
building. The archive is unsigned. No GitHub tag or hosted release is published
by local archive preparation.

## Release acceptance

The owner confirmed completion of the requested remaining release checks and
approved stable promotion. Version metadata, README and release notes now use
17.1.0. Automated checks and the archive are revalidated for the final source.
This records acceptance on the reference deployment, not certification of all
FreePBX distributions or every possible queue, route and agent configuration.
