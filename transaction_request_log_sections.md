# Transaction Request Log (TRL) Sections Guide

This guide explains what each Transaction Request Log menu section is used for, how records move through the system, and which database tables are involved.

## TRL at a Glance

The Transaction Request Log tracks bills-payment transactions that require investigation, correction, or refund processing.

```text
TRL - Import -----------+
                       |
TRL - Entry ------------+--> mldb.trl (pending: status is NULL)
                       |                 |
TRL - Ticket Entry -----+                 v
                                  TRL - Review
                                         |
                                         v
                               status = REFUNDED
                                         |
                                         v
                                   TRL - Report
```

The main record is stored in `mldb.trl`. Extra details are stored in a supporting table when the request type requires them:

| Type of request | Supporting table | Extra information |
|---|---|---|
| `WRONG BILLER` | `mldb.trl_wrongbiller` | Correct biller ID and name |
| `OVERSTATED AMOUNT` | `mldb.trl_overstatedamount` | Wrong amount, correct amount, and difference |
| `CANCELLED TRANSACTION` | `mldb.trl_cancelledtransaction` | Wrong amount and correct amount |
| Other request types | None | The main details remain in `mldb.trl` |

## 1. TRL - Import

### Purpose

Use this section to add many TRL records from one or more Excel files instead of encoding each record individually.

Accepted file types are `.xls` and `.xlsx`.

### Process

1. Select or drag Excel files into the upload area.
2. Click **Proceed Import**.
3. The system searches the first 30 worksheet rows for the expected header row.
4. The Excel rows are parsed and temporarily stored in the PHP session.
5. Each reference number is checked against `mldb.trl.ref_no`.
6. If existing references are found, the system displays a duplicate list.
7. Choose **Remove All** to exclude those database duplicates, or **Cancel** to stop and return to the upload page.
8. Review the remaining records on the TRL Import Preview page.
9. Confirm the preview to write the records to the database.

Uploading a file alone does not immediately insert records. Database insertion happens only after confirmation on the preview page.

### Expected Excel information

The importer recognizes fields such as:

- Transaction date/time
- Reference number
- Wrong biller ID and biller name
- Account number and customer name
- Payment branch ID and branch name
- Amount
- Type of request
- Correct biller ID and name, when applicable
- Wrong amount, correct amount, and difference, when applicable
- Reason

### Duplicate behavior

Duplicate checking is based on the **reference number**, not the Excel filename.

- Reimporting a previously completed file normally finds its reference numbers in `mldb.trl`.
- Choosing **Remove All** removes the matching rows from the current import session.
- If every row already exists, the system reports that no new rows were detected and does not insert anything.
- The current pre-check compares uploaded reference numbers with records already in `mldb.trl`. It does not explicitly identify repeated reference numbers that exist only inside the new upload.

### Database effect

Every valid row is inserted into `mldb.trl`. Request-specific details are inserted into the appropriate supporting table listed above.

The inserts run as one database transaction. If any row fails, the transaction is rolled back so that the import is not partially committed.

### Permission

The page requires `TRL Import` or the compatible `Bills Payment` permission.

## 2. TRL - Entry

### Purpose

Use this section to create an individual TRL record. It provides three entry methods.

### Search by Reference No.

This is the automatic entry method.

1. Enter a payment reference number.
2. The system looks for the transaction in `mldb.billspayment_transaction`.
3. Transaction details such as account, branch, biller, and amount are loaded automatically.
4. Select the type of request and enter the required request information.
5. Review the confirmation preview and submit the record.

This method reduces manual encoding because it uses the existing bills-payment transaction as the source.

### Input all fields directly

This is the manual entry method. Use it when the transaction cannot be loaded automatically or when the details need to be encoded directly.

The user supplies the transaction, account, branch, biller, amount, request type, and reason. The form can include a reference number when required.

### Load from Support Ticket

This method searches for a support-ticket number and loads its transaction and investigation details. A ticket is accepted only after it has a valid VPO owner, indicating that it has reached the required investigation stage.

The current user interface also displays the informational message **“This Section is for further discussions”** when Manual or Support Ticket mode is selected. The selected section still opens.

### Validation and duplicate protection

- Required fields depend on the request type.
- A reference number is required for automatic entry and whenever manual entry is configured to include it.
- If a supplied reference number already exists in `mldb.trl`, submission is rejected.
- `WRONG BILLER` requires the correct biller ID and name.
- `OVERSTATED AMOUNT` and `CANCELLED TRANSACTION` require wrong and correct amounts.

### Database effect

The main record is inserted into `mldb.trl`, with applicable details inserted into a supporting table. These writes use a transaction and are rolled back if a required insert fails.

New records normally have a `NULL` status, which means they are pending review.

### Permission

The page requires `TRL Entry` or the compatible `Bills Payment` permission.

## 3. TRL - Ticket Entry

### Purpose

Use this dedicated section to convert an investigated support ticket into a TRL record without retyping its details.

### Process

1. Search using a support-ticket number.
2. The page reads the ticket and transaction details from the `support_ticket` database.
3. The system verifies that the ticket has a VPO owner.
4. It loads the base transaction details from `support_ticket.tickets` and `support_ticket.ticket_info`.
5. It loads request-specific details from the applicable support-ticket table.
6. Review the read-only details and submit them to the TRL database.

Ticket-specific source tables include:

- `support_ticket.tickets`
- `support_ticket.ticket_info`
- `support_ticket.ticket_info_wrongbiller`
- `support_ticket.ticket_info_overstatedamount`
- `support_ticket.ticket_info_cancelledtransaction`

If the ticket has not reached the required VPO investigation stage, the system does not load it for TRL submission.

### Duplicate protection

Before insertion, the reference number is checked against `mldb.trl`. A ticket cannot create another TRL record using a reference number that is already recorded.

### Database effect

Submission creates the same `mldb.trl` record and request-specific supporting record used by the other entry methods.

### Permission

This section specifically requires the `TRL Ticket Entry` permission.

## 4. TRL - Review

### Purpose

Use this section to review pending TRL records and confirm that a refund has been completed.

### Records shown

The page shows records from `mldb.trl` where `status IS NULL`. These are treated as pending records.

Supporting tables are joined so reviewers can see the extra information for wrong-biller, overstated-amount, and cancelled-transaction cases.

### Available functions

- Search by reference number
- View the total number of pending rows
- Group or filter records by type of request
- Filter records with an empty reference number
- Expand or collapse request-type sections
- Open a record to review its full details
- Preview and confirm a refund

### What Confirm Refund does

When the reviewer confirms a refund:

1. The selected `mldb.trl` row is locked for update.
2. The system checks that it has not already been processed.
3. Its status is changed from `NULL` to `REFUNDED`.
4. If the database has `date_refunded`, the current date and time are recorded.
5. If the database has `refunded_by`, the current user is recorded.

The status condition prevents two users from refunding the same TRL row at the same time.

After confirmation, the record is no longer displayed in the pending Review list.

### Permission

The page requires `TRL Review` or the compatible `Bills Payment` permission.

## 5. TRL - Report

### Purpose

Use this section to monitor TRL amounts and records by partner and sub-biller and to export report data to Excel.

It has three report modes.

### Summary Details

- Select a partner.
- View pending TRL amounts grouped by sub-biller and transaction year.
- View yearly totals, total receivable per sub-biller, and the grand total.
- Select a sub-biller row to view its detailed records.
- Export the partner report or an individual sub-biller report to Excel.

Only rows where `mldb.trl.status IS NULL` are included in this pending summary.

### Refunded Transaction

- Select a partner.
- View detailed TRL rows that already have a status.
- The current Review process sets that status to `REFUNDED`.
- See transaction, request-specific, reason, and status information.

This view uses records where `mldb.trl.status IS NOT NULL`.

### Sub Biller Report

- Select a partner.
- Choose one or more specific sub-billers.
- View pending amounts grouped by selected sub-biller and year.
- Export the selected sub-biller reports to Excel.

Only pending records with `status IS NULL` are included.

### Partner and sub-biller mapping

Report filters and names come from `mldb.subbiller`. A TRL row is associated with a sub-biller by matching `mldb.trl.wrong_biller_id` with `mldb.subbiller.sub_billers_id`.

If that mapping is missing or inconsistent, a TRL record may not appear under the expected partner or sub-biller report.

### Permission

The page requires `TRL Report` or the compatible `Bills Payment` permission.

## Record Status Meaning

| `mldb.trl.status` value | Meaning | Where it appears |
|---|---|---|
| `NULL` | Pending and not yet confirmed as refunded | TRL Review, Summary report, Sub Biller report |
| `REFUNDED` | Refund has been confirmed | Refunded Transaction report |

## Recommended Section by Task

| Task | Section to use |
|---|---|
| Import many Excel rows | TRL - Import |
| Create one record from an existing payment reference | TRL - Entry → Search by Reference No. |
| Encode one record manually | TRL - Entry → Input all fields directly |
| Load ticket information from the support-ticket workflow | TRL - Ticket Entry |
| Confirm a refund | TRL - Review |
| View pending amounts by partner/year | TRL - Report → Summary Details |
| View completed refunds | TRL - Report → Refunded Transaction |
| Report on selected sub-billers | TRL - Report → Sub Biller Report |

## Important Operational Notes

- A file selection or preview is not yet a completed database import.
- Reference-number duplicate protection is important because the reference number identifies the original transaction.
- Request-specific information must remain consistent with the selected type of request.
- Review confirmation changes the reporting category of a record from pending to refunded.
- Report completeness depends on accurate partner and sub-biller mappings.
