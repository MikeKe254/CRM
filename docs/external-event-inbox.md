# Patronr External Event Inbox

This document defines the shared inbound event inbox used to move external webhooks and callbacks from `patronrapis` into Patronr async processing.

## Purpose

The inbox is:

- a durable ingress record
- the dedupe boundary
- the retry boundary for relay dispatch
- the bridge from `patronrapis` into Patronr Messenger workers

The inbox is not:

- a replacement for `mpesa_payments`
- a replacement for SMS or email delivery logs
- the payment business table

## Core tables

- `external_event_inbox`
- `mpesa_payments` remains the payment domain table

## First implemented event types

- `mpesa.stk_callback`
- `mpesa.c2b_confirmation`

Future event types can include:

- `sms.delivery_report`
- `email.delivery_event`
- other provider callbacks

## Relay flow

1. `patronrapis` writes a row into `external_event_inbox`
2. Patronr `app:async:inbox-relay` claims pending rows
3. relay maps each row to a typed Messenger message
4. Messenger routes payment events to the `payments` transport
5. payment worker processes the event
6. handler updates domain tables such as `mpesa_payments`, `stk_push_logs`, and `pos_transactions`

## Current implementation notes

- STK callback handling updates `stk_push_logs`
- STK callback handling updates linked `pos_transactions`
- STK callback handling creates or updates `mpesa_payments`
- C2B confirmation handling forwards payloads to configured `forward_urls`
- C2B confirmation handling creates or updates `mpesa_payments`

## Important rule

`patronrapis` stays minimal:

- receive
- validate
- record inbox row
- return response

Patronr owns:

- relay
- Messenger dispatch
- payment processing
- future loyalty and messaging logic
