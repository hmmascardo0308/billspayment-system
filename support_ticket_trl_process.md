# Support Ticket and TRL Process Order

## Which Process Comes First?

For a bills-payment concern reported by a **Branch**, the **Support Ticket process comes first**, followed by the **Transaction Request Log (TRL)** process.

The recommended sequence is:

`Support Ticket -> VPO Investigation -> TRL Entry -> Refund Review -> Reporting`

## Standard Process for a Branch-Reported Concern

### 1. Branch Creates a Support Ticket

The Branch reports the bills-payment concern and provides the transaction information, reason, request type, and supporting attachments.

The Support Ticket is used to:

- Record the initial concern.
- Communicate with the support teams.
- Assign responsibility for the issue.
- Track replies, actions, and timestamps.
- Escalate the concern from VPO to CAD when necessary.

### 2. VPO Accepts and Investigates the Ticket

A VPO user accepts the ticket and becomes its VPO owner. VPO reviews the transaction details, communicates with the Branch, and determines what correction or refund action is required.

The ticket must have a VPO owner before it can be loaded through the TRL Ticket Entry flow. This prevents an uninvestigated ticket from being converted into a formal transaction request.

### 3. The Concern Is Entered into TRL

After VPO has investigated the ticket, its details can be loaded into TRL using the ticket number. TRL stores the formal transaction correction or refund request.

This record may contain:

- The transaction reference number.
- Transaction date and time.
- Account and payment branch details.
- Original or wrong biller information.
- The type of request and reason.
- Correct biller or corrected amount details.

### 4. The TRL Request Is Reviewed

The TRL record remains pending while the required correction or refund is being processed. An authorized user reviews the request and confirms when the refund has been completed.

### 5. The TRL Record Is Marked as Refunded

After refund confirmation, the TRL status is changed to `REFUNDED`. Where supported by the database, the system also records the refund date and the user who confirmed it.

### 6. The Result Appears in TRL Reports

The completed record becomes available in the Refunded report. Users can also generate partner and subbiller summaries or export the information to Excel for reconciliation and monitoring.

## Process Flow

```text
Branch identifies a payment concern
                |
                v
Branch creates a Support Ticket
                |
                v
VPO accepts and investigates the ticket
                |
                v
Is a transaction correction or refund required?
        |                       |
       Yes                      No
        |                       |
        v                       v
Create a TRL record      Resolve or close the ticket
        |
        v
Process and review the request
        |
        v
Confirm the refund
        |
        v
TRL status becomes REFUNDED
        |
        v
Include the result in TRL reports
```

## When Can TRL Come First?

A Support Ticket is not required for every TRL record. TRL can start directly when the concern is already verified or comes from an established operational source.

TRL may be created independently through:

| TRL method | When it is used |
|---|---|
| **Auto Entry** | An existing transaction can be found using its reference number. |
| **Manual Entry** | An authorized user already has the complete transaction and request details. |
| **Import** | Multiple verified TRL records need to be uploaded from an Excel file. |

In these cases, the process can be:

`TRL Entry or Import -> Refund Review -> Reporting`

## Decision Guide

| Situation | First process |
|---|---|
| A Branch is reporting a new concern that needs investigation. | **Support Ticket first** |
| VPO or CAD needs to communicate with the Branch or investigate the issue. | **Support Ticket first** |
| An investigated Support Ticket requires a transaction correction or refund. | **Create TRL after VPO investigation** |
| An authorized user already has a verified transaction request. | **TRL can be created directly** |
| Many verified requests are available in an Excel file. | **TRL Import can be used directly** |
| The concern is resolved without requiring a correction or refund. | **Close the Support Ticket; TRL may not be needed** |

## Support Ticket and TRL Responsibilities

| Support Ticket | TRL |
|---|---|
| Starts the Branch concern and investigation. | Records the formal transaction request. |
| Manages communication and attachments. | Stores correction and refund details. |
| Assigns the issue to VPO and, when needed, CAD. | Supports refund review and confirmation. |
| Tracks the issue's complete action history. | Supports reconciliation, reports, and Excel exports. |

## Final Rule

Use this rule when deciding which process should come first:

> If the concern still needs communication, ownership, or investigation, create a **Support Ticket first**. If the transaction request is already verified and ready for correction or refund processing, it may proceed directly to **TRL**.

## Related Documentation

- `support_ticket_explanation.md` explains the purpose and workflow of the Support Ticket module.
- `billspayment_trl_explanation.md` explains the purpose and workflow of Bills Payment - TRL.
- `support_ticket.md` contains the detailed Support Ticket business rules and technical specification.
