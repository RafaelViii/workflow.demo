-- Lets a user customize which Quick Actions shortcuts appear on their
-- dashboard (add/remove from a catalog), independent of the saved card
-- order added in 2026-08-12_dashboard_card_layout.sql.
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'user_dashboard_layout'
      AND column_name = 'layout' AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE public.user_dashboard_layout ALTER COLUMN layout DROP NOT NULL;
  END IF;
END $$;

ALTER TABLE user_dashboard_layout ADD COLUMN IF NOT EXISTS quick_actions JSONB NULL;

COMMENT ON COLUMN user_dashboard_layout.quick_actions IS 'JSON array of catalog keys for the user''s customized Quick Actions shortcuts; NULL means use the permission-based default set.';
