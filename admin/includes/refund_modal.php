<?php

/**
 * The "Mark refunded" dialog (Payments page → Refunds due, and the order page). Opened by a button with
 * data-open-modal="#refund-modal" data-record-id="{order_id}" data-record-label="{order · amount}" (admin.js).
 */
?>
<div class="modal fade" id="refund-modal" tabindex="-1" aria-labelledby="refund-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="record_id" value="">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="refund-title">Mark refunded <span class="text-muted fs-6" data-record-label></span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Send the money back first, then record how it was sent. The customer is notified.</p>
                <label class="form-label" for="refund_note">Refund note</label>
                <input class="form-control" id="refund_note" name="refund_note" minlength="5" maxlength="255" required
                       placeholder="e.g. M-Pesa QJK7XY12AB, 45,000 to 0712 345 678">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-chimbo" type="submit" name="form_action" value="mark_refunded">Mark refunded</button>
            </div>
        </form>
    </div>
</div>
