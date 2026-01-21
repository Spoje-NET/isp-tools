# ISP Tools

ISP network management tools for blocking/unblocking internet access based on AbraFlexi invoice status.

## MultiFlexi Applications

This project provides two MultiFlexi applications:

### BlockNet
Blocks internet access for all clients with the ODPOJEN (DISCONNECTED) label in AbraFlexi.

### UnblockNet
Unblocks internet access for all clients who are not in debt according to AbraFlexi invoices.

## Installation

```bash
composer install
```

## Configuration

Copy `.env.example` to `.env` and configure your AbraFlexi connection:

```bash
cp .env.example .env
```

Edit `.env` with your actual credentials:
- `ABRAFLEXI_URL` - Your AbraFlexi server URL
- `ABRAFLEXI_LOGIN` - AbraFlexi username
- `ABRAFLEXI_PASSWORD` - AbraFlexi password
- `ABRAFLEXI_COMPANY` - Company code in AbraFlexi

## Usage

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
- `blocknet.multiflexi.app.json` - BlockNet application definition
- `unblocknet.multiflexi.app.json` - UnblockNet application definition

## Requirements

- PHP >= 7.4
- AbraFlexi account
- MultiFlexi Core library

## License

MIT