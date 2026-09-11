<?php
/**
 * Predloga za Vasco ERP (ali katerikoli drug sistem).
 *
 * Ni implementiran — služi kot vzorec, ko pride čas za povezavo s pravim ERP.
 * Tooli se NE spreminjajo; edina sprememba v konfiguraciji je:
 *     define('ADAPTER', 'VascoAdapter');
 *
 * Kaj je treba narediti ob implementaciji:
 *   1. V konstruktorju vzpostavi povezavo (ODBC/MSSQL, REST API ali izvoz CSV —
 *      odvisno od tega, kaj Vasco na konkretni namestitvi ponuja).
 *   2. V vsaki metodi preslikaj stolpce ERP-ja v ključe, ki jih predpisuje
 *      AdapterInterface. Preslikava spada SEM, ne v toole.
 *   3. Ob napaki povezave vrzi AdapterException — nikoli ne vračaj polovičnih
 *      podatkov, ker bi jih AI predstavil stranki kot resnične.
 */
final class VascoAdapter implements AdapterInterface
{
    public function __construct(array $config)
    {
        throw new AdapterException(
            'VascoAdapter še ni implementiran. V konfiguraciji nastavi ADAPTER na DirectMySQLAdapter.'
        );
    }

    public function searchProducts(string $query, ?string $category = null, int $limit = 10): array
    {
        throw new AdapterException('VascoAdapter::searchProducts ni implementiran.');
    }

    public function getProductById(int $id): ?array
    {
        throw new AdapterException('VascoAdapter::getProductById ni implementiran.');
    }

    public function findOrderById(int $orderId): ?array
    {
        throw new AdapterException('VascoAdapter::findOrderById ni implementiran.');
    }

    public function getBusinessHours(): array
    {
        throw new AdapterException('VascoAdapter::getBusinessHours ni implementiran.');
    }

    public function createInquiry(array $inquiry): int
    {
        throw new AdapterException('VascoAdapter::createInquiry ni implementiran.');
    }
}
