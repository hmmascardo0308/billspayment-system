# Support Ticket Explanation

## What Is the Support Ticket Used For?

The **Support Ticket** module is used to formally report, track, escalate, and resolve bills-payment or transaction-related concerns. It gives each concern a unique ticket number and keeps all messages, actions, attachments, owners, and timestamps in one traceable record.

It is a structured workflow rather than a real-time chat system. Every reply and action becomes a ticket-trail entry, making it possible to see who handled an issue, what was done, and when it happened.

## Main Purposes

The module helps the system to:

- Record payment concerns such as a wrong biller, overstated amount, or cancelled transaction.
- Route each concern to the correct support team.
- Prevent requests from being lost in informal messages or separate communication channels.
- Show the current status and person or team responsible for a concern.
- Store supporting files and the complete history of actions taken.
- Measure response and resolution time using the timestamp on every trail entry.
- Provide an auditable record for follow-up, reporting, and accountability.

## Who Uses It?

### Branch

The Branch creates a ticket when it needs help with a bills-payment or transaction concern. It supplies the reference number, source, biller or subbiller, request type, reason, transaction details, and any supporting attachments. The Branch can monitor and reply to its ticket while it is still open, but cannot reply after it has been closed.

### VPO

VPO receives newly created tickets, accepts ownership, reviews the concern, communicates through ticket replies, and attempts to resolve it. If the issue requires further investigation or action, VPO transfers the ticket to CAD. VPO can also resolve or close a ticket without CAD when escalation is unnecessary.

### CAD

CAD receives tickets escalated by VPO. A CAD user accepts the ticket, investigates or processes the concern, sends updates, and resolves or closes it.

### System

The system records automated trail entries for important events such as transfers, acceptance, resolution, and closure. It can automatically close a resolved ticket after the selected resolution period expires.

## Typical Ticket Flow

1. A Branch creates a ticket for a payment or transaction concern.
2. The ticket is placed in the VPO Open queue.
3. A VPO user accepts it, making that user the active handler.
4. VPO reviews the concern and sends replies or updates.
5. VPO either resolves the concern or transfers it to CAD.
6. If transferred, a CAD user accepts, investigates, and resolves the ticket.
7. The ticket is closed immediately or automatically after a selected period.
8. The completed ticket remains available in the appropriate Closed section and reports.

In short, the normal escalation path is:

`Branch -> VPO -> CAD (when needed) -> Resolved -> Closed`

## Ticket Statuses

| Status | Meaning |
|---|---|
| `open` | Newly created and waiting for VPO acceptance. |
| `accepted` | Accepted and being handled by VPO. |
| `resolving` | Accepted and being handled by CAD. |
| `resolved` | Marked as resolved and waiting for automatic closure. |
| `closed` | Processing is complete and no more Branch replies are accepted. |

## Information Kept in a Ticket

A ticket can contain:

- A unique ticket number, such as `TKT-YYYYMMDD-XXXX`.
- The payment reference number and source (`KPX` or `KP7`).
- Partner, biller, and subbiller information.
- The ticket type, request type, reason, amount, account, and branch details.
- Correction-specific information for wrong-biller, overstated-amount, or cancelled-transaction requests.
- The current handler, VPO owner, CAD owner, and ticket status.
- Replies, acceptances, transfers, resolutions, and closure events.
- Supporting images, documents, or spreadsheets.
- Date and time stamps for every trail entry.

## Why It Is Important

Without the Support Ticket module, payment concerns may be handled through disconnected messages, making ownership, progress, and resolution difficult to verify. The module creates one reliable source of truth from the initial Branch report through final closure. This improves coordination between Branch, VPO, and CAD while providing transparency and accountability throughout the process.

## Related Files

- `support_ticket.md` contains the detailed workflow, business rules, and interface specification.
- `support_ticket_sql.md` contains the database schema and setup SQL.
- `dashboard/support_ticket/` contains the implemented pages, controllers, assets, reports, and automatic-close process.
