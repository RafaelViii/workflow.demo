-- Fix fn_notify_payroll_released(): it referenced columns/table that don't
-- match the actual schema (payroll_periods.start_date/end_date should be
-- period_start/period_end; payroll_data should be payslips.payroll_run_id),
-- so releasing a payroll run threw a DB error instead of notifying employees.
-- Discovered while seeding demo payroll data — this blocked the real
-- "release payroll run" workflow, not just seed scripts.

BEGIN;

CREATE OR REPLACE FUNCTION fn_notify_payroll_released()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_title TEXT;
    v_body TEXT;
    v_period_label TEXT;
    v_payload JSONB;
    v_employee RECORD;
BEGIN
    -- Only trigger when released_at changes from NULL to a value
    IF NEW.released_at IS NOT NULL AND (OLD.released_at IS NULL OR OLD.released_at != NEW.released_at) THEN

        -- Get period label
        SELECT
            TO_CHAR(period_start, 'Mon DD') || ' - ' || TO_CHAR(period_end, 'Mon DD, YYYY')
        INTO v_period_label
        FROM payroll_periods
        WHERE period_start = NEW.period_start AND period_end = NEW.period_end;

        IF v_period_label IS NULL THEN
            v_period_label := TO_CHAR(NEW.period_start, 'Mon DD') || ' - ' || TO_CHAR(NEW.period_end, 'Mon DD, YYYY');
        END IF;

        -- Build notification title and body
        v_title := 'Payroll Released';
        v_body := 'Your payroll for ' || COALESCE(v_period_label, 'this period') || ' has been released and is now available to view.';

        -- Build payload
        v_payload := jsonb_build_object(
            'type', 'payroll_release',
            'run_id', NEW.id,
            'period_label', v_period_label,
            'view_path', '/modules/payroll/run_view?id=' || NEW.id
        );

        -- Send notification to all employees who have a payslip in this run
        FOR v_employee IN
            SELECT DISTINCT u.id AS user_id
            FROM payslips ps
            JOIN employees e ON ps.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE ps.payroll_run_id = NEW.id
              AND u.id IS NOT NULL
        LOOP
            INSERT INTO notifications (user_id, title, body, message, payload)
            VALUES (
                v_employee.user_id,
                v_title,
                v_body,
                v_body,
                v_payload
            );
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$;

COMMIT;
