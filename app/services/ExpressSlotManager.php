<?php
/**
 * ExpressSlotManager
 *
 * Reads/writes express_slots so the tailor can throttle express & rush
 * capacity per week from the admin panel, without touching code.
 */
class ExpressSlotManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function currentWeekStart(): string
    {
        $date = new DateTime('monday this week');
        return $date->format('Y-m-d');
    }

    /**
     * Returns ['slots_total' => int, 'slots_used' => int, 'available' => int]
     * for the given tier this week. If no row exists yet, treats as 0 total.
     */
    public function getAvailability(string $tier): array
    {
        $stmt = $this->db->prepare(
            'SELECT slots_total, slots_used FROM express_slots
             WHERE week_start_date = :week AND tier = :tier'
        );
        $stmt->execute(['week' => $this->currentWeekStart(), 'tier' => $tier]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['slots_total' => 0, 'slots_used' => 0, 'available' => 0];
        }

        $available = max(0, (int) $row['slots_total'] - (int) $row['slots_used']);
        return [
            'slots_total' => (int) $row['slots_total'],
            'slots_used'  => (int) $row['slots_used'],
            'available'   => $available,
        ];
    }

    public function hasAvailability(string $tier): bool
    {
        return $this->getAvailability($tier)['available'] > 0;
    }

    /**
     * Atomically claims one slot for the tier this week.
     * Returns true if a slot was claimed, false if none were available.
     */
    public function claimSlot(string $tier): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE express_slots
             SET slots_used = slots_used + 1
             WHERE week_start_date = :week AND tier = :tier AND slots_used < slots_total'
        );
        $stmt->execute(['week' => $this->currentWeekStart(), 'tier' => $tier]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Admin: set/update total slots for the current week + tier.
     */
    public function setWeeklySlots(string $tier, int $slotsTotal): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO express_slots (week_start_date, tier, slots_total, slots_used)
             VALUES (:week, :tier, :total, 0)
             ON DUPLICATE KEY UPDATE slots_total = :total2'
        );
        $stmt->execute([
            'week'   => $this->currentWeekStart(),
            'tier'   => $tier,
            'total'  => $slotsTotal,
            'total2' => $slotsTotal,
        ]);
    }
}
