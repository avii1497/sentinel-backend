ALTER TABLE property_offers
  ADD COLUMN offer_expires_at DATETIME NULL;

ALTER TABLE property_reservations
  ADD COLUMN owner_ack_at DATETIME NULL;

ALTER TABLE property_reservations
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancelled_by ENUM('customer','agent','system') NULL,
  ADD COLUMN cancel_reason VARCHAR(100) NULL,
  ADD COLUMN refund_status ENUM('none','pending','approved','rejected','processed') NOT NULL DEFAULT 'none',
  ADD COLUMN refund_amount DECIMAL(10,2) NULL,
  ADD COLUMN refund_currency VARCHAR(10) NULL,
  ADD COLUMN refund_reference VARCHAR(255) NULL,
  ADD COLUMN refunded_at DATETIME NULL;

ALTER TABLE property_contracts
  MODIFY COLUMN status ENUM('Pending','Active','Completed','Expired','Cancelled') NOT NULL DEFAULT 'Pending';
