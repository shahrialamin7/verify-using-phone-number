-- Phone Verification Feature
-- Add verification_method column to brands table

-- Safe: Only add if column doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'pp_brands';
SET @columnname = 'verification_method';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` VARCHAR(20) NOT NULL DEFAULT ''transaction_id'' AFTER `updated_date`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Default: 'transaction_id' (legacy)
-- Options: 'transaction_id', 'phone_number'

-- Performance: Composite index for phone verification queries
-- Run this if sms_data table has >100k rows

CREATE INDEX IF NOT EXISTS idx_sms_data_verify 
ON `pp_sms_data` (`sender_key`, `amount`, `status`, `created_date`, `id`);
