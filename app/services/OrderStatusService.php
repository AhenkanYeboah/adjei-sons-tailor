<?php
/**
 * OrderStatusService
 *
 * Every order status change goes through here: it updates orders.status,
 * writes an order_status_log row (audit trail / tracking dashboard feed),
 * and queues a WhatsApp notification. One source of truth for all three.
 */
class OrderStatusService
{
    private PDO $db;

    private const STATUS_MESSAGES = [
        'received'          => 'We\'ve received your order! Our team will begin measuring shortly.',
        'measuring'         => 'Your measurements are being finalized.',
        'cutting'           => 'Fabric cutting has begun on your garment.',
        'sewing'            => 'Your garment is now being sewn by our tailors.',
        'fitting'           => 'Your garment is ready for fitting review.',
        'ready_for_pickup'  => 'Great news — your garment is ready for pickup!',
        'completed'         => 'Order complete. Thank you for choosing us!',
        'cancelled'         => 'Your order has been cancelled. Contact us with any questions.',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function updateStatus(int $orderId, string $newStatus, ?int $staffId = null): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
            $stmt->execute(['status' => $newStatus, 'id' => $orderId]);

            $stmt = $this->db->prepare(
                'INSERT INTO order_status_log (order_id, status, changed_by_staff_id, whatsapp_sent)
                 VALUES (:order_id, :status, :staff_id, 0)'
            );
            $stmt->execute([
                'order_id'  => $orderId,
                'status'    => $newStatus,
                'staff_id'  => $staffId,
            ]);

            $this->queueNotification($orderId, $newStatus);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('Order status update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function queueNotification(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('SELECT client_id FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $clientId = $stmt->fetchColumn();

        if (!$clientId) {
            return;
        }

        $message = self::STATUS_MESSAGES[$status] ?? ('Order status updated: ' . $status);

        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_notifications (client_id, related_order_id, message_type, message_body, status)
             VALUES (:client_id, :order_id, :type, :body, :status)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'order_id'  => $orderId,
            'type'      => 'status_update',
            'body'      => $message,
            'status'    => 'queued',
        ]);
    }
}
