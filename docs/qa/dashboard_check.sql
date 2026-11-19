-- Script: docs/qa/dashboard_check.sql
-- Purpose: Verify dashboard summary card counts against actual database rows (CMP-19 / FR-008).
-- Usage: Run against the active PostgreSQL database. Replace :organization_id with the target org id (e.g., 1).

-- 1. Total applicable compliance requirements
SELECT COUNT(*) AS applicable_requirements
FROM compliance_requirements
WHERE organization_id = 1
  AND applies = true;

-- 2. Applicable compliance requirements grouped by status
SELECT 
    status,
    COUNT(*) AS count
FROM compliance_requirements
WHERE organization_id = 1
  AND applies = true
GROUP BY status
ORDER BY 
    CASE status
        WHEN 'compliant' THEN 1
        WHEN 'pending' THEN 2
        WHEN 'at_risk' THEN 3
        WHEN 'overdue' THEN 4
        ELSE 5
    END;

-- 3. Detailed breakdown matching all dashboard summary cards
SELECT
    COUNT(*) AS applicable_requirements,
    COUNT(CASE WHEN status = 'compliant' THEN 1 END) AS compliant,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
    COUNT(CASE WHEN status = 'at_risk' THEN 1 END) AS at_risk,
    COUNT(CASE WHEN status = 'overdue' THEN 1 END) AS overdue
FROM compliance_requirements
WHERE organization_id = 1
  AND applies = true;

-- 4. Count of applicable requirements with missing or unsatisfied required documents (ADR-022)
SELECT COUNT(DISTINCT cr.id) AS requirements_with_missing_documents
FROM compliance_requirements cr
WHERE cr.organization_id = 1
  AND cr.applies = true
  AND (
    -- Requirement has required documents but at least one is unsatisfied
    EXISTS (
        SELECT 1 
        FROM required_documents rd
        LEFT JOIN documents d ON rd.document_id = d.id AND d.is_archived = false
        LEFT JOIN document_versions dv ON d.id = dv.document_id AND dv.is_current = true
        WHERE rd.compliance_requirement_id = cr.id
          AND (
            rd.document_id IS NULL
            OR d.category != rd.category
            OR dv.id IS NULL
            OR LOWER(dv.status) != 'processed'
            OR (dv.expiration_date IS NOT NULL AND dv.expiration_date <= CURRENT_DATE)
          )
    )
  );
