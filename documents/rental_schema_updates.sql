-- Rental booking lifecycle + cancellation/refund metadata
ALTER TABLE rental_bookings_backup
  MODIFY status ENUM('pending','accepted','rejected','cancelled','ongoing','completed') DEFAULT 'pending';

ALTER TABLE rental_bookings_backup
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancelled_by ENUM('customer','agent') NULL,
  ADD COLUMN cancel_reason TEXT NULL,
  ADD COLUMN refund_status ENUM('none','pending','approved','rejected','refunded','failed') NOT NULL DEFAULT 'none',
  ADD COLUMN refund_amount DECIMAL(10,2) NULL,
  ADD COLUMN refund_currency VARCHAR(3) NULL,
  ADD COLUMN refund_reference VARCHAR(255) NULL,
  ADD COLUMN refunded_at DATETIME NULL;

-- Rental lifecycle on properties
ALTER TABLE properties
  ADD COLUMN rental_status ENUM('Draft','Published','Unavailable','Archived') NOT NULL DEFAULT 'Draft';

UPDATE properties p
INNER JOIN rental_properties rp ON rp.property_id = p.id AND rp.is_active = 1
SET p.rental_status = 'Published';
