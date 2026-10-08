<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\WorkModel;

/**
 * What the export that is being made right now looks like: which kind of work its entries are and,
 * from that, which columns it shows (see WorkModelService::describeExport).
 *
 * The page that starts an export sets it; the parts that build the file (the column list of the Excel
 * and CSV files, the PDF and print templates) read it. Without it an export uses the columns for mixed work.
 */
final class ExportContext
{
    /** @var array<string, mixed>|null */
    private static ?array $layout = null;

    /**
     * @param array<string, mixed>|null $layout
     */
    public static function set(?array $layout): void
    {
        self::$layout = $layout;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return self::$layout ?? [
            'model' => 'mixed',
            'title' => 'All work',
            'mixed' => true,
            // name of the custom field of a time entry => column title
            'columns' => array_merge(WorkModelService::META_LEVELS, ['phase' => 'Phase']),
            'showProject' => true,
            'showEmployee' => true,
            'types' => [],
        ];
    }
}
