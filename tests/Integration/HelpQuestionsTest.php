<?php

use PHPUnit\Framework\TestCase;

/** Msaada's common questions: one list for the website and the app; "Ninalipaje?" follows the switched-on methods. */
final class HelpQuestionsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        PaymentMethod::clearCache();
    }

    protected function tearDown(): void
    {
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code = 'cod')");
        PaymentMethod::clearCache();
    }

    public function testPaymentAnswerNamesTheMethodsSwitchedOnAndNoCashWhenItIsOff(): void
    {
        $this->db->execute("UPDATE payment_methods SET payment_method_is_active = (payment_method_code IN ('mpesa', 'mixx'))");

        $answer = $this->answerTo('Ninalipaje?');

        $this->assertStringContainsString('Unalipa kwa M-Pesa au Mixx', $answer);
        $this->assertStringNotContainsString('lipa ukipokea', $answer);
    }

    public function testEveryQuestionHasAnAnswerAndTransportIsExplained(): void
    {
        $questions = (new HelpQuestions($this->db))->getQuestions();

        $this->assertNotEmpty($questions);
        foreach ($questions as $item) {
            $this->assertNotSame('', $item['answer'], $item['question']);
        }
        $this->assertStringContainsString('unamlipa dereva', $this->answerTo('Usafiri unalipwaje?'));
    }

    private function answerTo(string $question): string
    {
        $answers = array_column((new HelpQuestions($this->db))->getQuestions(), 'answer', 'question');
        return $answers[$question];
    }
}
