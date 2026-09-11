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
    private const CATEGORIES = ['drva', 'peleti', 'briketi'];

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
        return [
            'name'          => $product['name'],
            'unit'          => $product['unit'],
            'price'         => round($product['price_per_unit'], 2),
            'price_display' => $this->formatPrice($product['price_per_unit']) . ' za ' . $this->unitPhrase($product['unit']),
            'availability'  => $product['stock_quantity'] > 0 ? 'na zalogi' : 'trenutno ni na zalogi',
        ];
    }

    private function formatPrice(float $price): string
    {
        // Slovenski zapis: decimalna vejica, presledek pred valuto.
        return number_format($price, 2, ',', '.') . ' €';
    }

    private function unitPhrase(string $unit): string
    {
        $phrases = [
            'kubik'  => 'kubični meter',
            'vreča'  => 'vrečo',
            'paleta' => 'paleto',
            'tona'   => 'tono',
            'paket'  => 'paket',
            'zaboj'  => 'zaboj',
        ];
        return $phrases[$unit] ?? $unit;
    }
}
