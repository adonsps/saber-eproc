-- Migration: Add 'registration_submitted' registration status
-- Introduces a distinct status for vendors that have JUST registered but have
-- not yet completed their profile. New sign-ups now start as
-- 'registration_submitted' and are auto-promoted to 'invited_pq' once the profile
-- passes isVendorProfileComplete() (see modules/vendor/profile_action.php).
--
-- registration_status is an ENUM, so the new value MUST be added here before the
-- application can store it. Run once against DB u894129685_vmsportal.

ALTER TABLE vendors
  MODIFY COLUMN `registration_status`
  ENUM(
    'registration_submitted',
    'registered',
    'min_info_submitted',
    'invited_pq',
    'draft',
    'pq_submitted',
    'revision_requested',
    'under_review_sourcing',
    'under_review_vm',
    'under_review_vm_head',
    'approved',
    'rejected',
    'returned_to_sourcing',
    'migrated'
  ) COLLATE utf8mb4_unicode_ci DEFAULT 'registered';
