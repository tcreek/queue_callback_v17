# Queue Callback for FreePBX / Asterisk (v17.0.1 Beta 1)

Intelligent queue callback for **FreePBX 17** and **Asterisk 22**.  
Callers can request a callback instead of waiting on hold. The processor only releases callbacks when it’s the caller’s **turn in queue** and **agents are actually available**.

---

## Status

🚧 **Version 17.0.1 Beta 1**  
Core logic is functional, but some behaviors still need refinement (see **Known Issues**).

---

## Compatibility

This module will function on any FreePBX-based system, including:

- **IncrediblePBX** – <https://wiki.incrediblepbx.com/HomePage>  
- **Tango PBX** – <https://community.tangopbx.org>  
- **FreePBX** – <https://www.freepbx.org/>  

---

## Features

- Customer-first or Agent-first modes (`call_first`)  
- Honors **true queue order** (waiting calls + pending callbacks)  
- **Agent readiness** checks via `queue show` (with DB fallback)  
- Optional **return announcement** before entering the queue  
- Configurable **retry interval**, **max attempts**, **processing interval**  
- Strict FIFO (one callback processed per run)  
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
4. On successful bridge, the request is marked completed.  

---

## Installation (GUI module upload)

1. In **FreePBX → Admin → Module Admin → Upload Module**  
   Upload the packaged module tarball (e.g. `queuecallback-17.0.1beta1.tgz`).  
2. Click **Install** (or **Upgrade & Install**) → **Process** → **Confirm**.  
3. Click **Apply Config**.  
4. Open **Admin → Queue Callback** to configure your queues and options.  
5. Enable the **processor** (scheduled execution) in the module UI.  
   *(If you prefer manual scheduling, you can add a cron entry for the `asterisk` user to run the included `intelligent_callback_processor.php` every minute.)*  

> The module ships all code; no manual file copying required.  

---

## Known Issues (Beta 1)

- **Return announcement not playing**  
  The optional return message/recording is not currently played to the customer upon answer in customer-first mode.  

- **Status not labeled correctly**  
  Completed/failed statuses aren’t always being set, causing the system to **re-call until max retries** are exhausted.  

- **Early DTMF capture**  
  Pressing **1 to confirm the callback number** can be detected **before** the number announcement finishes.  

- **CallerID update**  
  CallerID is not yet updated to **the original caller’s number** when the call re-enters the queue.  

---

## Components

- **Dialplan builder (`functions.inc.php`)**  
  Creates:  
  - `queuecallback-outbound` (customer-first)  
  - `queuecallback-agent-outbound` (agent-first)  
  Prefers inherited vars (e.g., `__CALLBACK_*`) and falls back if needed.  

- **Processor (`intelligent_callback_processor.php`)**  
  Generates `.call` files, checks agent readiness, queue position, schedules retries, and marks outcomes.  

- **Database**  
  - `queuecallback_requests` – individual requests  
  - `queuecallback_config` – per-queue settings  
  - `recordings` – resolving return announcement filenames  

---

## Requirements

- **FreePBX 17**  
- **Asterisk 22**  

---

## License

Licensed under **GNU GPL v3.0 or later (GPLv3+)**.  
See <https://www.gnu.org/licenses/gpl-3.0.html>.  

---

## Credits

Built on the shoulders of **Asterisk** and **FreePBX**.  
Queue Callback logic and intelligent processor by **[Your Name/Org]**.  
