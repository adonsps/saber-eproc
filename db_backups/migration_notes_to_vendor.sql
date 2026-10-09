-- Migration: VM "Return to Vendor" flow
-- Adds notes_to_vendor column for VM->Vendor messages, and widens the
-- pq_sla_logs.vm_action_type enum so closePqSlaLeg() can log the new action.

ALTER TABLE pq_submissions
  ADD COLUMN notes_to_vendor TEXT NULL AFTER vm_notes;

ALTER TABLE pq_sla_logs
  MODIFY vm_action_type
  ENUM('forward_head','returned_to_sourcing','returned_to_vendor')
  COLLATE utf8mb4_unicode_ci DEFAULT NULL;
