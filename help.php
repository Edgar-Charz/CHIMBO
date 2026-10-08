<?php

/**
 * Msaada — common questions and how to reach CHIMBO (phone, WhatsApp, hours come from the admin settings).
 */

require __DIR__ . '/includes/init.php';

$settings = new Settings(Database::instance());
$support = $settings->getSupportContacts();
$cash_on_delivery_limit = $settings->getInt('cod_max_order_total', 0);

$questions = [
    ['question' => 'Ninaagizaje?', 'answer' => 'Chagua bidhaa, weka idadi unayotaka, kisha bofya "Ongeza kikapuni". Ukimaliza, fungua kikapu na ubofye "Endelea kwenye Malipo". Utaingia kwa namba yako ya simu, utachagua anwani na njia ya usafirishaji, kisha "Thibitisha Oda".'],
    ['question' => 'Bei za jumla zinafanyaje kazi?', 'answer' => 'Kila bidhaa ina ngazi za bei. Kadri unavyoagiza vipande vingi, ndivyo bei ya kila kipande inavyoshuka. Kikapu kinakuonyesha bei ya ngazi uliyofikia na unachohitaji kuongeza ili kufikia bei nafuu zaidi.'],
    ['question' => 'MOQ ni nini?', 'answer' => 'MOQ ni idadi ndogo kabisa unayoweza kuagiza ya bidhaa hiyo. Inaonyeshwa kwenye kila bidhaa.'],
    ['question' => 'Ninalipaje?', 'answer' => 'Kwa sasa unalipa pesa taslimu mzigo ukifika dukani kwako (lipa ukipokea)' . ($cash_on_delivery_limit > 0 ? ', kwa oda zisizozidi ' . formatTzs($cash_on_delivery_limit) : '') . '. Malipo kwa simu yanakuja hivi karibuni.'],
    ['question' => 'Mzigo unafika baada ya muda gani?', 'answer' => 'Kila bidhaa inaonyesha siku za kufika. Kwenye malipo unachagua njia ya usafirishaji, na tarehe inayotarajiwa inaonyeshwa kabla ya kuthibitisha oda.'],
    ['question' => 'Nitajuaje oda yangu imefika wapi?', 'answer' => 'Fungua "Oda Zangu" kisha "Fuatilia". Utaona kila hatua — imethibitishwa, imepakiwa, imetumwa, inasafirishwa, imewasili — pamoja na namba ya wakala anayekuletea. Pia tunakutumia arifa kila hatua.'],
    ['question' => 'Naweza kughairi oda?', 'answer' => 'Ndiyo, kabla oda haijaanza kupakiwa. Fungua oda kwenye "Oda Zangu" na ubofye "Ghairi oda".'],
    ['question' => 'Nimesahau PIN yangu', 'answer' => 'Kwenye ukurasa wa kuingia, weka namba yako kisha ubofye "Umesahau PIN?". Tutakutumia namba ya siri kwa SMS, kisha utaweka PIN mpya.'],
    ['question' => 'Naweza kutumia tovuti na programu ya simu pamoja?', 'answer' => 'Ndiyo. Ukiingia kwa namba ile ile, kikapu, oda, vipendwa na anwani zako ni vile vile kwenye tovuti na kwenye programu.'],
];

$page = [
    'title'       => 'Msaada',
    'description' => 'Maswali ya mara kwa mara na jinsi ya kuwasiliana na CHIMBO.',
];

require __DIR__ . '/includes/header.php';
?>

<section class="container-xl text-page">
    <h1 class="page-title">Tunakusaidiaje?</h1>

    <div class="help-contacts">
        <?php if ($support['support_phone']) : ?>
            <a class="help-contact" href="tel:<?= e($support['support_phone']) ?>">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span><strong>Piga simu</strong><?= e($support['support_phone']) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($support['support_whatsapp_url'] !== null) : ?>
            <a class="help-contact" href="<?= e($support['support_whatsapp_url']) ?>" target="_blank" rel="noopener">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span><strong>WhatsApp</strong>Tuandikie ujumbe</span>
            </a>
        <?php endif; ?>
        <?php if ($support['support_hours']) : ?>
            <div class="help-contact">
                <i class="bi bi-clock" aria-hidden="true"></i>
                <span><strong>Muda wa huduma</strong><?= e($support['support_hours']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <h2 class="text-page__subtitle">Maswali ya mara kwa mara</h2>
    <div class="accordion help-questions" id="help-questions">
        <?php foreach ($questions as $index => $item) : ?>
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#help-answer-<?= $index ?>" aria-expanded="false" aria-controls="help-answer-<?= $index ?>">
                        <?= e($item['question']) ?>
                    </button>
                </h3>
                <div class="accordion-collapse collapse" id="help-answer-<?= $index ?>" data-bs-parent="#help-questions">
                    <div class="accordion-body"><?= e($item['answer']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
