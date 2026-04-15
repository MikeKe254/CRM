# Customer Canonical Note

This note captures the intended future direction for customer identity and branch-level customer intelligence.

## Current Direction

Customer identity should remain canonical at the company level.

That means:

- one customer record per real person per company
- one phone number per company
- no duplicate customer identities across branches by default

In practical terms:

- `customers` should remain the master identity table
- customer uniqueness should stay tenant-wide, not branch-wide

## Why

This keeps the CRM clean and intelligent:

- one person stays one person
- transaction history stays unified
- lifetime value stays accurate
- cross-branch behavior remains visible
- personalization becomes stronger instead of fragmented

Avoiding duplicate customer rows prevents:

- split customer history
- multiple balances or profiles for the same person
- merge headaches later
- confusing branch-to-branch support issues

## Branch-Level Intelligence

Even though customer identity remains company-wide, branch teams still need branch-specific visibility.

The intended model is:

- canonical customer identity at company level
- branch-scoped customer intelligence layered on top

That branch-local layer can hold things like:

- first seen at branch
- last seen at branch
- visit count at branch
- spend total at branch
- average order value at branch
- favorite items or categories at branch
- branch-local notes
- branch-local tags
- branch-local staff relationships

## Suggested Future Model

1. `customers`

- canonical identity
- unique per `company_id + msisdn`

2. `customer_branch_profiles`

- one row per `customer_id + branch_id`
- stores branch-local behavior and intelligence

3. optional branch-aware extensions

- `customer_notes` with optional `branch_id`
- `customer_tags` with optional `branch_id`
- branch-specific preference or sentiment tables if needed later

## Loyalty Direction

Current recommendation:

- keep customer identity canonical
- keep loyalty company-wide by default unless a business explicitly needs branch-local loyalty
- keep transactions and redemptions branch-linked

If branch-local loyalty is ever needed, it should be modeled on top of the same canonical customer identity rather than by duplicating customer rows.

## Working Principle

When we return to CRM expansion work:

- do not make customers branch-scoped by default
- add branch-scoped intelligence instead of duplicate identities
- treat canonical identity as the source of truth

This note exists so we can come back and implement the CRM layer intentionally rather than rediscovering the model from scratch.
