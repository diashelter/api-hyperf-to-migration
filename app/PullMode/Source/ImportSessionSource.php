<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\PullMode\Source;

/**
 * Source legada → `import_sessions`. FKs: imports, layouts.
 *
 * TODO: ajustar nome da tabela legada e aliases das colunas.
 */
class ImportSessionSource extends AbstractLegacySource
{
    public function entity(): string
    {
        return 'import_sessions';
    }

    public function targetTable(): string
    {
        return 'import_sessions';
    }

    public function fkMap(): array
    {
        return [
            'legacy_import_id' => 'imports',
            'legacy_layout_id' => 'layouts',
        ];
    }

    public function sql(): string
    {
        return <<<'SQL'
            SELECT DISTINCT
                'IS-' || fk_layoutempresa   AS legacy_id,
                fk_layout                   AS legacy_layout_id,
                'IMP-' || fk_layoutempresa  AS legacy_import_id
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
            WHERE fk_layout <> 0
            ORDER BY 'IS-' || fk_layoutempresa
        SQL;
    }

    public function validationRules(): array
    {
        return [
            'legacy_layout_id' => 'required|integer',
            'legacy_import_id' => 'nullable|string',
            'file_name' => 'nullable|string',
            'date_to_create' => 'nullable|string',
            'size' => 'nullable|integer',
        ];
    }
}
