# Queue Callback for FreePBX / Asterisk

Queue callbacks for **FreePBX 17**, targeting **Asterisk 22**. Callers can
request a callback instead of waiting on hold. Dispatch checks queue order and
live agent readiness before publishing an attempt; see the limitations below.

Project: [tcreek/queue_callback_v17](https://github.com/tcreek/queue_callback_v17/)

## Status

**Version 17.0.5.0 Beta 5. Updated November 7, 2026.**

Beta 5 adds returned-call press-1 confirmation, configurable response timeout
and prompt repeats, invalid-choice handling, agent failure feedback and new
recordings. It also includes the callback reliability and installation fixes
listed in the [release notes and upgrade guidance](RELEASE_NOTES.md).

The project owner confirmed the corrected initial callback-confirmation flow
works in a live test on November 7. Automated checks cover the affected menu,
settings, storage, dispatch, migration and retry paths, including installed AGIs
running as Asterisk against temporary tables. That confirmation does not complete
the full live-call and clean installation/upgrade matrix required for stable.

Version metadata and documentation are prepared locally. No GitHub release or
tag has been published by this version update.

## Compatibility

The target is FreePBX 17 with its Core and Queues modules and Asterisk 22.
Inspected reference-host versions:

| Component | Reference version |
| --- | --- |
| FreePBX Framework | 17.0.33.2 |
| Core | 17.0.18.54 |
| Queues | 17.0.4 |
| PHP | 8.2.35 |
| MariaDB | 11.8.6 |
| Operating system | Debian 13 |

These identify the environment used for the isolated checks, not a completed
compatibility certification. Other versions and distributions, including
IncrediblePBX and Tango PBX, have not been verified here. The exact Asterisk
server build and the full live-call matrix must be recorded during release
verification; do not infer support for every FreePBX-based system.

## Features

- Customer-first or Agent-first modes (`call_first`)  
- Orders callbacks by queue priority and original queue entry time
- Live AMI checks for idle, unpaused members outside wrap-up in both modes
- **Return Call Announcement - Confirmation** asks the customer to press 1 before connecting.
- Configurable **retry interval**, **max attempts**, **processing interval**  
- One new callback per run; one active callback per queue
- Verbose logging (`NoOp()` + CLI output)  

---

## How it Works (high level)

1. Caller opts in → request written to `queuecallback_requests`.  
2. Processor evaluates:  
   - **Is it their turn?** (live waiting calls + pending callbacks)  
   - **Are agents ready?** (member states, not just A:X summary)  
3. If yes → `.call` file spooled and the call is driven into:  
   - `queuecallback-outbound` (customer-first), or  
   - `queuecallback-agent-outbound` (agent-first)  
4. A hangup handler records completion only with bridge evidence. Unsuccessful
   attempts return to pending until the configured total attempt limit is reached,
   then become failed. Unanswered originates are reconciled from archived call files.

---

## Installation and upgrade

1. Back up FreePBX configuration and the module tables. Read the
   [upgrade notes](RELEASE_NOTES.md#upgrade-notes), especially routes, blocklist
   patterns and previous credential exposure.
2. In **Admin → Module Admin → Upload Module**, upload the module archive built
   from the intended source revision. Its root folder must be `qcallback/` with
   `module.xml` inside. Use the filename supplied for that revision; older beta
   archives do not contain these changes. A new archive has not been published.
3. Select **Install** or **Upgrade & Install**, complete processing, then use
   **Apply Config**. Both installation entry points use the shared installer.
4. Open **Applications → Queue Callback** and configure the queues. Review saved
   route selections, callback keys, alternate keys and matching recordings.
5. Verify the installer’s scheduler: four staggered cron runs per minute for
   `process_callbacks.php`. Per-queue delays still control eligibility. Do not
   add an additional scheduler under another user.
6. Complete the live release-verification matrix in the release notes before
   treating the installation as stable.

Upgrade/install deploys the packaged AGIs and performs additive database
migrations; Apply Config regenerates dialplan and queue configuration. Source
edits or a read-only health check alone do not activate those changes.

## Known Issues / Verification Pending

- Compound recordings joined with `&` are not supported by Asterisk's periodic-announcement playback. Select a single recording containing the complete Queue Callback Instruction.
- If the callback key is also an IVR menu key, that IVR option is reserved for callbacks while Queue Callback is enabled. Choose an unused key.
- Live verification of breakout IVR routing alongside callbacks is pending.

---

## Components

- **Dialplan generation (`Qcallback.class.php`, `callback_dialplan.php`)**
  FreePBX hooks delegate to the BMO generator. Shared renderers produce request
  menus, legacy context aliases and both outbound modes.

- **Processor (`process_callbacks.php`)**
  Runs the shared dispatch and lifecycle helpers. The older
  `intelligent_callback_processor.php` name directly loads this worker.

- **Installation (`Qcallback.class.php`, `callback_install.php`)**
  The legacy `install.php` entry point calls the same BMO installer. Schema,
  default blocklist rows, packaged AGIs, announcement files and cron generation
  have one implementation.

- **Database**  
  - `queuecallback_requests` – individual requests  
  - `queuecallback_config` – per-queue settings  
  - `recordings` – resolving return announcement filenames  

## Call-file publication

All callback publishers use `callback_spool.php`. A connection-scoped database
lock serializes workers for each queue and callback number. A conditional update
claims a pending request and records its attempt before the call file becomes
visible to Asterisk.

Call files are staged in `/var/spool/asterisk/.qcallback-staging` on the outgoing
filesystem, then renamed into `outgoing` after writing and setting permissions.
Files use mode `0640` and the outgoing directory's owner and group; the staging directory
uses `0770` with that group. The processor must be able to write to the spool
parent and outgoing directory. Publishing fails if staging shares a different
filesystem or an outgoing file for the request already exists.

If publication fails after claiming, the previous attempt count and timestamp
are restored, provided the request is still in that processing attempt.

Run `php tests/callback_spool_test.php` on a FreePBX development/test host to check
claim ordering, competing database connections, duplicate suppression, file
permissions, and failure recovery. It uses connection-local temporary tables
and a spool under `/tmp`, without sending calls to Asterisk or changing saved
callback records.

## Retry and completion behavior

- Each `queuecallback_<id>_<attempt>.call` file makes one originate attempt
  (`MaxRetries: 0`). Maximum Attempts includes the first attempt. The configured
  processing interval is the minimum delay before the first attempt; the retry
  interval starts when an unsuccessful attempt ends. Defaults are 30 seconds before
  the first attempt and 60 seconds (1 minute) between retries. The Retry Interval
  field displays whole minutes, starting at 1; scheduling stores seconds.
- The processor polls about every 15 seconds, so actual dispatch can occur later
  than the minimum delay. Maintenance uses the same processor and claim checks.
- Both callback modes require the customer to press 1 during the return-call
  announcement or within the configured Return Confirmation Timeout afterward
  (default 10 seconds, range 1–60). Return Confirmation Repeats controls additional
  plays after timeout or invalid input (default 1, range 0–5; 1 means 2 plays total).
  These controls appear beside the return announcement in both settings forms.
  The default recording is
  `custom/callback-return-confirm`; custom return recordings must also ask for
  press 1. Other digits, including star and pound, play Asterisk's
  `option-is-invalid` before using the next repeat. After the final unsuccessful
  play, the customer hears `custom/failed-to-confirm`. In agent-first mode the
  waiting agent also hears that recording when the customer fails to confirm,
  including no answer or a hangup before confirmation. A confirmed call does not
  play the failure recording to the agent. Prompt repeats stay within one call
  attempt; only after the attempt ends do the callback retry rules apply.
  A voicemail answer alone cannot complete the callback. The old
  `callback_returned` audio has been replaced; request-confirmation recordings
  remain separate. WAV, mu-law (`.ulaw`) and A-law (`.alaw`) versions are packaged.
- Customer-first completion requires positive `ANSWEREDTIME_MS` from Queue,
  queue wait timing, and no abandonment or failed queue outcome. Agent-first
  completion requires `DIALSTATUS=ANSWER` and positive bridge timing on the agent
  leg. Customer answer or announcement playback alone does not complete a request.
- Results carry the attempt number. Late or duplicate handlers and archives
  cannot overwrite a newer attempt or a cancellation. Failed terminal requests
  receive a completion timestamp for retention cleanup; retryable requests do not.
- A call-file originate that is busy, unavailable, or unanswered never reaches
  the answer dialplan. On the next processor run, its terminal archived Status
  releases the attempt for retry or failure. An archived `Completed` without a
  confirmed hangup result is treated as unconfirmed, not as a successful bridge.
- The optional database event is a heartbeat measured in seconds. It no longer
  resets processing calls by age or fails an active final attempt. If a worker
  crashes before publication, or Asterisk cannot archive a call file and no
  hangup result is recorded, the request stays processing for investigation.
  Check active channels before manually recovering it; time alone cannot prove
  a call has ended. Archive-based recovery requires `outgoing_done` to be writable.

Upgrade/install the module and Apply Config to install the updated result AGIs,
replace the outbound contexts, and refresh old database events. There are no
database schema changes, and saved interval values are preserved as seconds.
Run `php tests/callback_lifecycle_test.php` for isolated retry/completion tests.
Live verification of both callback modes, announcements interrupted by hangup,
queue timeout, busy/no-answer, and agent/customer hangup is still required.

References: [Asterisk Queue timing variables](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Queue/)
and [call-file retries and archives](https://docs.asterisk.org/Configuration/Interfaces/Asterisk-Call-Files/).

## Callback settings

- **Outbound Route:** an explicit selection routes external customer numbers
  through a context containing only that FreePBX route, including its configured
  time windows and time zone. Dial patterns, number transformations and trunk
  selection remain in FreePBX. A number that does not match the selected route
  does not fall back to another route. Missing routes are shown as unavailable
  and rejected when saving an enabled queue or preparing an external callback.
- **Automatic - FreePBX route order:** route ID `0` uses normal FreePBX routing.
  New callback configurations default to Automatic. Existing saved selections
  are preserved and are now enforced; review them before activation.
- The route selection applies to the customer leg in either call-first mode.
  Registered FreePBX users use internal routing regardless of extension length.
  Queue agents retain their existing internal dialing behavior. Maintenance
  publishers use the same customer routing helper as the scheduled processor.
- **Confirm Callback Number = No:** use the detected caller ID without initial
  request-confirmation prompts. **Yes:** read back the number, accept `1`, then
  play the full **Callback Confirm Prompt** before accepting a fresh `1` to save
  the request. Digits during the final prompt are ignored, preventing early or
  duplicate input from cutting it short. The response timeout is five seconds
  after playback. A wrong final key, hangup, timeout or playback failure cancels.
  This setting controls the initial request; returned-call confirmation is separate.
- **Different Number Key:** the selected digit, `*`, or `#` opens alternate-number
  entry. `0` is preserved as a real key. `1` is reserved for confirmation; **None**
  disables alternate entry. The callback request key and alternate-number key
  may be the same because they are used in separate menus. After alternate
  entry, the new number is read back and must be confirmed before storing.
- Match the confirmation recording's instructions to the configured alternate
  key. The single-digit menu disables the default `#` terminator; number entry
  still uses `#` to finish entering the number.
- Saving a partial configuration from a legacy form preserves settings that
  form does not submit, including the route, confirmation flag and alternate key.

Upgrade/install and Apply Config to regenerate the request menus, selected-route
contexts and compatibility aliases. No schema migration or live test calls were
performed for these changes. Run `php tests/callback_settings_test.php` to check
scripted menu flows, saved settings and routing with temporary database tables.
Live verification should cover both callback modes, confirmation on/off,
alternate keys, route matching and non-matching numbers, and route time windows.

Implementation references: [FreePBX 17 outbound route generation](https://github.com/FreePBX/core/blob/release/17.0/functions.inc.php)
and [Asterisk Read options](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Read/).

## Request validation and storage results

New requests from the store AGI and the authenticated web handler share
`callback_requests.php`. Queue IDs must contain 1–50 digits. Callback destinations
must contain digits with an optional leading `+`, with a total length of at most
50 characters. Short internal extension numbers remain supported. Text such as
`anonymous`, embedded spaces, dial strings, control characters and oversized
destinations are rejected rather than stripped into a different number. This is
syntax validation; it does not prove that a number is reachable.

The insert itself requires an existing queue with enabled callback configuration
and a positive attempt limit. It captures that configured limit and records the
call's unique ID. The AGI also records `QUEUEJOINTIME`, `QUEUE_PRIO` and
`QUEUEPOSITION` when available; missing values use request time, priority zero
and unknown position. Position is diagnostic rather than the ordering key.
The store AGI no longer invokes `queue show` through a shell.

The AGI requires `QCB_CONFIRMED=1`. DTMF-authorized requests also require
`QCB_CONFIRM_SOURCE=dtmf`, `CONFIRM2=1` and `READSTATUS=OK`. Detected-number requests
are accepted only if the enabled queue's saved `confirm_number` setting is `0`;
a stale dialplan cannot bypass an enabled confirmation setting. Only an unset
`CALLBACK_NUMBER` falls back to caller ID; an explicitly empty number is invalid.
It returns these channel variables:

| Variable | Result |
| --- | --- |
| `QCB_STORE_SUCCESS` | `1` only after an insert returns a valid request ID; otherwise `0` |
| `QCB_REQUEST_ID` | Saved request ID on success; empty on failure |
| `QCB_STORE_ERROR` | Empty on success; otherwise `INVALID_QUEUE`, `INVALID_NUMBER`, `INVALID_METADATA`, `NOT_CONFIRMED`, `QUEUE_UNAVAILABLE`, `DB_ERROR` or `AGI_ERROR` |

The menu resets those variables before invoking AGI. A failed or unavailable AGI
therefore cannot reuse an earlier success flag. Only successful storage plays the
Callback Initiated Announcement; failure plays an apology, an error announcement
and a request to try later, then hangs up. Alternate-number entry rejects
non-digit input instead of silently removing it. Legacy generated entry points
delegate to the same request menu.

Database errors are logged with exception type and code, without SQL text,
credentials or raw exception messages. The web handler retains its plain-text
`SUCCESS:` / `ERROR:` response contract, with a generic error and HTTP 400 for
rejected requests or HTTP 503 for database failures.

Upgrade/install the module and Apply Config to deploy the updated store AGI,
bind its helper path to the installed module directory, and regenerate the
success/failure branches. No database schema change is required. Run
`php tests/callback_requests_test.php` for validation, temporary-database insert
and AGI protocol checks. Live verification of successful storage, unavailable
queues and the failure announcements remains pending.

Protocol references: [Asterisk GET VARIABLE](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_variable/)
and [SET VARIABLE](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_variable/).

---

## Requirements

- FreePBX 17 with Core and Queues installed; Asterisk 22 is the target.
- PHP with PDO MySQL and mbstring, MariaDB named-lock support, and cron.
- FreePBX AMI access to complete QueueStatus lists, queue readiness and required
  management actions. No CLI-output or configured-membership readiness fallback.
- Asterisk outgoing-spool access with a writable sibling staging directory on
  the same filesystem, and permissions for the installation’s generated files.

See Compatibility for the inspected versions and limits of verification.

---

## License

Licensed under **GNU GPL v3.0 or later (GPLv3+)**.  
See <https://www.gnu.org/licenses/gpl-3.0.html>.  

---

## Credits

Built on the shoulders of **Asterisk** and **FreePBX**.  


## Queue ordering and live readiness

All automatic, manual and maintenance dispatch paths use `callback_dispatch.php`.
Within each queue, pending requests sort by priority (highest first), original
queue entry time, request time and ID. A head request waiting for its retry delay
holds its place. Pending duplicates retain the first request in that order;
later duplicates are marked cancelled. Exhausted pending requests become failed
with a terminal timestamp, so they cannot permanently block the queue.

Before dialing, the worker requires a complete, recent AMI `QueueStatus` list and
checks `QUEUE_MEMBER(queue,ready)` for inherited queue wrap-up. A live caller with
higher priority, or equal priority and an earlier/equal entry time, defers the
callback. Its original channel must have left the queue. Unknown, unavailable,
busy, ringing, paused, in-call and wrapping members cannot be selected. Missing,
inconsistent or unavailable AMI data defers the request without an attempt.
There is no configured-membership fallback.

A database lock serializes selection and publication across callback workers.
One callback may be processing per queue, and its chosen member capacity is
reserved against other callback workers until the attempt ends. A member shared
with another queue containing live callers is conservatively excluded. Agent-first
uses the actual queue member interface, including Local channels, rather than
assuming PJSIP. Customer-first returns with the recorded queue priority and
`VQ_POSITION=1`, so callers of the same priority who arrived while the customer
was being dialed do not move it to the tail. Higher-priority callers retain
Asterisk's normal precedence.

These checks use a dispatch-time snapshot. They cannot physically reserve an
Asterisk agent or prevent a new live call from taking that agent while the
callback rings. Customer-first still uses the queue's configured strategy when
it returns. Ordering is per queue, not a global FIFO across queues. Existing
requests without original entry metadata use their request time; their previous
place cannot be reconstructed. Live verification of both modes remains required.

Upgrade/install is required before using this processor: it adds
`queue_joined_at`, `queue_priority` and `reserved_agent` plus a dispatch index.
The migration preserves existing requests and is safe to repeat. Apply Config
also deploys the updated store AGI and returning-customer dialplan. This source
change does not itself migrate the running database or reload Asterisk.

Verification: `php tests/callback_queue_test.php` uses temporary database tables,
fake AMI responses and a spool under `/tmp`; it places no calls. Also run the
request, settings, spool and lifecycle regression tests. Implementation references:
[Asterisk QueueStatus](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueStatus/)
and [Asterisk queue implementation](https://github.com/asterisk/asterisk/blob/22/apps/app_queue.c).

## Callback blocklist patterns

The enabled `pattern` field determines whether a callback destination is blocked.
`area_code` is an optional categorization label; it is not an additional prefix
rule. An exact number matches only that number. Numeric Asterisk patterns start
with `_` and support digits, `X` (0–9), `Z` (1–9), `N` (2–9), numeric sets/ranges
such as `[1237-9]`, and a final `.` (one or more digits) or `!` (zero or more
digits). Patterns are limited to 20 characters to match the existing schema.
Other Asterisk extension syntax and arbitrary regular expressions are rejected.
See [Asterisk pattern matching](https://docs.asterisk.org/Configuration/Dialplan/Pattern-Matching/).

For example, `_268NXXXXXX` blocks a 10-digit destination beginning with 268 only
when the following digit is 2–9. It does not block shorter/longer numbers or
numbers beginning with 1268; `_1268NXXXXXX` covers the latter. A leading `+` is
optional on either the number or the pattern, so `+12682345678` and `12682345678`
match the same rule. No country codes or dialing prefixes are inferred, added
or removed. Use separate patterns for other dialed representations you want to
block.

Global rules (blank queue ID) and rules for the callback's queue both apply.
Disabling one rule does not override another matching rule. The queue Security
tab displays both scopes, and editing a rule without changing its scope retains
its existing queue ID. The Security page can add global or queue-specific rules.
Queue-tab add, delete and toggle controls submit independent forms so they do
not accidentally submit the callback configuration form.

The common dispatcher checks the blocklist before querying live readiness or
publishing a call file. A match cancels the pending request with a timestamp and
consumes no attempt. Invalid enabled legacy patterns or database read errors
defer dispatch instead of permitting an unchecked call. Correct or disable a
malformed rule before retrying; invalid new/edited patterns and attempts to
re-enable malformed rules are rejected. Existing entries are not automatically
rewritten. Review custom entries that previously relied on `area_code` prefix
matching and make their patterns express the intended restriction.

Generated auxiliary security contexts keep global rules in
`qcallback-security-block` and queue rules in `qcallback-security-block-<queue>`,
which includes the global context. They include both leading-plus and plain
number forms. Callback enforcement itself uses the shared PHP matcher; these
contexts are not a general outbound-call firewall.

No new schema migration is required for this change. Upgrade/install includes
the shared helper, and Apply Config refreshes generated security contexts.
Verification: `php tests/callback_security_test.php` uses temporary database
tables and intercepts the reload flag; `php tests/callback_security_view_test.php`
renders the real queue view with a fake service. Neither test places calls or
changes live PBX configuration.


## Shared installation and compatibility paths

`install.php` delegates to `FreePBX::Qcallback()->install()`. Both framework
entry points use `callback_install.php` for additive schema setup, default rule
seeding, AGI and sound deployment, and processor cron text. Repeated setup
preserves requests, including when the config table is missing, and adds missing
legacy columns. Schema failures propagate instead of reporting a completed
installation. Default blocklist entries have one definition, reused for global
initial setup and per-queue defaults.

The installer copies all four packaged AGIs; it no longer contains a separate
inline check AGI or embedded database credentials. The packaged check AGI reads
FreePBX configuration. Upgrade/install is still needed to replace a previously
installed check AGI. Credential audit and rotation are separate administrative
operations; see the credential-handling guidance below.

`hooks.php` loads `functions.inc.php`, so either loading order defines hooks
once. Legacy dialplan writer names delegate to the BMO generator and no longer
construct alternative Queue() calls or overwrite normal queue contexts. The
legacy queue-form hook uses the shared settings saver and retains omitted
fields. Legacy enabled-queue lookup, config deletion and heartbeat recreation
also delegate to their BMO implementations. Normal generation hooks leave the
reload to FreePBX.

Installation and Apply Config use the same custom-context renderer. It replaces
all module-owned request/exit/compatibility contexts and selected-route contexts,
including disabled queues, while preserving unrelated contexts. Disabling the
last callback queue now runs cleanup rather than leaving its previous contexts
in place. A config-read failure is not treated as an empty configuration list.

Cron setup replaces the module's legacy processor entries with four staggered
runs per minute and preserves unrelated jobs. The compatibility processor name
loads the canonical worker directly, without spawning another PHP process.

Verification adds `tests/callback_entrypoints_test.php` (run with both
`functions-first` and `hooks-first`) and `tests/callback_install_test.php`.
Entry-point tests use fake services; installer tests write under `/tmp` and use
connection-local temporary tables. They do not run a real installation, change
live cron, reload Asterisk or place calls. Complete upgrade/reinstall and live
call verification remains pending before release.

## Credential handling in the check AGI

The packaged check AGI uses `FreePBX::Database()` after loading FreePBX
configuration. It no longer creates a second PDO connection or prints raw
exception messages through AGI. Both installer entry points deploy this same
packaged file, with its module path substituted for the shared helper.

Before the check runs, the request dialplan clears `QUEUE_CALLBACK_ENABLED` and
`QUEUE_CALLBACK_KEY`. The AGI clears them too, validates the queue ID, and checks
that the queue exists and callback configuration is enabled. It validates the
saved DTMF key, returns the key and error code first, and sets enabled last.
Missing AGIs, missing/disabled queues, invalid settings, database failures and
protocol errors therefore cannot reuse an earlier enabled flag. Unset queue
variables may fall back to AGI DNID; explicitly empty or invalid values cannot.
`QCB_CHECK_ERROR` reports `INVALID_QUEUE`, `QUEUE_UNAVAILABLE`, `INVALID_CONFIG`,
`DB_ERROR` or `AGI_ERROR`; successful checks leave it empty.

Bootstrap output is kept off the AGI protocol stream. Check/storage database
errors and completion errors log exception type/code rather than raw exception
messages. No database passwords are included in these error responses.

Older installers could contain real database credentials. Audit affected
deployments and rotate exposed accounts, coordinating FreePBX configuration,
ODBC and any generated dialplan globals that use the same login. Source removal
does not revoke an exposed password. Keep credential values out of issue reports,
release notes and command output. Deployment-specific audit and rotation records
belong in the administrator’s private checklist.

`php tests/callback_check_test.php` covers the real executable protocol with
invalid input, injected bootstrap/protocol failures, sanitized logging and
configuration reads through temporary tables. The settings tests also verify
that a missing check AGI cannot reuse a stale enabled flag. The automated suites
place no calls and do not change live credentials or PBX configuration. Credential rotation is a separate deployment operation.
