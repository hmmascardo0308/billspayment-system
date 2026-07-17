# Bills Payment - TRL Explanation

## What Is Bills Payment - TRL Used For?

**TRL** means **Transaction Request Log**. The Bills Payment - TRL module is used to record, review, monitor, and report bills-payment transactions that require investigation, correction, or refund processing.

It serves as the system's formal transaction-level record for payment concerns. Instead of keeping correction requests in informal messages or separate files, the module stores the original transaction information, the requested action, correction details, reason, and processing status in a consistent format.

## Main Purposes

The module helps the system to:

- Record bills-payment concerns that require operational action.
- Preserve the original transaction details connected to each request.
- Identify the wrong and correct biller or amount when a correction is needed.
- Detect duplicate reference numbers before records are saved or imported.
- Track which requests are still pending and which have been refunded.
- Group transaction requests by partner and subbiller for monitoring.
- Produce summary, detailed, and refunded-transaction reports.
- Export TRL information to Excel for reconciliation, sharing, and further analysis.
- Convert investigated Support Ticket information into a formal TRL record.

## Common Types of Requests

The TRL entry forms support the following transaction concerns:

- No Payment Received
- Double Posting
- Multi Posting
- Triple Posting
- Wrong Biller
- Overstated Amount
- Cancelled Transaction
- Unreflected Transaction (`UNREFLECTED TRXN`)

Additional correction information is stored when required. For example, a Wrong Biller request records the correct biller, while an Overstated Amount request records the wrong amount, correct amount, and difference.

## Main Parts of the TRL Module

### 1. TRL Entry

TRL Entry creates an individual Transaction Request Log record. It provides three entry modes:

| Entry mode | Use |
|---|---|
| **Auto** | Searches for an existing transaction using its reference number and loads its transaction details. |
| **Manual** | Allows the user to enter all transaction and request information directly. |
| **Ticket** | Loads information from an investigated Support Ticket and converts it into a TRL record. |

The system validates required information and checks whether the reference number already exists before inserting the record.

### 2. TRL Ticket Entry

The Ticket Entry flow connects the Support Ticket module to TRL. A user searches for a ticket number, and the system loads its transaction and correction information. The ticket must already have a VPO owner, which indicates that it has been investigated by VPO, before it can be used for TRL entry.

This integration avoids retyping the ticket details and helps keep the Support Ticket and TRL records consistent.

### 3. TRL Import

TRL Import is used for bulk processing through Excel files. It:

1. Accepts `.xls` or `.xlsx` files.
2. Reads and validates the uploaded rows.
3. Performs a duplicate pre-check using transaction reference numbers.
4. Separates duplicate, matched, and new rows for review.
5. Displays a preview before saving.
6. Inserts the approved rows into the TRL database tables.

This is useful when many transaction requests must be recorded at the same time.

### 4. TRL Review

TRL Review displays pending TRL records and allows an authorized user to confirm that a transaction has been refunded. Confirmation changes the record's status to `REFUNDED` and, when the database supports the fields, records the refund date and the user who confirmed it.

The review page protects already processed records from being refunded again.

### 5. TRL Report

TRL Report provides three reporting views:

| Report view | Use |
|---|---|
| **Summary** | Summarizes pending TRL amounts by partner, subbiller, and year. |
| **Refunded** | Lists transactions whose refund processing has been confirmed. |
| **Subbillers** | Shows selected subbiller summaries and transaction-level details. |

Reports can be exported to Excel. The generated workbooks can contain a summary sheet, a refunded-transactions sheet, and detailed sheets for individual subbillers.

## Typical TRL Workflow

1. A payment concern is identified directly or reported through a Support Ticket.
2. The request is entered through Auto, Manual, or Ticket mode, or uploaded through TRL Import.
3. The system validates the transaction information and checks for duplicate reference numbers.
4. The request is saved as a pending record in `mldb.trl` with any required correction details.
5. An authorized user reviews the pending request and confirms the refund when completed.
6. The record is marked `REFUNDED` and appears in the Refunded report.
7. Users view summaries or export reports for reconciliation and monitoring.

In short, the normal process is:

`Payment concern -> TRL Entry or Import -> Pending Review -> Refund Confirmation -> Reporting`

## Information Stored in a TRL Record

A TRL record can contain:

- A unique TRL number.
- Transaction date and time.
- Payment reference number.
- Original or wrong biller ID and biller name.
- Account number and account name.
- Payment branch ID and branch name.
- Transaction amount.
- Type of request and reason.
- Correct biller ID and name for Wrong Biller requests.
- Wrong amount, correct amount, and difference for amount-related requests.
- Refund status and, where available, the refund date and confirming user.

The main records are stored in `mldb.trl`. Request-specific details are stored in related tables such as:

- `mldb.trl_wrongbiller`
- `mldb.trl_overstatedamount`
- `mldb.trl_cancelledtransaction`

## Difference Between Support Ticket and TRL

| Support Ticket | TRL |
|---|---|
| Manages communication, assignment, escalation, and the history of a concern. | Stores the formal transaction correction or refund request. |
| Follows the Branch, VPO, and CAD support workflow. | Follows entry/import, review, refund confirmation, and reporting. |
| Contains messages, attachments, owners, and ticket statuses. | Contains transaction, correction, refund, and reporting data. |

The two modules complement each other: a Support Ticket coordinates the investigation, while TRL records the resulting transaction request for operational processing and reporting.

## Why It Is Important

Bills Payment - TRL provides one reliable source of truth for transaction corrections and refunds. It reduces duplicate entries, improves the accuracy of correction details, separates pending and refunded requests, and gives users partner- and subbiller-level reports for reconciliation. This makes payment concerns easier to trace from initial entry through final refund confirmation.

## Related Files

- `dashboard/trl/trl-entry/` contains Auto, Manual, and Ticket entry modes.
- `dashboard/trl/trl-ticket_entry/` contains the dedicated Support Ticket-to-TRL entry page.
- `dashboard/trl/trl-import/` contains Excel import, duplicate checking, preview, and insertion.
- `dashboard/trl/trl-review/` contains pending-request review and refund confirmation.
- `dashboard/trl/trl-report/` contains summary, refunded, subbiller, and Excel reports.
