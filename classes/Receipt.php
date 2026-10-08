<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Order receipts as PDF ("Pakua Risiti").
 * The layout is the HTML template classes/views/receipt.php; Dompdf turns it into a PDF.
 *
 * How to use it:
 *   $receipt = (new Receipt(Database::instance()))->createReceiptForCustomer($user_id, $order_id);
 *   return Response::download($receipt['pdf'], 'application/pdf', $receipt['file_name']);
 */
class Receipt
{
    public const PAYMENT_METHOD_NAMES = [
        'mpesa'        => 'M-Pesa',
        'airtel_money' => 'Airtel Money',
        'mixx'         => 'Mixx by Yas',
        'bank'         => 'Benki',
        'cod'          => 'Lipa ukipokea',
    ];

    public const PAYMENT_STATUS_NAMES = [
        'unpaid'      => 'Haijalipwa',
        'pending'     => 'Inasubiri malipo',
        'paid'        => 'Imelipwa',
        'cod_pending' => 'Italipwa wakati wa kupokea',
        'refunded'    => 'Imerejeshwa',
        'failed'      => 'Malipo yameshindikana',
        'cancelled'   => 'Hakuna malipo (oda imesitishwa)',
    ];

    public const ORDER_STATUS_NAMES = [
        'pending_payment' => 'Inasubiri malipo',
        'confirmed'       => 'Imethibitishwa',
        'packed'          => 'Imepakiwa',
        'dispatched'      => 'Imetumwa',
        'in_transit'      => 'Inasafirishwa',
        'delivered'       => 'Imewasili',
        'cancelled'       => 'Imesitishwa',
        'expired'         => 'Imeisha muda',
    ];

    public function __construct(private Database $db)
    {
    }

    /** The customer's own order only (404 for anyone else's). Returns ['pdf' => bytes, 'file_name' => …]. */
    public function createReceiptForCustomer(int $user_id, int $order_id): array
    {
        $order   = (new Order($this->db))->getOrder($user_id, $order_id);
        $profile = (new User($this->db))->getProfile($user_id);

        return $this->createReceipt($order, [
            'user_full_name' => $profile['user_full_name'],
            'user_phone'     => $profile['user_phone'],
            'business_name'  => $profile['business']['business_name'] ?? null,
        ]);
    }

    /** Any order — for staff printing a receipt from the admin. */
    public function createReceiptForAdmin(int $order_id): array
    {
        $order = (new OrderManager($this->db))->getOrderForAdmin($order_id);
        return $this->createReceipt($order, $order['customer']);
    }

    private function createReceipt(array $order, array $customer): array
    {
        return [
            'pdf'       => $this->renderPdf($this->renderHtml($order, $customer)),
            'file_name' => "Risiti-{$order['order_number']}.pdf",
        ];
    }

    /** Fills the HTML template with the order (the template escapes every value with e()). */
    private function renderHtml(array $order, array $customer): string
    {
        ob_start();
        require BASE_PATH . '/classes/views/receipt.php';
        return (string) ob_get_clean();
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');     // has every character we use (TZS, –, •)
        $options->set('isRemoteEnabled', false);         // never download anything from the internet
        $options->set('chroot', BASE_PATH);              // may only read files inside the project

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
