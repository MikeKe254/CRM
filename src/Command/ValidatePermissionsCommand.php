<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:validate:permissions',
    description: 'Validate integrity of roles, permissions, and user assignments across all companies',
)]
class ValidatePermissionsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Permission & Role Integrity Validator');

        $errors = [];
        $warnings = [];

        // 1. Validate role hierarchy relationships
        $io->section('1. Role Hierarchy Validation');
        $hierarchyErrors = $this->validateRoleHierarchy();
        if (empty($hierarchyErrors)) {
            $io->success('All role hierarchy relationships are valid');
        } else {
            $io->warning('Found role hierarchy issues:');
            foreach ($hierarchyErrors as $error) {
                $io->writeln("  • $error");
                $errors[] = $error;
            }
        }

        // 2. Validate user role assignments
        $io->section('2. User Role Assignment Validation');
        $assignmentErrors = $this->validateUserRoleAssignments();
        if (empty($assignmentErrors)) {
            $io->success('All user role assignments are valid');
        } else {
            $io->warning('Found assignment issues:');
            foreach ($assignmentErrors as $error) {
                $io->writeln("  • $error");
                $errors[] = $error;
            }
        }

        // 3. Validate user type consistency
        $io->section('3. User Type Consistency Validation');
        $typeErrors = $this->validateUserTypeConsistency();
        if (empty($typeErrors)) {
            $io->success('All user types are consistent with their assignments');
        } else {
            $io->warning('Found user type inconsistencies:');
            foreach ($typeErrors as $error) {
                $io->writeln("  • $error");
                $warnings[] = $error;
            }
        }

        // 4. Validate permission assignments
        $io->section('4. Permission Assignment Validation');
        $permissionErrors = $this->validatePermissionAssignments();
        if (empty($permissionErrors)) {
            $io->success('All permission assignments are valid');
        } else {
            $io->warning('Found permission issues:');
            foreach ($permissionErrors as $error) {
                $io->writeln("  • $error");
                $errors[] = $error;
            }
        }

        // 5. Validate hierarchy level consistency
        $io->section('5. Role Hierarchy Level Validation');
        $levelErrors = $this->validateHierarchyLevels();
        if (empty($levelErrors)) {
            $io->success('All role hierarchy levels are consistent');
        } else {
            $io->warning('Found hierarchy level issues:');
            foreach ($levelErrors as $error) {
                $io->writeln("  • $error");
                $warnings[] = $error;
            }
        }

        // Summary
        $io->section('Summary');
        if (empty($errors) && empty($warnings)) {
            $io->success('All validations passed! ✓');
            return Command::SUCCESS;
        }

        if (!empty($errors)) {
            $io->error("Found $" . count($errors) . " critical errors");
        }
        if (!empty($warnings)) {
            $io->caution('Found ' . count($warnings) . ' warnings (non-critical)');
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Validate role hierarchy relationships
     * @return array List of error messages
     */
    private function validateRoleHierarchy(): array
    {
        $errors = [];

        // Check for orphaned hierarchy entries
        $orphaned = $this->db->fetchAllAssociative(
            "SELECT rh.role_id, rh.parent_role_id
             FROM role_hierarchy rh
             WHERE rh.parent_role_id IS NOT NULL
             AND NOT EXISTS (
                 SELECT 1 FROM roles r WHERE r.id = rh.parent_role_id
             )"
        );

        foreach ($orphaned as $row) {
            $errors[] = "Role #{$row['role_id']} references non-existent parent role #{$row['parent_role_id']}";
        }

        // Check for circular dependencies
        $circularErrors = $this->detectCircularHierarchy();
        $errors = array_merge($errors, $circularErrors);

        return $errors;
    }

    /**
     * Detect circular dependencies in role hierarchy
     * @return array List of error messages
     */
    private function detectCircularHierarchy(): array
    {
        $errors = [];

        // Get all hierarchy entries
        $rows = $this->db->fetchAllAssociative(
            'SELECT role_id, parent_role_id FROM role_hierarchy WHERE parent_role_id IS NOT NULL'
        );

        $hierarchy = [];
        foreach ($rows as $row) {
            $hierarchy[(int)$row['role_id']] = (int)$row['parent_role_id'];
        }

        // Check each role for circular path
        foreach ($hierarchy as $roleId => $parentId) {
            $visited = [];
            $current = $parentId;

            while ($current !== null) {
                if (in_array($current, $visited, true)) {
                    $errors[] = "Circular dependency detected: Role #$roleId → ... → Role #$current → ...";
                    break;
                }
                if ($current === $roleId) {
                    $errors[] = "Circular dependency: Role #$roleId references itself indirectly";
                    break;
                }
                $visited[] = $current;
                $current = $hierarchy[$current] ?? null;
            }
        }

        return $errors;
    }

    /**
     * Validate user role assignments
     * @return array List of error messages
     */
    private function validateUserRoleAssignments(): array
    {
        $errors = [];

        // Check for assignments with non-existent users
        $invalidUsers = $this->db->fetchAllAssociative(
            "SELECT unr.user_id, unr.node_id, unr.role_id
             FROM user_node_roles unr
             WHERE NOT EXISTS (
                 SELECT 1 FROM users u WHERE u.id = unr.user_id AND u.deleted_at IS NULL
             )"
        );

        foreach ($invalidUsers as $row) {
            $errors[] = "Assignment has non-existent or deleted user #${row['user_id']}";
        }

        // Check for assignments with non-existent roles
        $invalidRoles = $this->db->fetchAllAssociative(
            "SELECT unr.user_id, unr.node_id, unr.role_id
             FROM user_node_roles unr
             WHERE NOT EXISTS (
                 SELECT 1 FROM roles r WHERE r.id = unr.role_id AND r.deleted_at IS NULL
             )"
        );

        foreach ($invalidRoles as $row) {
            $errors[] = "Assignment has non-existent or deleted role #${row['role_id']} for user #${row['user_id']}";
        }

        // Check for assignments with non-existent branches
        $invalidNodes = $this->db->fetchAllAssociative(
            "SELECT unr.user_id, unr.node_id, unr.role_id
             FROM user_node_roles unr
             WHERE NOT EXISTS (
                 SELECT 1 FROM branches b WHERE b.id = unr.node_id AND b.deleted_at IS NULL
             )"
        );

        foreach ($invalidNodes as $row) {
            $errors[] = "Assignment has non-existent or deleted branch #${row['node_id']} for user #${row['user_id']}";
        }

        return $errors;
    }

    /**
     * Validate user type consistency
     * @return array List of warning messages (non-critical)
     */
    private function validateUserTypeConsistency(): array
    {
        $warnings = [];

        // Check for office-type users assigned to branch roles
        $officeInBranch = $this->db->fetchAllAssociative(
            "SELECT DISTINCT u.id, u.name
             FROM users u
             WHERE u.user_type IN ('office')
             AND u.deleted_at IS NULL
             AND EXISTS (
                 SELECT 1 FROM user_node_roles unr
                 WHERE unr.user_id = u.id
                 AND unr.role_id IN (
                     SELECT r.id FROM roles r WHERE r.scope = 'branch'
                 )
             )"
        );

        foreach ($officeInBranch as $row) {
            $warnings[] = "Office-type user '{$row['name']}' (#{$row['id']}) is assigned to branch-scoped role(s)";
        }

        // Check for branch-type users assigned to office roles
        $branchInOffice = $this->db->fetchAllAssociative(
            "SELECT DISTINCT u.id, u.name
             FROM users u
             WHERE u.user_type IN ('branch')
             AND u.deleted_at IS NULL
             AND EXISTS (
                 SELECT 1 FROM user_node_roles unr
                 WHERE unr.user_id = u.id
                 AND unr.role_id IN (
                     SELECT r.id FROM roles r WHERE r.scope IN ('hq', 'any') AND r.is_head_role = 1
                 )
             )"
        );

        foreach ($branchInOffice as $row) {
            $warnings[] = "Branch-type user '{$row['name']}' (#{$row['id']}) is assigned to office-level role(s)";
        }

        return $warnings;
    }

    /**
     * Validate permission assignments
     * @return array List of error messages
     */
    private function validatePermissionAssignments(): array
    {
        $errors = [];

        // Check for assignments with non-existent permissions
        $invalidPerms = $this->db->fetchAllAssociative(
            "SELECT DISTINCT rp.role_id, rp.permission_id
             FROM role_permissions rp
             WHERE NOT EXISTS (
                 SELECT 1 FROM permissions p WHERE p.id = rp.permission_id AND p.deleted_at IS NULL
             )"
        );

        foreach ($invalidPerms as $row) {
            $errors[] = "Role #{$row['role_id']} assigned non-existent permission #{$row['permission_id']}";
        }

        return $errors;
    }

    /**
     * Validate role hierarchy level consistency
     * @return array List of warning messages
     */
    private function validateHierarchyLevels(): array
    {
        $warnings = [];

        // Check parent level is greater than child level
        $levelIssues = $this->db->fetchAllAssociative(
            "SELECT
               child.role_id AS child_id, child.level AS child_level,
               parent_rh.role_id AS parent_id, parent_rh.level AS parent_level
             FROM role_hierarchy child
             JOIN role_hierarchy parent_rh ON parent_rh.role_id = child.parent_role_id
             WHERE child.parent_role_id IS NOT NULL
             AND parent_rh.level IS NOT NULL
             AND child.level IS NOT NULL
             AND parent_rh.level <= child.level"
        );

        foreach ($levelIssues as $row) {
            $warnings[] = "Role #{$row['child_id']} (level {$row['child_level']}) has parent role #{$row['parent_id']} with equal/lower level ({$row['parent_level']})";
        }

        return $warnings;
    }
}
