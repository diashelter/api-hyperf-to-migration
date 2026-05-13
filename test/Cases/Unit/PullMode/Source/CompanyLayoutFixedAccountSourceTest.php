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

namespace HyperfTest\Cases\Unit\PullMode\Source;

use App\PullMode\Source\CompanyLayoutFixedAccountSource;
use HyperfTest\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(CompanyLayoutFixedAccountSource::class)]
final class CompanyLayoutFixedAccountSourceTest extends UnitTestCase
{
    public function testTransformRowNormalizesFixedAccountsModel(): void
    {
        $source = new CompanyLayoutFixedAccountSource();

        $result = $source->transformRow([
            'legacy_id' => 'LF-292',
            'legacy_company_layout_id' => 292,
            'bank_account' => '84',
            'contas_fixas_modelo' => json_encode([
                'D' => [
                    ['campo' => 'Valor', 'conta' => '1.1.01', 'la' => 'ignored', 'cod_hist' => '101', 'hist_person' => 'Valor debito'],
                    ['campo' => 'Juros', 'conta' => '1.1.02', 'la' => '', 'cod_hist' => '102', 'hist_person' => 'Juros debito'],
                    ['campo' => 'Multa', 'conta' => '1.1.03', 'la' => '', 'cod_hist' => '103', 'hist_person' => 'Multa debito'],
                    ['campo' => 'Desconto', 'conta' => '1.1.04', 'la' => '', 'cod_hist' => '104', 'hist_person' => 'Desconto debito'],
                    ['campo' => 'Outros', 'conta' => '1.1.05', 'la' => '', 'cod_hist' => '105', 'hist_person' => 'Outros debito'],
                    ['campo' => 'Devolução', 'conta' => '1.1.06', 'la' => '', 'cod_hist' => '106', 'hist_person' => 'Devolucao debito'],
                    ['campo' => 'Tarifas', 'conta' => '1.1.00', 'la' => '', 'cod_hist' => '100', 'hist_person' => 'Tarifas debito'],
                    ['campo' => 'Taxas', 'conta' => '1.1.07', 'la' => '', 'cod_hist' => '107', 'hist_person' => 'Taxas debito'],
                ],
                'C' => [
                    ['campo' => 'Valor', 'conta' => '2.1.01', 'la' => '', 'cod_hist' => '201', 'hist_person' => 'Valor credito'],
                    ['campo' => 'Juros', 'conta' => '2.1.02', 'la' => '', 'cod_hist' => '202', 'hist_person' => 'Juros credito'],
                    ['campo' => 'Tarifas', 'conta' => '2.1.07', 'la' => '', 'cod_hist' => '207', 'hist_person' => 'Tarifas credito'],
                    ['campo' => 'Taxas', 'conta' => '', 'la' => '', 'cod_hist' => '', 'hist_person' => ''],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ], 'contract-id');

        $this->assertArrayNotHasKey('contas_fixas_modelo', $result);
        $this->assertSame('LF-292', $result['legacy_id']);
        $this->assertSame(292, $result['legacy_company_layout_id']);
        $this->assertSame('84', $result['bank_account']);

        $this->assertSame('1.1.01', $result['value_debit']);
        $this->assertSame('101', $result['value_code_history_debit']);
        $this->assertSame('Valor debito', $result['value_history_debit']);
        $this->assertSame('1.1.02', $result['fees_debit']);
        $this->assertSame('102', $result['fees_code_history_debit']);
        $this->assertSame('Juros debito', $result['fees_history_debit']);
        $this->assertSame('1.1.03', $result['fine_debit']);
        $this->assertSame('103', $result['fine_code_history_debit']);
        $this->assertSame('Multa debito', $result['fine_history_debit']);
        $this->assertSame('1.1.04', $result['discount_debit']);
        $this->assertSame('104', $result['discount_code_history_debit']);
        $this->assertSame('Desconto debito', $result['discount_history_debit']);
        $this->assertSame('1.1.05', $result['others_debit']);
        $this->assertSame('105', $result['others_code_history_debit']);
        $this->assertSame('Outros debito', $result['others_history_debit']);
        $this->assertSame('1.1.06', $result['refunds_debit']);
        $this->assertSame('106', $result['refunds_code_history_debit']);
        $this->assertSame('Devolucao debito', $result['refunds_history_debit']);
        $this->assertSame('1.1.07', $result['rates_debit']);
        $this->assertSame('107', $result['rates_code_history_debit']);
        $this->assertSame('Taxas debito', $result['rates_history_debit']);

        $this->assertSame('2.1.01', $result['value_credit']);
        $this->assertSame('201', $result['value_code_history_credit']);
        $this->assertSame('Valor credito', $result['value_history_credit']);
        $this->assertSame('2.1.02', $result['fees_credit']);
        $this->assertSame('202', $result['fees_code_history_credit']);
        $this->assertSame('Juros credito', $result['fees_history_credit']);
        $this->assertNull($result['fine_credit']);
        $this->assertSame('2.1.07', $result['rates_credit']);
        $this->assertSame('207', $result['rates_code_history_credit']);
        $this->assertSame('Tarifas credito', $result['rates_history_credit']);
    }

    public function testTransformRowRemovesInvalidModelAndKeepsNullableFields(): void
    {
        $source = new CompanyLayoutFixedAccountSource();

        $result = $source->transformRow([
            'legacy_id' => 'LF-772',
            'legacy_company_layout_id' => 772,
            'bank_account' => '11055',
            'contas_fixas_modelo' => 'invalid-json',
        ], 'contract-id');

        $this->assertArrayNotHasKey('contas_fixas_modelo', $result);
        $this->assertNull($result['value_debit']);
        $this->assertNull($result['value_code_history_debit']);
        $this->assertNull($result['value_history_debit']);
        $this->assertNull($result['value_credit']);
        $this->assertNull($result['value_code_history_credit']);
        $this->assertNull($result['value_history_credit']);
    }

    public function testSourceDoesNotInjectContractId(): void
    {
        $source = new CompanyLayoutFixedAccountSource();

        $this->assertFalse($source->hasContractId());
        $this->assertSame('legacy_company_layout_id', $source->paginationKey());
    }

    public function testQueriesOnlyReadFixedAccountsForMigratedCompanyLayouts(): void
    {
        $source = new CompanyLayoutFixedAccountSource();

        foreach ([$source->sql(), $source->countSql()] as $query) {
            $this->assertStringContainsString(
                'JOIN layout ON layout.pk = layout_empresa.fk_layoutimp AND layout.visivel = 1',
                $query
            );
            $this->assertStringContainsString('layout_empresa.fk_empresa <> 0', $query);
            $this->assertStringContainsString('layout_empresa.fk_layoutimp <> 0', $query);
        }
    }
}
