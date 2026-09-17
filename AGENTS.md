# Queue Callback - Agent Notes

## Project Structure

Two synced locations:
- **Dev**: `/home/trent/dev/queue_callback_v17/`
- **Installed**: `/var/www/html/admin/modules/qcallback/`

Sync both when making changes. Use `sudo cp` + `chown asterisk:asterisk` + `chmod 0644` for installed location.

## Key Files

| File | Purpose |
|------|---------|
| `Qcallback.class.php` | Core class - config, dialplan generation, security, events, cron |
| `process_callbacks.php` | Cron processor - generates call files for pending callbacks |
| `install.php` | Module install/upgrade - DB schema, AGI, dialplan, security defaults |
| `uninstall.php` | Module uninstall |
| `hooks.php` | FreePBX hooks - maintenance, queue config, reload |
| `page.qcallback.php` | Main admin page (overview, config, reports) |
| `page.qcallback_reports.php` | Reports page - scheduled callback status |
| `page.qcallback_security.php` | Security blocklist management |
| `page.qcallback_tab.php` | Queue config tab |
| `freepbx_menu.conf` | Menu entries (Reports → Scheduled Queue Callbacks, Tools → Security) |
| `module.xml` | Module metadata |
| `package.sh` | Packaging script |
| `views/callback_config.php` | Queue config form (embedded) |
| `views/callback_config_standalone.php` | Standalone queue config page with tabs |
| `views/callback_reports.php` | Reports table view |
| `views/callback_overview.php` | Overview page |
| `views/callback_reports.php` | Scheduled callbacks report |
| `agi-bin/` | AGI scripts (store, check, complete, result) |
| `announcements/` | Default sound files |

## Architecture

### Callback Flow

1. Caller in queue presses callback key → `queuecallback-{queue_id}` context
2. DTMF caught via `QGOSUB` override in `extensions_override_freepbx.conf`
3. `queuecallback-check.agi` sets `QUEUE_CALLBACK_ENABLED` and `QUEUE_CALLBACK_KEY`
4. Confirmation: `Read(CONFIRM1,...,n,5)` → `Wait(1)` → `Read(CONFIRM2,...,n,5)`
5. `queuecallback-store.agi` creates pending request in `queuecallback_requests`
6. `process_callbacks.php` (cron every 15s) processes pending requests
7. Call file generated to `/var/spool/asterisk/outgoing/`

### Two Flows

- **Customer-first**: Call customer → connect to queue via `queuecallback-outbound` context
- **Agent-first**: Call available agent → agent confirms → connect to customer via `queuecallback-agent-outbound` → `qcb-customer-confirm` subroutine

### Database Tables

- `queuecallback_config` - Per-queue config (enabled, announce, route, etc.)
- `queuecallback_requests` - Pending/processing/completed callback requests
- `queuecallback_security` - Toll fraud blocklist (per-queue or global `queue_id=""`)
- `queuecallback_trigger` - DB event trigger

### FreePBX Integration Points

- `extensions_override_freepbx.conf` - QGOSUB override for queue callback detection
- `queues_post_custom.conf` - Periodic announce settings
- `extensions_custom.conf` - Handler contexts, security blocklist
- `crontab` - 4 staggered entries for `process_callbacks.php` (every ~15s)
- `freepbx_menu.conf` - Admin menu entries
- `outbound_routes` table - Route selection for external callbacks

## Version

Current: **17.0.4.2 Beta 4**

## Key Decisions

1. **Security entries**: Global entries (`queue_id=""`) for fresh install visibility; per-queue entries created when callback enabled; `getSecurityEntries($queue_id)` falls back to global when no per-queue entries exist
2. **Outbound routing**: External numbers dialed via `Local/number@from-internal` (FreePBX outbound route selection); `outbound_route_id` available per-queue for future route forcing
3. **Reports**: Separate `page.qcallback_reports.php` under Reports menu, separate from main module page
