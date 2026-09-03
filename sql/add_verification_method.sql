-- Phone Verification Feature
-- Add verification_method column to brands table

ALTER TABLE `pp_brands` 
ADD COLUMN `verification_method` VARCHAR(20) NOT NULL DEFAULT 'transaction_id' 
AFTER `updated_date`;

-- Default: 'transaction_id' (legacy)
-- Options: 'transaction_id', 'phone_number'

-- Performance: Composite index for phone verification queries
-- Run this if sms_data table has >100k rows

CREATE INDEX IF NOT EXISTS idx_sms_data_verify 
ON `pp_sms_data` (`sender_key`, `amount`, `status`, `created_date`, `id`);
