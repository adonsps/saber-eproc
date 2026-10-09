-- Migration: Exception Request OTP flow
--
-- Replaces the old "confirm with your password" step on Exception Request submit
-- with a 6-digit OTP that is delivered by WhatsApp to the Sourcing user's direct
-- supervisor (supervisor + phone come from the WORKSPACE database).
--
-- Adds:
--   1. A 'requested' state to exception_requests.status — the record created the
--      moment Sourcing presses "Kirim OTP" (before the form is submitted). It
--      becomes 'under_review_vm_head' once the correct OTP is entered and the
--      form is submitted.
--   2. OTP tracking columns (otp_hash stores a password_hash of the code, never
--      the plaintext).
--
-- Run ONCE against DB u894129685_vmsportal. Safe to re-run: the ALTERs simply
-- error with "duplicate column" / no-op if already applied.

ALTER TABLE `exception_requests`
    MODIFY `status` ENUM('requested','under_review_vm_head','approved','rejected')
        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'under_review_vm_head';

ALTER TABLE `exception_requests`
    ADD COLUMN `otp_hash` VARCHAR(255) NULL AFTER `submitted_notes`,
    ADD COLUMN `otp_requested_at` DATETIME NULL AFTER `otp_hash`,
    ADD COLUMN `otp_sent_to` VARCHAR(30) NULL AFTER `otp_requested_at`,
    ADD COLUMN `otp_attempts` INT NOT NULL DEFAULT 0 AFTER `otp_sent_to`;
