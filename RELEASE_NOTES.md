# Queue Callback release notes

Project: [tcreek/queue_callback_v17](https://github.com/tcreek/queue_callback_v17/)

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

Version metadata is now `17.0.5.0 Beta 5`. No release package, GitHub tag or
publication was created by this version update. Stable-release verification
remains pending.
