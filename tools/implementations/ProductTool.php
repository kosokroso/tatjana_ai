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
        $limit = $action === 'search' ? 8 : 5;

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
     * Poleg surovih vrednosti vrnemo tudi pripravljene nize v slovenščini.
     * AI jih samo prebere, namesto da bi sam oblikoval ceno ali sklanjal enoto —
     * manj možnosti, da si kaj izmisli ali narobe zaokroži.
     */
    private function formatProduct(array $product): array
    {
        $inStock = $product['stock_quantity'] > 0;

        return [
            'id'            => $product['id'],
            'name'          => $product['name'],
            'category'      => $product['category'],
            'unit'          => $product['unit'],
            'price'         => round($product['price_per_unit'], 2),
            'price_display' => $this->formatPrice($product['price_per_unit']) . ' za ' . $this->unitPhrase($product['unit']),
            'stock'         => $product['stock_quantity'],
            'in_stock'      => $inStock,
            'availability'  => $inStock ? 'na zalogi' : 'trenutno ni na zalogi',
            'description'   => $product['description'],
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
