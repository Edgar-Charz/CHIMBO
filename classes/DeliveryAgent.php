<?php

/**
 * People who deliver orders ("Wakala wa Uwasilishaji") — shown to the customer on "Fuatilia Oda".
 * Staff manage them in the admin; an agent is chosen when an order is dispatched.
 */
class DeliveryAgent
{
    private const RULES = [
        'delivery_agent_full_name' => 'required|string|min:2|max:100',
        'delivery_agent_phone'     => 'required|phone_tz',
        'delivery_agent_is_active' => 'nullable|bool',   // unticked checkbox = inactive
    ];

    public function __construct(private Database $db)
    {
    }

    /** Every agent with how many orders they are carrying right now. */
    public function getAllAgentsForAdmin(): array
    {
        return $this->db->fetchAll(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM order_deliveries d JOIN orders o ON o.order_id = d.order_id
                     WHERE d.delivery_agent_id = a.delivery_agent_id AND o.order_status IN ('dispatched', 'in_transit')) AS active_order_count
             FROM delivery_agents a
             ORDER BY a.delivery_agent_is_active DESC, a.delivery_agent_full_name"
        );
    }

    /** Active agents — for the "who takes this order" dropdown. */
    public function getActiveAgents(): array
    {
        return $this->db->fetchAll(
            'SELECT delivery_agent_id, delivery_agent_full_name, delivery_agent_phone
             FROM delivery_agents WHERE delivery_agent_is_active = 1 ORDER BY delivery_agent_full_name'
        );
    }

    public function getAgentForAdmin(int $delivery_agent_id): array
    {
        $agent = $this->db->fetchOne('SELECT * FROM delivery_agents WHERE delivery_agent_id = :id', ['id' => $delivery_agent_id]);
        if ($agent === null) {
            throw ApiException::notFound('Delivery agent not found.');
        }
        return $agent;
    }

    /** Returns the new delivery_agent_id. */
    public function createAgent(array $input, int $admin_id): int
    {
        $data = Validator::validate($input, self::RULES);

        $delivery_agent_id = $this->db->insert(
            'INSERT INTO delivery_agents (delivery_agent_full_name, delivery_agent_phone, delivery_agent_is_active)
             VALUES (:full_name, :phone, :is_active)',
            ['full_name' => $data['delivery_agent_full_name'], 'phone' => $data['delivery_agent_phone'], 'is_active' => (int) ($data['delivery_agent_is_active'] ?? false)]
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_agent.created', 'delivery_agent', $delivery_agent_id, null, $data);
        return $delivery_agent_id;
    }

    public function updateAgent(int $delivery_agent_id, array $input, int $admin_id): void
    {
        $old_agent = $this->getAgentForAdmin($delivery_agent_id);
        $data = Validator::validate($input, self::RULES);

        $this->db->execute(
            'UPDATE delivery_agents
             SET delivery_agent_full_name = :full_name, delivery_agent_phone = :phone, delivery_agent_is_active = :is_active
             WHERE delivery_agent_id = :id',
            [
                'full_name' => $data['delivery_agent_full_name'],
                'phone'     => $data['delivery_agent_phone'],
                'is_active' => (int) ($data['delivery_agent_is_active'] ?? false),
                'id'        => $delivery_agent_id,
            ]
        );

        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_agent.updated', 'delivery_agent', $delivery_agent_id, $old_agent, $data);
    }

    /** The photo customers see on "Fuatilia Oda" (replaces the old one). */
    public function setAgentPhoto(int $delivery_agent_id, array $uploaded_file, int $admin_id): void
    {
        $old_agent = $this->getAgentForAdmin($delivery_agent_id);
        $uploader  = new ImageUploader();
        $paths     = $uploader->saveUploadedFile($uploaded_file, "delivery_agents/{$delivery_agent_id}");

        // A small photo is enough; the other sizes are removed straight away
        $uploader->deleteImageFiles([$paths['medium'], $paths['large']]);
        $this->db->execute(
            'UPDATE delivery_agents SET delivery_agent_photo_path = :path WHERE delivery_agent_id = :id',
            ['path' => $paths['thumb'], 'id' => $delivery_agent_id]
        );
        if ($old_agent['delivery_agent_photo_path']) {
            $uploader->deleteImageFiles([$old_agent['delivery_agent_photo_path']]);
        }

        (new AuditLog($this->db))->record('admin', $admin_id, 'delivery_agent.photo_changed', 'delivery_agent', $delivery_agent_id);
    }


    /** "Activate / Deactivate": changes only delivery_agent_is_active. Returns false when nothing changed. */
    public function setDeliveryAgentActive(int $delivery_agent_id, bool $is_active, int $admin_id): bool
    {
        return (new RecordSwitch($this->db))->set('delivery_agents', 'delivery_agent_id', $delivery_agent_id, 'delivery_agent_is_active', (int) $is_active, $admin_id, 'delivery_agent');
    }
}
