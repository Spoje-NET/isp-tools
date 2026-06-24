# ISP Tools

ISP network management tools for blocking/unblocking internet access based on AbraFlexi invoice status.

## Event-driven pipeline

```
abraflexi-reminder (3rd reminder sent)
        │
        │ emits: invoice.reminder.sent
        ▼
multiflexi-event-processor
        │ rule: invoice.reminder.sent → mark-defaulters runtemplate
        ▼
abraflexi-mark-defaulters
  - finds customers with UPOMINKA3 + active internet contract
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

## MultiFlexi Applications

This project provides three MultiFlexi applications:

### MarkDefaulters (`abraflexi-mark-defaulters`)

Identifies customers with the `UPOMINKA3` label (3rd reminder sent) who also
have an active **internet** service contract and marks them for disconnection by
adding the `ODPOJENO` label. Triggered by the `invoice.reminder.sent` event.

Customers with only VoIP, IpTV, Hosting or Housing contracts are **not** marked
for internet disconnection even if their invoices are overdue. Set `INET_CONTRACT_TYPE`
to the AbraFlexi `typSmlouvyK` code of internet contracts to enable precise filtering.

### BlockNet

Blocks internet access for all clients with the `ODPOJENO` (DISCONNECTED) label in AbraFlexi.

### UnblockNet

Unblocks internet access for all clients who are not in debt according to AbraFlexi invoices.

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
- `LABEL_NODISCONNECTED` - Label for customers not to disconnect (default: `NEODPOJOVAT`)
- `LABEL_VIP` - VIP customer label (default: `VIP`)

#### Subversion Repository (Legacy Backend)

- `SVNUSER` - Subversion repository username
- `SVNPASS` - Subversion repository password
- `SVNURL` - Subversion repository URL
- `SVNBIN` - Path to subversion binary (default: `/usr/bin/svn`)
- `LOGFILE` - Path to log file for operations

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

### Setting up event rules

After registering all apps in MultiFlexi and creating their runtemplates,
configure the event processor rules via `multiflexi-cli`:

```bash
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
```

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

1. Update `DeBlocker.php` constructor to use `NetBoxer` instead of `SubVersioner`:

   ```php
   $this->adapter = new NetBoxer();
   ```

2. Ensure all customer IPs are properly configured in NetBox with speed custom fields

3. Test blocking/unblocking operations

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
- MultiFlexi Core library
- NetBox (optional, for modern infrastructure management)

## License

MIT
