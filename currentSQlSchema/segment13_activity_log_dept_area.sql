-- ============================================================
-- Segment 13 — Activity Log Templates: Departments & Areas
-- Purpose: Add templates for department/area CRUD events
-- Run AFTER Segment 12
-- ============================================================

INSERT INTO `activity_log_templates`
    (`id`, `action_key`, `template`, `module_slug`, `submodule_slug`, `feature_slug`, `default_action`, `is_active`)
VALUES
-- Departments
(97,  'department.created',        'Created department: {department_name}',                                  'business_management', NULL, NULL, 'CREATE', 1),
(98,  'department.updated',        'Updated department: {department_name}',                                  'business_management', NULL, NULL, 'UPDATE', 1),
(99,  'department.status_changed', 'Changed department "{department_name}" status to {new_status}',          'business_management', NULL, NULL, 'UPDATE', 1),
(100, 'department.deleted',        'Deleted department: {department_name}',                                  'business_management', NULL, NULL, 'DELETE', 1),

-- Areas
(101, 'area.created',              'Created area: {area_name}',                                              'business_management', NULL, NULL, 'CREATE', 1),
(102, 'area.updated',              'Updated area: {area_name}',                                              'business_management', NULL, NULL, 'UPDATE', 1),
(103, 'area.status_changed',       'Changed area "{area_name}" status to {new_status}',                      'business_management', NULL, NULL, 'UPDATE', 1),
(104, 'area.deleted',              'Deleted area: {area_name}',                                              'business_management', NULL, NULL, 'DELETE', 1);
