<?php
require __DIR__ . '/includes/admin_bootstrap.php';

$current_admin = AdminSession::requireLogin('delivery.manage');
$agent_model   = new DeliveryAgent(Database::instance());

// Row action: activate/deactivate (one-click switch)
$form_error = adminHandleForm(function (string $form_action) use ($agent_model, $current_admin): void {
    if ($form_action !== 'toggle_active') {
        return;
    }
    $agent_id = adminActionRecordId();
    $agent    = $agent_model->getAgentForAdmin($agent_id);
    $is_active = !$agent['delivery_agent_is_active'];
    $agent_model->setDeliveryAgentActive($agent_id, $is_active, (int) $current_admin['admin_id']);
    Session::flash('success', $agent['delivery_agent_full_name'] . ($is_active ? ' can be chosen for dispatch again.' : ' is now inactive.'));
    redirect(url('admin/delivery_agents.php'));
});

$agents = $agent_model->getAllAgentsForAdmin();

$page_title  = 'Delivery agents';
$active_menu = 'delivery_agents';
require __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/form_alert.php'; ?>

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
                    <th class="text-end" data-orderable="false">Actions</th>
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
                        <td class="text-end">
                            <?= adminRowActions(
                                adminActionLink('bi-pencil', 'Edit', url('admin/delivery_agent_edit.php?id=' . $agent['delivery_agent_id'])),
                                $agent['delivery_agent_is_active']
                                    ? adminActionButton('bi-pause-circle', 'Deactivate', 'toggle_active', (int) $agent['delivery_agent_id'])
                                    : adminActionButton('bi-play-circle', 'Activate', 'toggle_active', (int) $agent['delivery_agent_id']),
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
