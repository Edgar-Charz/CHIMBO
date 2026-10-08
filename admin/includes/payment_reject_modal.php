<?php

/**
 * The "Reject payment" dialog (Payments page and order page). Opened by a button with
 * data-open-modal="#reject-payment-modal" data-record-id="{payment_id}" data-record-label="{order · amount}" (admin.js).
 * The reason is shown to the customer, so it is written in Kiswahili.
 */
?>
<div class="modal fade" id="reject-payment-modal" tabindex="-1" aria-labelledby="reject-payment-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="record_id" value="">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="reject-payment-title">Reject payment <span class="text-muted fs-6" data-record-label></span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="payment_review_note">Reason <span class="text-muted">(the customer sees it — Kiswahili)</span></label>
                <textarea class="form-control" id="payment_review_note" name="payment_review_note" rows="3" minlength="5" maxlength="255" required
                          placeholder="Hatukupata malipo haya kwenye taarifa yetu."></textarea>
                <div class="form-text">5–255 characters. The customer can then send the correct payment details.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" type="submit" name="form_action" value="reject_payment">Reject payment</button>
            </div>
        </form>
    </div>
</div>
