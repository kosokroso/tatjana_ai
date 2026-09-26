<?php
/**
 * Odgovori na vprašanja, ki jih ni v katalogu.
 *
 * Vhod:  { "query": "ali delate tudi za društva" }
 * Izhod: { "matches": [ { "question": "...", "answer": "..." } ] }
 *
 * Katalog pove, kaj podjetje prodaja in po čem. Vse drugo — pogoji, potek dela,
 * omejitve — doslej ni imelo vira, zato je asistent odgovoril splošno ali pa
 * priznal, da ne ve. Oboje je pri stranki, ki se odloča, izguba.
 *
 * Vsebino ureja podjetje v admin/znanje.php. V kodi ne sme biti nič od tega:
 * pri naslednji stranki so vprašanja druga.
 */
final class KnowledgeTool extends Tool
{
    public function name(): string
    {
        return 'knowledge-lookup';
    }

    public function handle(array $input): ToolResponse
    {
        $query = $this->requireString($input, 'query', 300);

        $zadetki = $this->adapter->searchKnowledge($query, 3);

        if (!$zadetki) {
            // Prazen seznam ni napaka. Model mora vedeti, da vira ni, da ne
            // začne ugibati — in da lahko ponudi povpraševanje namesto odgovora.
            return ToolResponse::ok([
                'matches' => [],
                'note'    => 'Na to vprašanje nimamo zapisanega odgovora. Ne ugibaj; '
                    . 'povej, da bo to potrdil sodelavec, in ponudi pripravo ponudbe.',
            ]);
        }

        return ToolResponse::ok([
            'matches' => array_map(static function (array $vrstica): array {
                return [
                    'question' => $vrstica['question'],
                    'answer'   => $vrstica['answer'],
                ];
            }, $zadetki),
        ]);
    }
}
