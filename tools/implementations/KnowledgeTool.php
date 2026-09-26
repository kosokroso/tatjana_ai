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

        // Zadetek je zadetek po besedah, ne po pomenu. Iskanje po besedah bo
        // vedno kdaj vrnilo zapis, ki se ujema z besedami in ne z vprašanjem —
        // in model tak zapis rad vzame za potrdilo. Zato navodilo potuje skupaj
        // z izidom, ne samo v promptu: kar pride iz orodja, model upošteva bolj.
        return ToolResponse::ok([
            'matches' => array_map(static function (array $vrstica): array {
                return [
                    'question' => $vrstica['question'],
                    'answer'   => $vrstica['answer'],
                ];
            }, $zadetki),
            'note' => 'Uporabi samo zadetek, katerega vprašanje pomeni isto kot vprašanje stranke. '
                . 'Ujemanje po besedah ni ujemanje po pomenu. Če noben zadetek ne odgovarja na to, '
                . 'kar je stranka res vprašala, povej, da tega nimaš potrjenega, in ponudi, da potrdi '
                . 'sodelavec. Ne sestavljaj odgovora iz zadetka o drugi temi.',
        ]);
    }
}
