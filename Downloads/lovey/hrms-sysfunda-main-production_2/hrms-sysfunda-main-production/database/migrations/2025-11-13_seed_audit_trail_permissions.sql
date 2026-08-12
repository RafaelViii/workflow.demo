-- Migration: Seed Audit Trail Permissions
-- Date: 2025-11-13
-- Description: Ensures audit trail permissions are properly configured for key positions
-- Idempotent: Yes

DO $$
DECLARE
    pos_id INTEGER;
    perm_exists BOOLEAN;
BEGIN
    RAISE NOTICE 'Starting audit trail permissions seeding...';

    -- ============================================================================
    -- System Administrator Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%system admin%' 
       OR LOWER(name) LIKE '%sysadmin%'
       OR name = 'System Administrator'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        -- Check if permission already exists
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'manage', 'System Administrator - Full audit trail access')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for System Administrator (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'System Administrator already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'System Administrator position not found - skipping';
    END IF;

    -- ============================================================================
    -- HR Manager Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%hr manager%'
       OR LOWER(name) LIKE '%human resource%manager%'
       OR name = 'HR Manager'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'manage', 'HR Manager - Full audit trail access for HR oversight')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for HR Manager (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'HR Manager already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'HR Manager position not found - skipping';
    END IF;

    -- ============================================================================
    -- Internal Auditor Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%auditor%'
       OR LOWER(name) LIKE '%compliance%'
       OR name = 'Internal Auditor'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'manage', 'Internal Auditor - Full audit trail access for compliance')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Internal Auditor (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Internal Auditor already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Internal Auditor position not found - skipping';
    END IF;

    -- ============================================================================
    -- IT Administrator Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%it admin%'
       OR LOWER(name) LIKE '%information tech%admin%'
       OR name = 'IT Administrator'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'read', 'IT Administrator - Read audit trail for system monitoring')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for IT Administrator (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'IT Administrator already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'IT Administrator position not found - skipping';
    END IF;

    -- ============================================================================
    -- Compliance Officer Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%compliance officer%'
       OR LOWER(name) LIKE '%compliance manager%'
       OR name = 'Compliance Officer'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'manage', 'Compliance Officer - Full audit trail access for regulatory compliance')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Compliance Officer (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Compliance Officer already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Compliance Officer position not found - skipping';
    END IF;

    -- ============================================================================
    -- Security Officer Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(name) LIKE '%security officer%'
       OR LOWER(name) LIKE '%security manager%'
       OR LOWER(name) LIKE '%infosec%'
       OR name = 'Security Officer'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_access_permissions 
            WHERE position_id = pos_id 
            AND domain = 'system' 
            AND resource_key = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, notes)
            VALUES 
                (pos_id, 'system', 'audit_logs', 'manage', 'Security Officer - Full audit trail access for security monitoring')
            ON CONFLICT (position_id, domain, resource_key) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Security Officer (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Security Officer already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Security Officer position not found - skipping';
    END IF;

    RAISE NOTICE 'Audit trail permissions seeding completed successfully!';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Summary:';
    RAISE NOTICE '- Positions with MANAGE access: System Admin, HR Manager, Internal Auditor, Compliance Officer, Security Officer';
    RAISE NOTICE '- Positions with READ access: IT Administrator';
    RAISE NOTICE '- Only existing positions were updated';
    RAISE NOTICE '========================================';

EXCEPTION
    WHEN OTHERS THEN
        RAISE EXCEPTION 'Error seeding audit trail permissions: %', SQLERRM;
END;
$$;
