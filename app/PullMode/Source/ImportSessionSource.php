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

    public function hasContractId(): bool
    {
        return false;
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
            SELECT
                'IS-' || importacao.fk_layoutempresa  AS legacy_id,
                importacao.fk_layout                  AS legacy_layout_id,
                'IMP-' || importacao.fk_layoutempresa AS legacy_import_id,
                COALESCE(
                    MIN(NULLIF(importacao.arquivo, '')),
                    'legacy-import-' || importacao.fk_layoutempresa || '.txt'
                )                           AS file_name,
                MIN(NULLIF(importacao.arquivo, '')) AS original_file_name,
                'completed'                 AS status
            FROM importacao
            JOIN layout_empresa ON layout_empresa.pk = importacao.fk_layoutempresa
            WHERE importacao.fk_layout <> 0
            GROUP BY importacao.fk_layoutempresa, importacao.fk_layout
            HAVING MAX(importacao.inclusao) > NOW() - INTERVAL '60 days'
            ORDER BY 'IS-' || importacao.fk_layoutempresa
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
