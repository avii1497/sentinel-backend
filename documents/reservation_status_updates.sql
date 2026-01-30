-- Reservation lifecycle states for property_reservations
ALTER TABLE property_reservations
  ADD COLUMN reservation_status ENUM(
    'PENDING_APPROVAL',
    'ACCEPTED_AWAITING_PAYMENT',
    'PAID_CONFIRMED',
    'CANCELLED',
    'EXPIRED'
  ) NOT NULL DEFAULT 'PENDING_APPROVAL';

-- Backfill existing data
UPDATE property_reservations
SET reservation_status = CASE
  WHEN cancelled_at IS NOT NULL THEN 'CANCELLED'
  WHEN payment_status = 'paid' THEN 'PAID_CONFIRMED'
  WHEN payment_status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW() THEN 'EXPIRED'
  ELSE 'ACCEPTED_AWAITING_PAYMENT'
END;
