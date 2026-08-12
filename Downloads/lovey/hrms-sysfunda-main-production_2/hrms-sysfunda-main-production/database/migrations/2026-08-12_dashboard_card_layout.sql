-- Per-user saved order for the reorderable dashboard cards (Inventory Status,
-- Charts & Trends, Quick Actions, Recent Notifications).
CREATE TABLE IF NOT EXISTS user_dashboard_layout (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  layout JSONB NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE user_dashboard_layout IS 'Per-user custom drag-and-drop order for dashboard collapsible cards.';
COMMENT ON COLUMN user_dashboard_layout.layout IS 'JSON array of section element IDs in display order, e.g. ["secActionCenter","secInventoryStatus","secChartsTrends","secNotifications"]';
