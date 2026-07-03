# ISP Tools

ISP network management tools for blocking/unblocking internet access based on AbraFlexi invoice status.

## Pipeline A — Reminder → Disconnection

```
abraflexi-reminder (3rd reminder sent)
        │
        │ emits: invoice.reminder.sent
        ▼
multiflexi-event-processor
        │ rule: invoice.reminder.sent → mark-defaulters runtemplate
        ▼
abraflexi-mark-defaulters
  - finds customers with UPOMINKA3 + active internet contract (typSmlouvy.INTERNET)
  - sets ODPOJENO label in AbraFlexi
        │
        │ AbraFlexi webhook: adresar updated
        ▼
multiflexi-event-processor
        │ rule: adresar update → blocknet runtemplate
        ▼
blocknet
  - disconnects all customers with ODPOJENO label
```

Payment clears → `abraflexi-reminder-clean-labels` removes `UPOMINKA*` →
`multiflexi-event-processor` triggers `unblocknet` → internet restored.

## Pipeline B — Bank Payment → Matching → Confirmation

```
AbraFlexi: new record in banka or pokladna evidence
        │
        │ webhook via abraflexi-webhook-acceptor → changes_cache
        ▼
multiflexi-event-processor
        │ rule: banka/pokladna create → match-received-payment runtemplate
        │       env_mapping: {"DOCUMENTID": "recordid"}
        ▼
isp-match-received-payment
  exit 0: payment matched to invoice
        │
        │ AbraFlexi: faktura-vydana updated (linked to payment)
        │ webhook: faktura-vydana, update
        ▼
  multiflexi-event-processor
        │ rule: faktura-vydana update → potvrzeni-prijeti-uhrady runtemplate
        │       env_mapping: {"DOCID": "recordid"}
        ▼
  isp-potvrzeni-prijeti-uhrady
    - sends tax document confirmation to customer

  exit 2: payment found but not matched (unknown varsym / under/overpayment)
        │
        │ rule: payment.unmatched → potvrzeni-prijeti-bankovni-platby runtemplate
        │       env_mapping: {"DOCID": "recordid"}
        ▼
  isp-potvrzeni-prijeti-bankovni-platby
    - notifies customer their payment was received but awaits manual matching
```

> **Note on Pipeline B stage 2:** rules 4–6 below work today — the
> `multiflexi-event-processor` reacts to AbraFlexi webhook changes and the
> matched invoice update (rule 6) is itself a `faktura-vydana` webhook change.
> Rule 7 (`payment.unmatched`) requires the event processor to react to job
> exit codes / `job.completed`, which it does not support yet; until then run
> `isp-potvrzeni-prijeti-bankovni-platby` manually or from a wrapper
> that inspects the matcher's exit code.

## MultiFlexi Applications

This project provides six MultiFlexi applications:

### MarkDefaulters (`abraflexi-mark-defaulters`)

Identifies customers with the `UPOMINKA3` label (3rd reminder sent) who also
have an active **internet** service contract and marks them for disconnection by
adding the `ODPOJENO` label. Triggered by the `invoice.reminder.sent` event.

Customers with only VoIP, IpTV, Hosting or Housing contracts are **not** marked
for internet disconnection even if their invoices are overdue. Set `INET_CONTRACT_TYPE`
to the AbraFlexi `typSmlouvyK` code of internet contracts to enable precise filtering.

### BlockNet (`blocknet`)

Blocks internet access for all clients with the `ODPOJENO` (DISCONNECTED) label in AbraFlexi.
Customers labelled `VIP` or `NEODPOJOVAT` are skipped. Customer IP addresses are
resolved through the configured network backend and each IP is blocked by setting
its speed to 0.

### UnblockNet (`unblocknet`)

Restores internet access for disconnected customers who no longer owe:

1. Finds customers with the `ODPOJENO` label.
2. Checks AbraFlexi for unpaid **overdue** issued invoices per customer.
3. Customers without debt get all their IPs unblocked (the backend restores the
   original speed recorded at block time; `DEFAULT_SPEED` is the fallback).
4. After a successful unblock the `ODPOJENO` label is removed from the customer.

### MatchReceivedPayment (`isp-match-received-payment`)

Matches a received bank/cash payment to an unpaid issued invoice by variable
symbol and links it via AbraFlexi payment pairing (`sparovani`).

- Env: `DOCUMENTID` (record code or numeric id, required), `PAYMENT_EVIDENCE`
  (`banka|pokladna|auto`, default `auto`), `MATCH_OVERPAY_MODE`
  (`settle|manual`, default `settle`), `LABEL_OVERPAY`, `LABEL_INVOICE_MISSING`,
  `LABEL_UNIDENTIFIED`.
- Exit codes: `0` = matched and linked, `2` = received but cannot be
  auto-matched (unknown variable symbol, ambiguity, or overpayment in `manual`
  mode) — emits `payment.unmatched`, `1` = error.
- Underpayment is linked as a partial payment (`castecnaUhrada`).

### PotvrzeniPrijetiUhrady (`isp-potvrzeni-prijeti-uhrady`)

Sends the customer a payment-received confirmation email with the tax document
(invoice PDF) attached. Skips invoices that are not (at least partially) paid,
so it is safe to trigger from a generic `faktura-vydana` update rule.

- Env: `DOCID` (faktura-vydana code, required), `EASE_FROM`, `MUTE`
  (`true` = dry run).
- Exit codes: `0` = sent (or dry run / not paid), `1` = error (unknown
  document, no email, send failure).

### PotvrzeniPrijetiBankovniPlatby (`isp-potvrzeni-prijeti-bankovni-platby`)

Notifies the customer that their bank payment was received but awaits manual
matching by accounting.

- Env: `DOCID` (bank record code or numeric id, required), `PAYMENT_EVIDENCE`,
  `EASE_FROM`, `MUTE`.
- Exit codes: `0` = notified (unknown payer is a warning, not an error),
  `1` = error.

## Installation

```bash
composer install
```

## Configuration

Copy `.env.example` to `.env` and configure your connections:

```bash
cp .env.example .env
```

### Required Configuration Keys

#### AbraFlexi Connection

- `ABRAFLEXI_URL` - Your AbraFlexi server URL (e.g., `https://your-server.com:5434`)
- `ABRAFLEXI_LOGIN` - AbraFlexi username
- `ABRAFLEXI_PASSWORD` - AbraFlexi password
- `ABRAFLEXI_COMPANY` - Company code in AbraFlexi

#### Application Settings

- `EASE_LOGGER` - Logging configuration (default: `console|syslog`)
- `RESULT_FILE` - Output file for results (default: `isp_tools_result.json`)
- `APP_DEBUG` - Debug mode (default: `false`)

#### Customer Labels

- `LABEL_DISCONNECTED` - Label for disconnected customers (default: `ODPOJENO`)
- `LABEL_NODISCONNECT` - Label for customers not to disconnect (default: `NEODPOJOVAT`)
- `LABEL_VIP` - VIP customer label (default: `VIP`)
- `LABEL_THIRD_REMINDER` - Label set by abraflexi-reminder after the 3rd reminder (default: `UPOMINKA3`)

#### Payment Matching (Pipeline B)

- `MATCH_OVERPAY_MODE` - Overpayment handling: `settle` or `manual` (default: `settle`)
- `PAYMENT_EVIDENCE` - Evidence to load payments from: `banka`, `pokladna` or `auto` (default: `auto`)
- `LABEL_OVERPAY` - Label for overpaid documents (default: `PREPLATEK`)
- `LABEL_INVOICE_MISSING` - Label for payments without a matching invoice (default: `CHYBIFAKTURA`)
- `LABEL_UNIDENTIFIED` - Label for unidentified payments (default: `NEIDENTIFIKOVANO`)
- `EASE_FROM` - Sender address for confirmation emails
- `MUTE` - `true` = dry run, confirmation emails are not actually sent

#### Unblocking

- `DEFAULT_SPEED` - Fallback speed used when the backend has no stored original speed (default: `0`)

#### Subversion Repository (Legacy Backend)

- `SVNUSER` - Subversion repository username
- `SVNPASS` - Subversion repository password
- `SVNURL` - Subversion repository URL
- `SVNBIN` - Path to subversion binary (default: `/usr/bin/svn`)
- `LOGFILE` - Path to log file for operations

Blocking rewrites the hosts-file comment of the customer's IP line to
`# speed=0 orig=<previous speed>`; unblocking restores the speed recorded in
the `orig=` token (falling back to `DEFAULT_SPEED` when the line never carried
a `speed=` value). Customer IPs are resolved preferably by the machine-readable
`{code:XXXXX}` comment token.

#### NetBox API (Modern Backend)

- `NETBOXURL` - NetBox server URL (e.g., `https://netbox.yourdomain.com`)
- `NETBOXTOKEN` - NetBox API token for authentication

## Usage

### Mark customers for disconnection

```bash
bin/abraflexi-mark-defaulters
```

### Block Internet Access

```bash
bin/blocknet
```

### Unblock Internet Access

```bash
bin/unblocknet
```

## MultiFlexi Integration

These applications are designed to work with MultiFlexi for automated scheduling and execution.

The MultiFlexi application definitions are located in the `multiflexi/` directory:

- `mark_defaulters.multiflexi.app.json` - MarkDefaulters application definition
- `blocknet.multiflexi.app.json` - BlockNet application definition
- `unblocknet.multiflexi.app.json` - UnblockNet application definition
- `match_received_payment.multiflexi.app.json` - MatchReceivedPayment application definition
- `potvrzeni_prijeti_uhrady.multiflexi.app.json` - PotvrzeniPrijetiUhrady application definition
- `potvrzeni_prijeti_bankovni_platby.multiflexi.app.json` - PotvrzeniPrijetiBankovniPlatby application definition

### Setting up event rules

After registering all apps in MultiFlexi and creating their runtemplates,
configure the event processor rules via `multiflexi-cli`:

```bash
# ── Pipeline A: Reminder → Disconnection ──────────────────────────────────

# Rule 1: after 3rd reminder → mark customers for disconnection
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "invoice.reminder.sent" \
  --operation "any" \
  --runtemplate_id <MARK_DEFAULTERS_RUNTEMPLATE_ID> \
  --priority 10 \
  --enabled 1

# Rule 2: after ODPOJENO is set (adresar webhook) → block internet
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "adresar" \
  --operation "update" \
  --runtemplate_id <BLOCKNET_RUNTEMPLATE_ID> \
  --priority 5 \
  --enabled 1

# Rule 3: after UPOMINKA* labels removed (adresar webhook) → unblock internet
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "adresar" \
  --operation "update" \
  --runtemplate_id <UNBLOCKNET_RUNTEMPLATE_ID> \
  --priority 5 \
  --enabled 1

# ── Pipeline B: Bank Payment → Matching → Confirmation ────────────────────

# Rule 4: new bank record → run payment matcher
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "banka" \
  --operation "create" \
  --runtemplate_id <MATCHER_RUNTEMPLATE_ID> \
  --priority 20 \
  --enabled 1 \
  --env_mapping '{"DOCUMENTID":"recordid"}'

# Rule 5: new cash record → run payment matcher
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "pokladna" \
  --operation "create" \
  --runtemplate_id <MATCHER_RUNTEMPLATE_ID> \
  --priority 20 \
  --enabled 1 \
  --env_mapping '{"DOCUMENTID":"recordid"}'

# Rule 6: invoice updated (= matched payment) → send tax document confirmation
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "faktura-vydana" \
  --operation "update" \
  --runtemplate_id <POTVRZENI_PRIJETI_UHRADY_RUNTEMPLATE_ID> \
  --priority 10 \
  --enabled 1 \
  --env_mapping '{"DOCID":"recordid"}'

# Rule 7: unmatched payment (exit 2 from matcher) → notify customer
multiflexi-cli eventrule create \
  --event_source_id 1 \
  --evidence "payment.unmatched" \
  --operation "any" \
  --runtemplate_id <POTVRZENI_PRIJETI_BANKOVNI_PLATBY_RUNTEMPLATE_ID> \
  --priority 10 \
  --enabled 1 \
  --env_mapping '{"DOCID":"recordid"}'
```

#### INET_CONTRACT_TYPE

Set to `typSmlouvy.INTERNET` for Spoje.net deployment to restrict disconnection
to customers with active internet contracts only (typSmlouvy code `INTERNET`).
Leave empty to match all contract types.

#### Customer labels

| Label | Set by | Meaning |
|-------|--------|---------|
| `UPOMINKA3` | abraflexi-reminder | 3rd payment reminder sent |
| `ODPOJENO` | abraflexi-mark-defaulters | Customer marked for disconnection |
| `NEODPOJOVAT` | Manual | Never disconnect this customer |
| `VIP` | Manual | Skip disconnection for VIP customers |

## NetBox Integration

For modern infrastructure management, the system supports NetBox as an alternative backend to Subversion.

### Configuration

Add NetBox settings to your `.env` file:

```bash
# NetBox API Configuration
NETBOXURL=https://your-netbox-instance.com
NETBOXTOKEN=your-api-token-here
```

### NetBox Setup

1. **IP Address Management**: Ensure your customer IP addresses are registered in NetBox IPAM
2. **Custom Fields**: Add a custom field named `speed` to IP addresses:
   - Type: Integer
   - Label: Speed
   - Description: Internet connection speed in Mbps

### Migration from Subversion

To switch from Subversion-based management to NetBox:

1. Pass a `NetBoxer` instance to the `DeBlocker` constructor (it defaults to
   `SubVersioner`):

   ```php
   $deblocker = new \SpojeNet\DeBlocker(new \SpojeNet\NetBoxer());
   ```

2. Ensure all customer IPs are properly configured in NetBox with speed custom fields

3. Test blocking/unblocking operations

> **Note:** the NetBox backend currently implements only customer IP lookup;
> its `blockIp()`/`unblockIp()` operations are not implemented yet.

### NetBox API Requirements

- NetBox API access with token authentication
- IP addresses must have custom field `speed` for speed management
- Blocking sets speed to 0, unblocking restores the configured speed

## Testing

The project includes comprehensive unit tests for all components.

### Running Tests

```bash
composer install
vendor/bin/phpunit
```

### Test Repository

For testing the Subversion backend, a test repository is included in `tests/svn/` containing:
- Subversion repository with sample hosts file
- Working copy for testing operations
- Documentation in `tests/svn/README.md`

The test repository allows testing blocking/unblocking operations without affecting production systems.

## Requirements

- PHP >= 8.1
- AbraFlexi account
- vitexsoftware/abraflexi-bricks library
- NetBox (optional, for modern infrastructure management)

## License

MIT
