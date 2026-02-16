# Project Context – isp-tools (BlockNet / UnblockNet)

This document contains specific coding standards and practices for the ISP tools development. All AI assistants must follow these guidelines when working on this codebase.

## Project Context
- **Language**: PHP 8.4 or later
- **Framework**: Ease Core library for PHP applications
- **Testing**: PHPUnit for unit testing
- **Standards**: PSR-12 coding standard
- **Internationalization**: Uses i18n library with `_()` function for translatable strings

## Language and Communication

### Language Requirements

- All code comments must be written in English
- All user-facing messages and error messages must be written in English
- All documentation must use Markdown format
- Commit messages must use imperative mood and be concise

### Code Comments

- Use complete sentences with proper grammar
- Explain the "why" and "how", not just "what"
- Keep comments up-to-date with code changes

## Code Quality Standards

### PHP Version and Compatibility

- **MANDATORY**: All code must be written in PHP 8.4 or later
- Ensure compatibility with the latest PHP version and all used libraries
- Consider performance implications and optimize where necessary

### Coding Standards

- **MANDATORY**: Follow PSR-12 coding standard strictly
- Use meaningful, descriptive variable names that clearly indicate their purpose
- Avoid magic numbers or strings - define named constants instead
- **MANDATORY**: Include type hints for all function parameters and return types

### Documentation

- **MANDATORY**: Include PHPDoc docblocks for all classes, functions, and methods
- Docblocks must describe:
  - Purpose of the class/function
  - All parameters with their types and descriptions
  - Return types and descriptions
  - Any exceptions that may be thrown

## Security and Error Handling

### Security Practices

- Never expose sensitive information in code or logs
- Handle exceptions properly with meaningful error messages
- Validate all inputs and sanitize outputs

### Error Handling

- Always catch and handle exceptions appropriately
- Provide clear, actionable error messages to users
- Log errors securely without exposing sensitive data

## Testing Requirements

### Unit Testing

- **MANDATORY**: Use PHPUnit for all testing
- **MANDATORY**: Follow PSR-12 standard in test files
- **MANDATORY**: Create or update PHPUnit test files for every new or modified class
- Ensure all code is well-tested with comprehensive unit tests
- Tests must cover edge cases and error conditions

### Testing Workflow

- Write tests before implementing new features (TDD approach preferred)
- Run tests after every code change to ensure functionality
- Maintain high test coverage for reliability

## Internationalization

- **MANDATORY**: Use the `_()` function for all user-facing strings that need translation
- Never hardcode translatable text in the source code
- Support multiple languages through the i18n system

## Code Maintenance

- Ensure code is maintainable and follows best practices
- Refactor when necessary to improve readability and performance
- Keep dependencies up-to-date and compatible

## Quality Assurance

### Code Validation

- **MANDATORY**: After every PHP file edit, run `php -l <filename>` to check syntax
- Fix any syntax errors immediately before proceeding
- Use static analysis tools (PHPStan) to catch potential issues

### Code Style

- Use automated tools to enforce coding standards
- Keep code consistent across the entire codebase
- Review code for readability and maintainability

## Development Workflow

### File Changes

- When creating new classes: Always create corresponding test files
- When modifying existing classes: Always update or create corresponding test files
- Test all changes thoroughly before committing

### Commit Practices

- Use clear, descriptive commit messages in imperative mood
- Commit related changes together
- Ensure all tests pass before pushing changes

## Priority Guidelines

1. **MANDATORY** items must always be followed - these are critical requirements
2. Security and error handling take precedence over other considerations
3. Code must be functional and tested before style/formatting concerns
4. Maintain backward compatibility unless explicitly stated otherwise
5. Performance optimizations should not compromise code readability or maintainability

### Purpose

This project automates blocking and unblocking internet connectivity for customers based on their payment status.

- Customers with unpaid invoices must be **blocked**.
- Customers without debt must be **unblocked**.
- Blocking is implemented by setting customer IP speed to `0`.
- Unblocking restores the contractual speed assigned to the customer.

The source of truth for debtor status is the accounting system (MultiFlexi / AbraFlexi API).

---

## High-Level Architecture

There are two CLI entrypoints:

- `src/BlockNet.php`
- `src/UnblockNet.php`

Both use shared domain logic implemented in:

- `src/SpojeNet/DeBlocker.php`

Network configuration is applied via adapters:

- `src/SpojeNet/SubVersioner.php` (legacy, currently active)
- `src/SpojeNet/NetBoxer.php` (new backend, future primary implementation)

For a transition period, both adapters may be used in parallel.

---

## Core Concepts

### Blocking logic

Blocking means:

- Set customer IP speed to `0`.

Unblocking means:

- Restore the IP speed to the value defined in the customer contract.

Speed must never be guessed.
Speed must always come from authoritative data (accounting system / contract).

---

## Class Responsibilities

### BlockNet.php

- Fetch list of customers with unpaid invoices.
- Pass them to `DeBlocker` for blocking.
- Must not contain low-level network logic.

### UnblockNet.php

- Fetch list of customers without debt.
- Pass them to `DeBlocker` for unblocking.
- Must not contain network-specific logic.

### DeBlocker.php

This is the core orchestration layer.

Responsibilities:

- Accept domain-level operations (block / unblock).
- Decide which backend adapter(s) to call.
- Contain shared decision logic.
- Be backend-agnostic.

It must:

- Not contain SVN-specific logic.
- Not contain NetBox-specific logic.
- Work only with abstractions.

Design goal:
DeBlocker coordinates adapters via a common interface.

### SubVersioner.php (Legacy Adapter)

Responsible for:

- Managing a hosts-like file containing:

  - IP address
  - Assigned speed
- Updating that file
- Committing changes to Subversion repository

This is considered legacy and must be isolated behind an adapter interface.

### NetBoxer.php (Future Adapter)

Responsible for:

- Communicating with NetBox API using `mkevenaar/netbox` library.
- Finding IP addresses by address in NetBox IPAM.
- Updating custom field `speed` on IP addresses (0 for block, configured speed for unblock).
- Using NetBox API token authentication.

Implementation uses `\Ease\Shared::cfg('netbox.url')` and `\Ease\Shared::cfg('netbox.token')` for configuration.

NetBox must become the primary backend in the future.

---

## Architectural Direction

### Adapter Pattern

Network backend logic must be abstracted.

Introduce and use an interface, e.g.:

```php
interface NetworkBackendInterface
{
    public function blockIp(string $ip): void;
    public function unblockIp(string $ip, int $speed): void;
}
```

Both:

- SubVersioner
- NetBoxer

must implement this interface.

DeBlocker must depend only on the interface.

---

## Migration Strategy

Current state:

- SubVersioner is production backend.
- NetBoxer is implemented and ready for use.

Target state:

- NetBoxer becomes primary.
- SubVersioner may be removed later.

During transition:

- DeBlocker may call both adapters.
- Logic must remain deterministic and idempotent.

To switch to NetBoxer:

1. Configure NetBox URL and token in `.env`
2. Ensure IP addresses exist in NetBox with `speed` custom field
3. Change `DeBlocker` constructor: `$this->adapter = new NetBoxer();`
4. Test operations

---

## Important Constraints

1. No business logic in adapters.
2. No backend logic in BlockNet/UnblockNet.
3. All domain decisions belong to DeBlocker.
4. Blocking/unblocking must be idempotent.
5. Do not mix infrastructure and domain logic.

---

## Coding Expectations

- Strict typing enabled.
- Avoid global state.
- Use dependency injection.
- Write small, testable methods.
- Do not duplicate logic between adapters.
- Avoid hardcoding values (especially speeds).

---

## When Generating Code

Copilot / LLM should:

- Preserve separation of concerns.
- Prefer interfaces over concrete dependencies.
- Keep orchestration inside DeBlocker.
- Keep IO/API/file operations inside adapters only.
- Avoid mixing accounting API logic with network backend logic.

---

## Summary

This project enforces payment discipline by:

Accounting System → DeBlocker → Network Backend Adapter (SVN / NetBox)

The goal is to fully migrate from Subversion-based file management to NetBox-based infrastructure management, without breaking existing behavior.

All new development should move toward a clean adapter-based architecture with NetBox as the future primary backend.
