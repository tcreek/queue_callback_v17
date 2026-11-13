# Queue Callback release notes

Project: [tcreek/queue_callback_v17](https://github.com/tcreek/queue_callback_v17/)

## Unreleased — strict shared FIFO

- Enforce original queue order at the member pre-dial boundary so newer live
  callers wait behind older pending callbacks, including retry cooldown.
- Preserve queue priorities, consistent same-second ties, existing pre-dial
  hooks, hold behavior and normal timeout/exit behavior. Disable single-offer
  Queue exit for guarded queues so refused offers retry while callers hold.
- Release the virtual position when a callback connects; retain it across failed
  attempts and release terminal, blocked and exhausted requests.
- Calculate returning-customer queue position behind older live callers. Add the
  `fifo_connected_at` migration and deploy `queuecallback-fifo.agi` on upgrade.
- Tests: 19 automated invocations pass. Isolated real-Asterisk tests with only
  synthetic Local channels verify cooldown holds, cancellation releases, and the
  connected AGI releases a returning customer's position. No real phones called.

## 17.1.0 — November 12, 2026

Stable release approved by the owner after confirming completion of the remaining
release tests. See [release verification](RELEASE_READINESS.md).

- Support customer-first and agent-first callback flows with returned-call press-1
  confirmation, configurable retries and an agent-only connection beep.
- Silently reject detected blocked numbers before callback request prompts and
  check entered alternate numbers before saving. Log terminal Blocked records
  with zero dialing attempts. Blocked numbers never become callback jobs; no
  destination selector or blocked-number announcement is used.
- Include Blocked labels, filtering and counts in Reports. Existing pending
  requests that match the blocklist are marked Blocked before dialing.
- Add native FreePBX 17 Backup/Restore adapters for queue settings, complete
  request history and security rules. Preserve scope, enabled state, ordering
  and IDs; support older missing sections and explicitly empty lists. Database
  write failures roll back restoration. Obsolete destinations are ignored.
- Include all runtime helpers, views, AGIs and 21 recordings in the manifest.
- Validation: 18 automated invocations, syntax checks for 51 PHP/AGI files,
  installed-AGI blocklist checks and release archive/checksum verification.

### Upgrade from beta

Back up FreePBX and module data, upload `qcallback-17.1.0.tgz` through Module
Admin, complete Upgrade & Install, then Apply Config. Additive schema migrations
preserve historical statuses and add Blocked reporting. Old blocklist destination
settings are ignored. Include referenced custom recordings and destination
modules in FreePBX backup jobs. Restore on a controlled PBX first because
restored pending requests can resume dialing.

## 17.0.5.1 Beta 5.1 — November 8, 2026

- Accepting an entered alternate callback number with 1 now also confirms the
  request, removing the second acceptance prompt. Detected caller ID requests
  retain their existing final confirmation.
- Add an agent-only connection beep when an agent-first callback bridges after
  customer confirmation. Use the installed Asterisk beep and clear any pending
  tone if Dial returns without a bridge. Live audio verification remains pending.
- Update configuration help and add scripted checks for single acceptance,
  cancellation, invalid choices, changing numbers and storage failures.
- Validation: generated request-menu and return-call confirmation checks pass,
  along with PHP syntax checks. The database-backed settings test was not
  completed because database access was declined. Live-call verification of
  this fix remains pending.

## 17.0.5.0 Beta 5 — November 7, 2026

New beta version: `17.0.5.0 Beta 5`. Adds returned-call confirmation and includes
the reliability, installation and configuration changes below. The project
owner confirmed the corrected initial callback-confirmation flow works in a
live test on November 7. This remains a beta; full stable-release verification
is still pending. This version update does not publish a GitHub release or tag.

### Changes

- Set the retry default to one minute and display retry settings in whole
  minutes starting at 1. Keep label text on one line above its input or menu.
- Fix installed AGI helper discovery and FreePBX bootstrap scope, apply missing
  request columns, and supply NODEST when originating a FreePBX queue agent.

- Preserve the initial Confirm Callback Number on/off setting. When enabled,
  play the full final request-confirmation prompt before collecting a fresh
  press 1; ignore early digits during playback so they cannot skip the prompt.
  Playback failure, wrong final input, hangup or timeout cancels. Validate final
  DTMF and Read status in the store AGI; allow detected-number requests only when
  saved queue configuration explicitly disables initial confirmation.
- Add per-queue Return Confirmation Timeout (10 seconds by default) and Return
  Confirmation Repeats (1 additional play by default) to both settings forms.
  Invalid keys play Asterisk's option-is-invalid; silence and invalid input
  share a bounded repeat budget. Agent-first calls play failed-to-confirm to the
  waiting agent after an unconfirmed customer leg ends. Add additive schema
  migration, validated saves, call-file propagation and scripted flow coverage.
- November 7: Replace the return announcement with `callback-return-confirm`
  and add `failed-to-confirm` in WAV, mu-law and A-law. Rename the setting to
  Return Call Announcement - Confirmation and require press 1 in both modes
  before connecting. Unconfirmed answers follow retry rules; completion requires
  confirmation as well as bridge evidence. Preserve callback-request prompts.
  Deployed AGI subprocess tests cover confirmed and unconfirmed answers using
  temporary tables. Initial request confirmation is user-verified live; the
  complete returned-call audio, DTMF and voicemail matrix remains pending.
- Serialize callback dispatch and publication, atomically publish complete call
  files with restricted permissions, and reject stale or duplicate claims.
- Order callbacks by queue priority and original entry time, retain retry order,
  require complete live AMI state, and exclude unavailable, paused, busy and
  wrapping members in both modes. Share capacity reservations across workers.
- Let the module own retries in seconds. Require bridge evidence for completion,
  reject late results and reconcile unsuccessful originates from archived files.
- Enforce selected outbound routes and their time conditions. Honor confirmation
  settings and alternate-number keys, including zero, star and pound.
- Validate AGI/web requests, check queue availability at insertion, and announce
  success only after storage succeeds. Missing/failed AGIs fail closed.
- Match blocklist patterns rather than area-code labels; preserve global/queue
  scope, validate numeric Asterisk patterns and defer on unreadable/invalid rules.
- Share installer, schema, AGI, cron and dialplan implementations. Retain legacy
  entry points as adapters and remove stale contexts for disabled queues.
- Remove the installer’s inline database login. Use FreePBX’s shared connection
  for the check AGI and sanitize database/AGI failure reporting.
- Replace the project URL, correct installation guidance and narrow compatibility
  statements to the documented reference environment and verification scope.

### Upgrade notes

1. Back up the FreePBX configuration and module tables before installation.
2. Use a package built from the cleaned source with a `qcallback/` root folder,
   containing `module.xml`, all shared PHP helpers, AGIs, views and announcements.
   Old beta packages are not substitutes for this source revision.
3. Run the module upgrade/install, then Apply Config. Installation adds
   `queue_joined_at`, `queue_priority`, `reserved_agent` and the dispatch index.
   Existing requests/configurations are preserved. Requests lacking original
   entry metadata fall back to request time and priority zero.
4. Review saved outbound routes: selections are enforced, and missing routes
   do not silently fall back. Automatic routing is an explicit choice.
5. Review blocklist patterns. Area code is now a label. Match the intended full
   number or numeric pattern, including required country/dialing prefixes.
   A leading `+` is equivalent; other prefixes are not inferred or removed.
6. Match instruction recordings to the callback and alternate-number keys.
   Retry and processing intervals are seconds; announcement-frequency legacy
   values retain their documented conversion.
7. Confirm the installer’s four staggered cron runs per minute are present for
   `process_callbacks.php`. Avoid installing a second scheduler under another
   user. The legacy processor name delegates to the same worker.
8. If an older installer contained real credentials, rotate the exposed account
   on the affected deployment and coordinate all consumers. Source removal
   does not revoke credentials; replacing the module alone does not rotate them.

### Verification and limitations

All ten automated suites passed on the reference environment, including both
hook-loading orders, temporary-database upgrades, dispatch, AGI protocols,
settings and generated dialplan tests. PHP syntax and manifest checks passed.
These results do not establish full upgrade/reinstall or live call compatibility.
See README for the inspected reference versions.

Dispatch uses a live snapshot and logical reservations between callback workers;
it cannot physically reserve an Asterisk agent. Ordering is per queue. Missing
terminal call evidence remains processing for investigation. Compound recordings
joined with `&` are unsuitable for periodic announcements; use one recording.

### Stable-release gate

Complete and record these on the intended deployment before publishing stable:

- Fresh installation, upgrade with existing data, repeat installation and
  uninstall/reinstall, with correct schema, AGIs, cron and generated contexts.
- Customer-first and agent-first calls through internal, Automatic and selected
  outbound routes, including route time windows and missing-route behavior.
- Earlier live callers, priorities, busy/paused/wrapping members, shared agents,
  retry cooldown, simultaneous workers and duplicate requests.
- No answer, busy, caller/agent hangup, exhausted attempts, archive reconciliation
  and correct bridge-based completion.
- Confirmation on/off, alternate keys `0`, `*`, `#` and None, storage failures,
  blocklist matches/scope and missing AGIs.
- Periodic announcements, callback-key/IVR coexistence and unrelated queue routing.

The beta-era gate above is retained as the test matrix. The owner confirmed
completion for 17.1.0; see RELEASE_READINESS.md for the release record.
