<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('delivery.manage');
$agents        = (new DeliveryAgent(Database::instance()))->getAllAgentsForAdmin();

$page_title  = 'Delivery agents';
$active_menu = 'delivery_agents';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-page-actions">
    <p class="text-muted mb-0">The people who deliver orders. Customers see the agent's name, phone and photo while their order is on the way.</p>
    <a class="btn btn-chimbo" href="<?= e(url('admin/delivery_agent_edit.php')) ?>"><i class="bi bi-plus-lg"></i> Add delivery agent</a>
</div>

<div class="admin-panel admin-table-panel">
    <?php if (!$agents): ?>
        <div class="admin-empty-state">
            <i class="bi bi-truck"></i>
            <p class="mt-2 mb-0">No delivery agents yet. Add one before dispatching orders.</p>
        </div>
    <?php else: ?>
        <table class="table admin-table w-100" data-datatable data-title="All delivery agents" data-icon="bi-truck"
               data-order='[[0,"asc"]]' data-search-placeholder="Search name or phone">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Phone</th>
                    <th class="text-end">Orders on the way</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $agent): ?>
                    <tr class="row-link">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?= adminThumbnail($agent['delivery_agent_photo_path'], 'bi-person') ?>
                                <a class="stretched-link fw-semibold" href="<?= e(url('admin/delivery_agent_edit.php?id=' . $agent['delivery_agent_id'])) ?>">
                                    <?= e($agent['delivery_agent_full_name']) ?>
                                </a>
                            </div>
                        </td>
                        <td class="text-nowrap"><?= e(Phone::format($agent['delivery_agent_phone'])) ?></td>
                        <td class="text-end"><?= e(number_format((int) $agent['active_order_count'])) ?></td>
                        <td><?= adminStatusBadge($agent['delivery_agent_is_active'] ? 'active' : 'inactive') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
