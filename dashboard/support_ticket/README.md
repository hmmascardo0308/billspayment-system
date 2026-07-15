# Support Ticket Module

This module implements the Support Ticket flow defined in `support_ticket.md`.

## Included pages

- `create-ticket.php` - Branch create ticket + reply to own tickets
- `bpo-ticket.php` - VPO queues (open/active/closed)
- `cad-ticket.php` - CAD queues (open/active/closed)

## Controllers

- Branch:
  - `controllers/branch/create-ticket.php`
  - `controllers/branch/reply-ticket.php`
- VPO:
  - `controllers/vpo/accept-ticket.php`
  - `controllers/vpo/submit-ticket.php`
  - `controllers/vpo/close-ticket.php`
- CAD:
  - `controllers/cad/accept-ticket.php`
  - `controllers/cad/submit-ticket.php`
  - `controllers/cad/close-ticket.php`
- System:
  - `controllers/system/auto-close.php`
  - `cron/auto-close.php`

## Required database

- `support_ticket` schema and tables from `support_ticket_sql.md`
- Live-read views on `mldb.subbiller`:
  - `support_ticket.vw_mldb_subbillers`
  - `support_ticket.vw_mldb_partners`

## Permission keys used

- `Support Ticket Create`
- `Support Ticket VPO`
- `Support Ticket CAD`

## Notes

- `ticket_info.wrong_biller_id` and `ticket_info.biller_name` are taken from `vw_mldb_subbillers`.
- `tickets.partner_ext_id` is derived from selected subbiller (`partner_id_kpx`).
- Branch replies are blocked when ticket status is `closed`.
