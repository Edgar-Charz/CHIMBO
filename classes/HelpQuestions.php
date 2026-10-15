<?php

/**
 * "Maswali ya mara kwa mara" — the common questions shown in Msaada on the website (help.php) and in the app
 * (GET /support → help_questions), so both say the same thing. "Ninalipaje?" follows the payment methods staff
 * switched on.
 *
 * How to use it:
 *   $questions = (new HelpQuestions(Database::instance()))->getQuestions();   // [['question' => …, 'answer' => …], …]
 */
class HelpQuestions
{
    public function __construct(private Database $db)
    {
    }

    public function getQuestions(): array
    {
        return [
            ['question' => 'Ninaagizaje?', 'answer' => 'Chagua bidhaa, weka idadi unayotaka, kisha "Ongeza kikapuni". Ukimaliza, fungua kikapu na uendelee kwenye malipo: chagua anwani na njia ya usafirishaji, kisha "Thibitisha Oda".'],
            ['question' => 'Bei za jumla zinafanyaje kazi?', 'answer' => 'Kila bidhaa ina ngazi za bei. Kadri unavyoagiza vipande vingi, ndivyo bei ya kila kipande inavyoshuka. Kikapu kinakuonyesha bei ya ngazi uliyofikia na unachohitaji kuongeza ili kufikia bei nafuu zaidi.'],
            ['question' => 'MOQ ni nini?', 'answer' => 'MOQ ni idadi ndogo kabisa unayoweza kuagiza ya bidhaa hiyo. Inaonyeshwa kwenye kila bidhaa.'],
            ['question' => 'Ninalipaje?', 'answer' => $this->paymentAnswer()],
            ['question' => 'Mzigo unafika baada ya muda gani?', 'answer' => 'Kila bidhaa inaonyesha siku za kufika. Kwenye malipo unachagua njia ya usafirishaji, na tarehe inayotarajiwa inaonyeshwa kabla ya kuthibitisha oda.'],
            ['question' => 'Usafiri unalipwaje?', 'answer' => 'Dar es Salaam tunakutumia mzigo kwa Bolt na unamlipa dereva moja kwa moja ukipokea — gharama hiyo haiko kwenye jumla ya oda. Mikoani unaona makadirio ya usafiri hadi mkoa wako; tunapima mzigo, tunakuthibitishia bei, kisha unalipa CHIMBO. Au chagua "Nitachukua mwenyewe dukani" — bure.'],
            ['question' => 'Nitajuaje oda yangu imefika wapi?', 'answer' => 'Fungua "Oda Zangu" kisha oda yako. Utaona kila hatua — imethibitishwa, imepakiwa, imetumwa, inasafirishwa, imewasili — pamoja na namba ya wakala anayekuletea. Pia tunakutumia arifa kila hatua.'],
            ['question' => 'Naweza kughairi oda?', 'answer' => 'Ndiyo, kabla oda haijaanza kupakiwa. Fungua oda kwenye "Oda Zangu" na uchague "Ghairi oda".'],
            ['question' => 'Nimesahau PIN yangu', 'answer' => 'Kwenye ukurasa wa kuingia, weka namba yako kisha uchague "Umesahau PIN?". Tutakutumia namba ya siri kwa SMS, kisha utaweka PIN mpya.'],
            ['question' => 'Naweza kutumia tovuti na programu ya simu pamoja?', 'answer' => 'Ndiyo. Ukiingia kwa namba ile ile, kikapu, oda, vipendwa na anwani zako ni vile vile kwenye tovuti na kwenye programu.'],
        ];
    }

    /** "Ninalipaje?" from the payment methods switched on in the admin (no cash on delivery unless it is on). */
    private function paymentAnswer(): string
    {
        $methods  = (new PaymentMethod($this->db))->getActiveMethods();
        $has_cash = in_array('cash', array_column($methods, 'payment_method_type'), true);
        $names    = swahiliList(array_column(
            array_filter($methods, fn (array $method): bool => $method['payment_method_type'] !== 'cash'),
            'payment_method_name'
        ));

        $answer = $names !== ''
            ? "Unalipa kwa {$names}. Ukithibitisha oda, utaona namba ya kulipia na kiasi. Ukishalipa, chagua \"Nimelipa\" na uweke namba ya muamala kutoka kwenye SMS — tunathibitisha malipo yako kisha tunaanza kuandaa oda."
            : '';
        if ($has_cash) {
            $cash_limit = (new Settings($this->db))->getInt('cod_max_order_total', 0);
            $answer .= ($answer === '' ? 'Unalipa' : ' Pia unaweza kulipa')
                . ' pesa taslimu mzigo ukifika dukani kwako (lipa ukipokea)'
                . ($cash_limit > 0 ? ', kwa oda zisizozidi TZS ' . number_format($cash_limit) : '') . '.';
        }
        return $answer !== '' ? $answer : 'Wasiliana nasi kwa namba ya msaada kujua njia za kulipa.';
    }
}
