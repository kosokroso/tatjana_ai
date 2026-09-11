<?php
/**
 * Vmesnik med tooli in virom podatkov.
 *
 * Tooli govorijo SAMO s tem vmesnikom — nikoli neposredno z bazo ali ERP-jem.
 * Ko podjetje zamenja sistem, napišeš nov adapter, tooli ostanejo enaki.
 *
 * Pravila za implementacije:
 *   - Metode vračajo navadna PHP polja (associative arrays), ne objektov baze.
 *   - Če zapisa ni, vrni null (oz. prazno polje pri seznamih) — ne meči izjem.
 *   - Za resnične napake (baza nedosegljiva) vrzi AdapterException.
 *   - Vrnjena polja imajo vedno enake ključe, ne glede na to, kako jih
 *     posamezen ERP poimenuje interno. Adapter je tisti, ki jih preslika.
 */
interface AdapterInterface
{
    /**
     * Išče izdelke po prostem besedilu (ime ali opis).
     *
     * @return array[] Vsak element: id, name, category, unit, price_per_unit,
     *                 stock_quantity, description
     */
    public function searchProducts(string $query, ?string $category = null, int $limit = 10): array;

    /**
     * @return array|null Enaki ključi kot pri searchProducts()
     */
    public function getProductById(int $id): ?array;

    /**
     * Naročilo skupaj s podatki stranke — tool sam odloči, kaj sme razkriti.
     *
     * @return array|null id, status, order_date, delivery_date, quantity, note,
     *                    product_name, product_unit, price_per_unit,
     *                    customer_id, customer_name, customer_phone, customer_email
     */
    public function findOrderById(int $orderId): ?array;

    /**
     * @return array[] Sedem vrstic, ključ day_of_week (1 = ponedeljek ... 7 = nedelja),
     *                 vsaka: day_of_week, opens_at, closes_at, closed
     */
    public function getBusinessHours(): array;

    /**
     * Shrani povpraševanje stranke in vrne njegovo številko.
     *
     * Edina metoda, ki piše. Pri drugem ERP-ju bo zapis pristal drugam (nov
     * dokument, REST klic), zato spada sem in ne v tool.
     *
     * @param array $inquiry name, phone, email, product, quantity, note, source
     * @throws AdapterException če zapisa ni bilo mogoče shraniti
     */
    public function createInquiry(array $inquiry): int;
}

/**
 * Vir podatkov ni dosegljiv ali je vrnil nekaj nepričakovanega.
 * Endpoint to prevede v čisto napako, AI pa stranko preusmeri na telefon.
 */
class AdapterException extends RuntimeException
{
}
