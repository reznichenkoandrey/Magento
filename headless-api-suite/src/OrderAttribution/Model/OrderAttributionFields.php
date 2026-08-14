<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

/**
 * The column names, in one place.
 *
 * They appear in five files that cannot see each other — the declarative schema, the grid virtual
 * type in di.xml, the UI component that renders the columns, the observer that writes them and the
 * resolver that reads them. Two of those are XML and will not fail loudly on a typo; they will
 * simply render an empty column forever.
 */
final class OrderAttributionFields
{
    public const SOURCE_CODE = 'scr1be_source_code';
    public const SOURCE_DETAIL = 'scr1be_source_detail';

    /**
     * Not instantiable — this is a namespace for two strings.
     */
    private function __construct()
    {
    }
}
