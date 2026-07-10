<?php
/**
 * WaitTimeCalculator
 *
 * Single source of truth for estimated wait time, used identically by:
 *   - the homepage widget
 *   - the checkout page
 * so the two never disagree.
 *
 * Rule (tunable via the production_settings table, one row):
 *   promised_days = min_wait_days + floor(open_orders / orders_per_increment) * days_added_per_n_orders
 *   capped at max_wait_days
 *
 * Express/rush tiers bypass the queue-based formula and use fixed windows,
 * gated by express_slots availability for the current week.
 */
class WaitTimeCalculator
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function getSettings(): array
    {
        $stmt = $this->db->query('SELECT * FROM production_settings LIMIT 1');
        $settings = $stmt->fetch();

        if (!$settings) {
            // Sane fallback if the config row is ever missing.
            return [
                'days_added_per_n_orders' => 2,
                'orders_per_increment'    => 5,
                'min_wait_days'           => 7,
                'max_wait_days'           => 45,
            ];
        }

        return $settings;
    }

    private function countOpenOrders(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM orders
             WHERE status NOT IN ('completed', 'cancelled')"
        );
        return (int) $stmt->fetchColumn();
    }

    /**
     * Standard-tier wait, in days, based on current open order volume.
     */
    public function getStandardWaitDays(): int
    {
        $settings = $this->getSettings();
        $openOrders = $this->countOpenOrders();

        $increments = intdiv($openOrders, (int) $settings['orders_per_increment']);
        $days = (int) $settings['min_wait_days'] + ($increments * (int) $settings['days_added_per_n_orders']);

        return min($days, (int) $settings['max_wait_days']);
    }

    /**
     * Wait days for a given order tier. Express/rush are fixed windows,
     * but still subject to slot availability (checked separately via
     * ExpressSlotManager before allowing checkout to proceed).
     */
    public function getWaitDaysForTier(string $tier): int
    {
        return match ($tier) {
            'express_5day' => 5,
            'rush_48hr'    => 2,
            default        => $this->getStandardWaitDays(),
        };
    }

    /**
     * Returns a promised pickup date (Y-m-d) for a given tier, anchored to "today".
     */
    public function getPromisedDate(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);
        $date = new DateTime('today');
        $date->modify("+{$days} days");
        return $date->format('Y-m-d');
    }

    /**
     * Human-readable summary for display, e.g. "12–14 days" for standard,
     * or a fixed line for express/rush.
     */
    public function getDisplayEstimate(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);

        return match ($tier) {
            'express_5day' => '5 business days (Express)',
            'rush_48hr'    => '48 hours (Rush)',
            default        => $days . ' days (Standard)',
        };
    }

    /**
     * Percentage fill (0-100) of standard wait days against max_wait_days,
     * for the tape-meter visualization on the homepage.
     */
    public function getQueueMeterPercent(): float
    {
        $settings = $this->getSettings();
        $max = max(1, (int) $settings['max_wait_days']);
        $days = $this->getStandardWaitDays();
        return round(min(100, ($days / $max) * 100), 1);
    }

    /**
     * Formatted promised pickup date, e.g. "Oct 28".
     */
    public function getPromisedDateShort(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);
        $date = new DateTime('today');
        $date->modify("+{$days} days");
        return $date->format('M j');
    }
}
