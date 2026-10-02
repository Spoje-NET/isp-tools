# ISP Tools
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![Packaging: deb](https://img.shields.io/badge/packaging-.deb-red?logo=debian&logoColor=white)

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
  - finds customers with UPOMINKA3 + active internet contract (typSml `INTERNET`)
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
        │ rule: banka/pokladna create → abraflexi-match-received-payment runtemplate
        │       env_mapping: {"DOCUMENTID": "recordid"}
        ▼
abraflexi-match-received-payment  (from abraflexi-matcher / multiflexi-abraflexi-matcher)
  exit 0: payment matched to invoice  (emits payment.received)
        │
        │ AbraFlexi: faktura-vydana updated (linked to payment)
        │ webhook: faktura-vydana, update
        ▼
  multiflexi-event-processor
        │ rule: payment.received / faktura-vydana update → potvrzeni-prijeti-uhrady runtemplate
        │       env_mapping: {"DOCID": "recordid"}
        ▼
  isp-potvrzeni-prijeti-uhrady
    - sends tax document confirmation to customer

  exit 2: payment found but not matched (unknown varsym / under/overpayment)
        │  emits payment.unmatched
        │ rule: payment.unmatched → potvrzeni-prijeti-bankovni-platby runtemplate
        │       env_mapping: {"DOCID": "recordid"}
        ▼
  isp-potvrzeni-prijeti-bankovni-platby
    - notifies customer their payment was received but awaits manual matching
```

> **Note on Pipeline B stage 2:** the `multiflexi-event-processor` reacts to
> AbraFlexi webhook changes (bank/cash record created, invoice settled), see
> [Setting up event rules](#setting-up-event-rules). Reacting to the matcher's
> exit code (`payment.unmatched`, exit 2) is not supported yet; until then run
> `isp-potvrzeni-prijeti-bankovni-platby` manually or from a wrapper
> that inspects the matcher's exit code.

## MultiFlexi Applications

This project provides five MultiFlexi applications. Pipeline B payment
matching is **not** shipped here — install
[`abraflexi-matcher`](https://github.com/VitexSoftware/abraflexi-matcher)
(`multiflexi-abraflexi-matcher`) and use its **AbraFlexi Payment Matcher**
app (`abraflexi-match-received-payment`, uuid
`23bf774d-de12-44b7-b4ef-454dd11ed8fd`).

### MarkDefaulters (`abraflexi-mark-defaulters`)

Identifies customers with the `UPOMINKA3` label (3rd reminder sent) who also
have an active **internet** service contract and marks them for disconnection by
adding the `ODPOJENO` label. Triggered by the `invoice.reminder.sent` event.

Customers with only VoIP, IpTV, Hosting or Housing contracts are **not** marked
for internet disconnection even if their invoices are overdue. Set `INET_CONTRACT_TYPE`
to the AbraFlexi contract type code (`typSml`, e.g. `INTERNET`) to enable precise filtering.

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

### MatchReceivedPayment (`abraflexi-match-received-payment`)

Provided by **abraflexi-matcher**, not this package. Matches a received
bank/cash payment to an unpaid issued invoice and links it via AbraFlexi
payment pairing (`sparovani`).

- Env: `DOCUMENTID` (record code or numeric id, required),
  `ABRAFLEXI_PARTIAL_MATCH` (settle underpayments automatically).
- Exit codes: `0` = matched and linked (emits `payment.received`),
  `2` = received but cannot be auto-matched (emits `payment.unmatched`),
  `1` = error (payment not found).
- See the [abraflexi-matcher README](https://github.com/VitexSoftware/abraflexi-matcher)
  for overpayment/underpayment behaviour and other matching tools.

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

#### Payment matching (Pipeline B)

Matching itself is configured on the **abraflexi-matcher** runtemplate
(`abraflexi-match-received-payment`). The confirmation scripts in this
package only need:

- `PAYMENT_EVIDENCE` - Evidence to load unmatched payments from: `banka`, `pokladna` or `auto` (default `auto`)
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
- `potvrzeni_prijeti_uhrady.multiflexi.app.json` - PotvrzeniPrijetiUhrady application definition
- `potvrzeni_prijeti_bankovni_platby.multiflexi.app.json` - PotvrzeniPrijetiBankovniPlatby application definition

Payment matching uses `match_received_payment.multiflexi.app.json` from
the `multiflexi-abraflexi-matcher` package (executable
`abraflexi-match-received-payment`).

### Event processor prerequisites

The event-driven pipelines need the whole delivery chain to work:

```
AbraFlexi ──webhook──▶ abraflexi-webhook-acceptor ──▶ changes_cache (SQL)
                                                          │ polled (30 s)
                                                          ▼
                              multiflexi-eventor (event source "AbraFlexiWebHookAcceptor")
                                                          │ event rules
                                                          ▼
                                                   MultiFlexi runtemplates
```

1. **Webhook acceptor** (`abraflexi-webhook-acceptor`) configured in
   `/etc/abraflexi-webhook-acceptor/.env` and reachable from the AbraFlexi
   server.
2. **A webhook registered in AbraFlexi** for the company — without it AbraFlexi
   sends nothing and no rule ever fires (check with
   `GET /c/<company>/hooks.json`; an empty `hooks` list means events are lost).
   Register it with the acceptor's `installer.php` or `POST /c/<company>/hooks`, URL
   `https://<host>/abraflexi-webhook-acceptor/webhook.php?company=<company>`.
3. **Event source** in MultiFlexi (`multiflexi-cli event-source:list`) pointing
   at the acceptor database; `multiflexi-eventor.service` running.

### Setting up event rules

After registering the ISP Tools apps **and** the AbraFlexi Payment Matcher
app (`multiflexi-abraflexi-matcher`) in MultiFlexi and creating their
runtemplates, configure the event processor rules via `multiflexi-cli`
(`event-rule:create`, options `--event_source_id --evidence --operation
--runtemplate_id --priority --enabled --env_mapping`). The `env_mapping` maps a
runtemplate variable to a column of the `changes_cache` row delivered by the
webhook acceptor: `recordid` (AbraFlexi record id), `evidence`, `operation`,
`externalids` (e.g. `code:E2ETEST2`), `inversion`. There is **no** `kod` column
and the `code:` prefix of `externalids` cannot be stripped, so the customer code
cannot be passed to the runtemplate from an `adresar` change.

Rules in use on the test deployment (vyvojar.spoje.net), highest priority first:

| # | Trigger | Runs | env_mapping | Status |
|---|---------|------|-------------|--------|
| 7 | `adresar` **update** (webhook) | Mark Defaulters | `{}` | works; sweep over all `UPOMINKA3` customers |
| 3 | `adresar` **update** (webhook) | BlockNet | `{"CUSTOMER":"kod"}` | works, but `kod` does not resolve → sweep (see below) |
| 6 | `adresar` **update** (webhook) | UnblockNet | `{}` | works; sweep over all `ODPOJENO` customers |
| 4 | `banka` **create** (webhook) | Match Received Payment | `{"DOCUMENTID":"recordid"}` | works |
| 5 | `pokladna` **create** (webhook) | Match Received Payment | `{"DOCUMENTID":"recordid"}` | same as 4 |
| 1 | `faktura-vydana` **settled** (webhook) | Clear Reminder Labels | `{"ABRAFLEXI_CUSTOMER":"firma"}` | **fails**: `firma` arrives as `code:KOD` and the app answers "Customer code:KOD not found" |

```bash
# Rules 3/6/7: any adresar change → mark defaulters, block, unblock (in this order)
multiflexi-cli event-rule:create --event_source_id 1 --evidence adresar --operation update \
  --runtemplate_id <MARK_DEFAULTERS_RUNTEMPLATE_ID> --priority 20
multiflexi-cli event-rule:create --event_source_id 1 --evidence adresar --operation update \
  --runtemplate_id <BLOCKNET_RUNTEMPLATE_ID> --priority 10
multiflexi-cli event-rule:create --event_source_id 1 --evidence adresar --operation update \
  --runtemplate_id <UNBLOCKNET_RUNTEMPLATE_ID> --priority 0

# Rules 4/5: new bank / cash record → match the payment
# (recordid = AbraFlexi record id; `id` would be the cache row id and match a wrong payment!)
multiflexi-cli event-rule:create --event_source_id 1 --evidence banka --operation create \
  --runtemplate_id <MATCHER_RUNTEMPLATE_ID> --env_mapping '{"DOCUMENTID":"recordid"}'
multiflexi-cli event-rule:create --event_source_id 1 --evidence pokladna --operation create \
  --runtemplate_id <MATCHER_RUNTEMPLATE_ID> --env_mapping '{"DOCUMENTID":"recordid"}'
```

**Rules that fire when another runtemplate finishes** (`runtemplate_source_id`)
only work if the finished job *produces* data (`produces` in its application
definition); otherwise the chaining engine skips it. Reminder, Clear Reminder Labels
and Mark Defaulters produce nothing, so such rules never fire — use the webhook
rules above instead (the Reminder sets `UPOMINKA3`, which AbraFlexi reports as an
`adresar` update).

> **Blocking, unblocking and marking are sweeps.** `CUSTOMER` is not passed (rule 3
> maps a column that does not exist), so every `adresar` change runs the three tools over
> **all** relevant customers. They are idempotent, and UnblockNet keeps customers
> that still have overdue invoices blocked, but a customer labelled `ODPOJENO`
> *without* overdue invoices is unblocked and loses the label right away. Beware of
> this on test AbraFlexi instances with many `ODPOJENO` customers (vyvojar DEV had
> 25; one `adresar` change cleared them).

> **Rule 1** needs a fix in `abraflexi-reminder` (accept `code:` prefixed customers)
> or in the event processor (strip the prefix); until then Clear Reminder Labels
> has to be started manually with `ABRAFLEXI_CUSTOMER=<kod>`.

> **`payment.unmatched`** (matcher exit 2 → bank payment notification) is not
> available: the event processor does not react to job exit codes yet.
> the event processor does not react to job exit codes yet.

#### INET_CONTRACT_TYPE

Set to `INTERNET` for Spoje.net deployment to restrict disconnection
to customers with an active (`stavSml` = `AKTIVNI`) contract of type `INTERNET`
(evidence *typ-smlouvy*). `code:INTERNET` and the legacy `typSmlouvy.INTERNET`
spelling are accepted too. Leave empty to match all contract types.

#### Customer labels

| Label | Set by | Meaning |
|-------|--------|---------|
| `UPOMINKA3` | abraflexi-reminder | 3rd payment reminder sent |
| `ODPOJENO` | abraflexi-mark-defaulters | Customer marked for disconnection |
| `NEODPOJOVAT` | Manual | Never disconnect this customer |
| `VIP` | Manual | Skip disconnection for VIP customers |

Notes:

- A label must exist in the AbraFlexi *štítky* list before it can be set; setting
  an unknown label fails with `success: false`. On the vyvojar DEV company
  `NEODPOJOVAT` does not exist yet (`ODPOJENO`, `UPOMINKA3`, `VIP` do).
- On update AbraFlexi **merges** the `stitky` field, it does not replace it.
  Removing labels needs the `stitky@removeAll` directive
  (`Adresar::unsetLabel()`); writing a shorter list leaves the old labels in place.

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

### Rehearsal on a test deployment (vyvojar.spoje.net)

The complete flow is rehearsed on `vyvojar.spoje.net` against the **DEV** AbraFlexi
(`flexibee-dev.spoje.net`, company `spoje_net_s_r_o_`) and a **local** Subversion
repository (`file:///var/lib/isp-tools-test-svn/hosts-repo/hostsbrevnov`, user
`multiflexi-test`), never against the production hosts repository. MultiFlexi company 21
("Testing") holds the runtemplates *Block Internet Access*, *Unblock Internet Access*,
*Mark Defaulters - Testing* and *Match Received Payment*; the AbraFlexi connection comes
from the shared credential *Testing / AbraFlexi Testing*. Test customers `E2ETEST1`
(10.99.99.11) and `E2ETEST2` (10.99.99.12) have `{code:…}` lines in the test `hosts` file.

Single customer from the command line (one-time `CUSTOMER` override):

```bash
multiflexi-cli run-template:schedule --id <BLOCK_RT> --schedule_time now --env CUSTOMER=E2ETEST2
svn log -v -l 1 file:///var/lib/isp-tools-test-svn/hosts-repo/hostsbrevnov   # Auto-block-IP-…
```

Verified so far:

| Case | Result |
|------|--------|
| Disconnect: `ODPOJENO` label → BlockNet | commit `Auto-block-IP-…`, line becomes `# speed=0 orig=N …` |
| Reconnect: UnblockNet without debt | commit `Auto-unblock-IP-…`, original speed restored from `orig=`, label removed |
| Customer with unpaid overdue invoice | UnblockNet keeps the block (`still_owes`), no commit |
| Customer labelled `VIP` | skipped by BlockNet, hosts file untouched |
| Customer with no IP in `hosts` | reported as "No IP addresses found", no commit |
| Mark Defaulters with no `UPOMINKA3` customer | exit 0, "No customers with UPOMINKA3 label found." |
| Webhook chain (AbraFlexi → acceptor → eventor → BlockNet) | label change blocks the customer within ~1 minute |

| Customer labelled `NEODPOJOVAT` | skipped by BlockNet (label had to be created in DEV AbraFlexi first) |
| Mark Defaulters (`UPOMINKA3` + active `INTERNET` contract) | customer gets `ODPOJENO`, webhook → BlockNet commits `speed=0` (needed the `stavSml`/`typSml` fix) |
| Bank payment with matching VS → rule 4 → matcher | invoice settled (exit 0) |
| `adresar` change → UnblockNet (rule 6) | customers without debt unblocked, labels removed |

Not verified end to end: the complete cycle on one customer without manual steps
(Clear Reminder Labels has to be started by hand, see rule 1), and the confirmation
mails (Payment Received Confirmation fails with `MAIL_FROM is not set` on the test
runtemplate).

## Packaging assets

- `isp-tools.svg` — application icon (installed to `hicolor/scalable/apps`)
- `multiflexi/<application-uuid>.svg` — one icon per MultiFlexi application,
  installed to the shared `/usr/share/multiflexi/images/`
- `debian/io.github.spoje_net.isp_tools.metainfo.xml` — AppStream metadata
  (validate with `appstreamcli validate --no-net`)

## Requirements

- PHP >= 8.1
- AbraFlexi account
- vitexsoftware/abraflexi-bricks library
- NetBox (optional, for modern infrastructure management)

## License

MIT
