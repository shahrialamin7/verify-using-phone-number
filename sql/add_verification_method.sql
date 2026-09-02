-- Phone Verification Feature
-- Add verification_method column to brands table

ALTER TABLE `pp_brands` 
ADD COLUMN `verification_method` VARCHAR(20) NOT NULL DEFAULT 'transaction_id' 
AFTER `updated_date`;

-- Default: 'transaction_id' (legacy)
-- Options: 'transaction_id', 'phone_number'
