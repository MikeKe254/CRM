-- segment31: Link VIEW_TRANSACTIONS permission to its terminal constraints.
--
-- The permission_constraints table defines which constraints are *available*
-- on a permission. Actual per-role values live in role_permission_constraints.
--
-- VIEW_TRANSACTIONS (id=3) carries two constraints that control how much
-- history a user can see on the M-Pesa terminal feed:
--   max_hours_history       (id=1) — how far back in time
--   max_transactions_visible (id=2) — how many rows at most
--
-- If a role has VIEW_TRANSACTIONS with no constraint values set, the system
-- falls back to pos_terminal_settings (company-level defaults).
-- If constraint values ARE set, they are capped by the company defaults.

INSERT IGNORE INTO permission_constraints
    (permission_id, constraint_id, is_required, default_value)
VALUES
    (3, 1, 0, '24'),   -- VIEW_TRANSACTIONS → max_hours_history  (default 24 h)
    (3, 2, 0, '50');   -- VIEW_TRANSACTIONS → max_transactions_visible (default 50)
