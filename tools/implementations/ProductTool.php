<?php
/**
 * Iskanje izdelkov, cen in zaloge.
 *
 * Vhod:  { "query": "bukova drva", "action": "get_price", "category": "drva" }
 * Izhod: seznam zadetkov s ceno, enoto in razpoložljivostjo.
 */
final class ProductTool extends Tool
{
    private const ACTIONS    = ['search', 'get_price', 'check_stock'];
    private const CATEGORIES = ['spletne-strani', 'trzenje', 'oblikovanje', 'vzdrzevanje'];

    public function name(): string
    {
        return 'product-lookup';
    }

    public function handle(array $input): ToolResponse
    {
        $query    = $this->requireString($input, 'query', 120);
        $action   = $this->enumValue($input, 'action', self::ACTIONS, 'search');
        $category = $this->enumValue($input, 'category', self::CATEGORIES, null);

        // Pri preverjanju zaloge stranko zanima ena stvar, ne cel katalog.
        // Vsak zadetek gre v pogovor z modelom in se šteje v porabo žetonov,
        // zato raje manj zadetkov: pri telefonskem odgovoru jih itak ne našteje
        // več kot dva ali tri.
        $limit = $action === 'search' ? 5 : 3;

        $products = $this->adapter->searchProducts($query, $category, $limit);

        if (!$products) {
            return ToolResponse::notFound(
                'Za "' . $query . '" ni zadetkov v katalogu.'
            );
        }

        $items = [];
        foreach ($products as $product) {
            $items[] = $this->formatProduct($product);
        }

        return ToolResponse::ok($items);
    }

    /**
     * Poleg surove cene vrnemo tudi pripravljen niz v slovenščini. AI ga samo
     * prebere, namesto da bi sam oblikoval ceno ali sklanjal enoto — manj
     * možnosti, da si kaj izmisli ali narobe zaokroži.
     *
     * Namenoma vračamo malo polj. Vsak znak tu gre v pogovor z modelom in se
     * plača: opis izdelka in številčna zaloga sta za odgovor stranki odveč,
     * ker asistent pove ime, ceno in ali je izdelek na voljo.
     */
    private function formatProduct(array $product): array
    {
        $item = [
            'name'          => $product['name'],
            'unit'          => $product['unit'],
            'price'         => $product['price_per_unit'],
            'price_display' => $this->priceDisplay($product),
            'available'     => $product['stock_quantity'] > 0,
        ];

        if (!empty($product['description'])) {
            $item['description'] = $product['description'];
        }

        return $item;
    }

    /**
     * Pripravljen niz, ki ga asistent samo prebere.
     *
     * Dve stvari, ki ju model ne sme narediti sam: izhodiščne cene predstaviti
     * kot končno ("399 €" namesto "od 399 €") in manjkajočo ceno nadomestiti
     * z ugibanjem. Oboje se tu odloči enkrat in pravilno.
     */
    private function priceDisplay(array $product): string
    {
        if ($product['price_per_unit'] === null) {
            return 'cena po dogovoru';
        }

        $price = number_format((float) $product['price_per_unit'], 2, ',', '.') . ' €';

        if (!empty($product['price_from'])) {
            return 'od ' . $price . ' za ' . $this->unitPhrase($product['unit']);
        }

        return $price . ' za ' . $this->unitPhrase($product['unit']);
    }

    private function unitPhrase(string $unit): string
    {
        $phrases = [
            'paket'   => 'paket',
            'mesec'   => 'mesec',
            'projekt' => 'projekt',
            'ura'     => 'uro',
            'kubik'   => 'kubični meter',
            'vreča'   => 'vrečo',
            'paleta'  => 'paleto',
            'tona'    => 'tono',
        ];
        return $phrases[$unit] ?? $unit;
    }
}
