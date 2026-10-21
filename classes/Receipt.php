<?php

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Order PDFs: the receipt ("Pakua Risiti") and the staff packing slip.
 * The layouts are HTML templates in classes/views/; Dompdf turns them into PDFs.
 *
 * How to use it:
 *   $receipt = (new Receipt(Database::instance()))->createReceiptForCustomer($user_id, $order_id);
 *   return Response::download($receipt['pdf'], 'application/pdf', $receipt['file_name']);
 */
class Receipt
{
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

    /**
     * Staff packing slip: what to pack (no prices), where it goes, the cash to collect for cash on delivery,
     * and a signed order link as a QR code. Returns ['pdf' => bytes, 'file_name' => …]. 404 if the order is missing.
     */
    public function createPackingSlip(int $order_id): array
    {
        $order = (new OrderManager($this->db))->getOrderForAdmin($order_id);

        return [
            'pdf'       => $this->renderPdf($this->renderHtml('packing_slip', [
                'order'       => $order,
                'qr_code_uri' => $this->qrCodeDataUri((new OrderLink($this->db))->createUrl($order['order_number'])),
            ])),
            'file_name' => "Kupakia-{$order['order_number']}.pdf",
        ];
    }

    private function createReceipt(array $order, array $customer): array
    {
        return [
            'pdf'       => $this->renderPdf($this->renderHtml('receipt', ['order' => $order, 'customer' => $customer])),
            'file_name' => "Risiti-{$order['order_number']}.pdf",
        ];
    }

    /** Fills a template in classes/views/ with these variables (the templates escape every value with e()). */
    private function renderHtml(string $template, array $variables): string
    {
        // The uploaded logo, else the built-in mark (PDFs need the picture inside the file)
        $logo_uri = (new SiteLogo($this->db))->pdfDataUri(BASE_PATH . '/admin/assets/img/favicon-192.png');
        extract($variables);
        ob_start();
        require BASE_PATH . "/classes/views/{$template}.php";
        return (string) ob_get_clean();
    }

    /** A QR code of the text as a PNG data URI, drawn here (nothing is sent to any website). */
    private function qrCodeDataUri(string $text): string
    {
        $options = new QROptions([
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'outputBase64' => true,    // "data:image/png;base64,…", ready for <img src>
            'scale'        => 8,       // pixels per square: sharp when printed
            'quietzoneSize' => 2,
        ]);
        return (new QRCode($options))->render($text);
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
