X-Contract-Id($ContractID): Nome da database(ex: integrador)

Observação: seguir a ordem correta de requisições

### Contrato ###
<code>{{base_url}}/api/v1/migration/contracts</code>

Usar tabela "contrato"
Campos Disponíveis | Banco Desktop
|-------------------------|------------------------|
legacy_id                 | CURRENT_DATABASE() 
cpf_cnpj                  | cnpj
corporate_name            | razaosocial
street                    | endereco
number                    | numero
neighborhood              | bairro
city                      | cidade
complement                | complemento
state                     | uf
name                      | fantasia
email                     | email
contractor_type           | "company" //usar default
company_count             | 1000      //usar default
legacy_status_contract    | status
zipcode                   | cep
is_approval               | false     //usar default
phone                     | telefone
legacy_database_id        | CURRENT_DATABASE() // user function
created_at                | inclusao

#### Query ####
<code>
SELECT 
    CURRENT_DATABASE()  as legacy_id,
    cnpj as cpf_cnpj,
    razaosocial as corporate_name,
    endereco as street,
    numero as number,
    bairro as neighborhood,
    cidade as city,
    complemento as complement,
    uf as STATE,
    COALESCE(NULLIF(fantasia, ''),razaosocial) as name,
    email as email,
    'company' as contractor_type,
    1000 as company_count,
    CASE ativo
        WHEN 'F' THEN 'SUSPENSO' 
        ELSE 'ATIVO' 
    END AS legacy_status_contract,
    cep as zipcode,
    false as is_approval,
    telefone as phone,
    CURRENT_DATABASE() as legacy_database_id,
    inclusao as created_at
FROM contrato
</code>

### Usuários ###
<code>{{base_url}}/api/v1/migration/users</code>

usar tabela "usuários"
Campos Disponíveis       | Banco Desktop
|------------------------|-------|
legacy_id                | pk
name                     | nome    
email                    | email
password                 | senha
status                   | inativo === false //se sim enviar true | se não enviar false
is_admin                 | administrador

#### query
<code>
SELECT
	pk AS legacy_id,
	nome as NAME,
	TRIM(email) as email,
	senha as password,
	CASE inativo WHEN true THEN false ELSE true END as status
	FROM usuarios
WHERE email <> 'suporte@integradorcontabil.net.br'
</code>

### Usuários do contrato ###
<code>{{base_url}}/api/v1/migration/contract-users</code>
usar tabela "usuários"

#### Query
<code>
SELECT
	'CU-' ||pk as legacy_id,
	pk AS legacy_user_id,
	CURRENT_DATABASE() as legacy_contract_id,
	CASE administrador WHEN 1 THEN 'owner' ELSE 'user' END as legacy_role_id,
	CASE administrador WHEN 1 THEN true ELSE false END as contract_admin
	FROM usuarios
WHERE email <> 'suporte@integradorcontabil.net.br'
</code>

### Compartilhamento de regras
<code>{{base_url}}/api/v1/migration/rules-sharings</code>

#### query
<code>
SELECT 
	pk AS legacy_id,
	pk AS code,
	nome AS name,
	CURRENT_DATABASE() as legacy_contract_id
FROM plano_contas
WHERE pk <> 0
</code>


### Plano de contas
<code>{{base_url}}/api/v1/migration/plans</code>

#### query
<code>
SELECT 
	pk AS legacy_id,
	pk AS code,
	nome AS name,
	CASE 
		campo_retorno
		WHEN 'Conta Completa' THEN 'COMPLETE'
		ELSE 'REDUCED' END
	 AS account_default
	FROM pcontasconc
</code>

### Layouts
<code>{{base_url}}/api/v1/migration/layouts</code>

#### query
<code>
-- Mapeamento de Migracao: public.layout → Novo Sistema
-- 102 campos destino | Revisado contra DDL real | v4
-- Filtro: visivel = 1

SELECT distinct
    -- Identidade
    layout.pk                                 AS code,
    layout.pk                                 AS legacy_id,
    nome                               AS name,
    CASE setor
	 	WHEN 'C' THEN 'Contábil' ELSE 'Fiscal' END AS sector,
    COALESCE(NULLIF(formato,''), 'PDF')             AS format,
    COALESCE(INITCAP(tipomovimento), 'Ambos')   AS movement_type,
    COALESCE(linha, 1)                 AS start_row,

    -- Colunas de Dados
    numdocumento                       AS num_doc_column,
    parcela                            AS parcel_separator,
    "data"                             AS date_column,
    historico                          AS history_column,
    historicocontinua                  AS history_2_lines_column,
    fornecedor                         AS client_supplier_column,
    cpfcnpj_forn                       AS cpf_cnpj_column,
    dc                                 AS debit_credit_column,
    datavencimento                     AS due_date_column,
    banco                              AS bank_column,
    participante                       AS third_party_participant_column,
    infadicional                       AS additional_information_column,
    infadicional2                      AS complement_column,
    contadeb                           AS debit_account_column,
    contacred                          AS credit_account_column,
    filial                             AS filial_column,
    infadicional2                      AS additional_information_2_column,  -- AMBIGUO: mesmo campo de complement_column
    infadicional3                      AS additional_information_3_column,
    ignorar                            AS ignore_history,
    layout.obs                                AS comments,

    -- Valores
    valor                              AS debit_value_column,
    valor_debito                       AS credit_value_column,  -- AMBIGUO: nome invertido na origem
    juros                              AS interest_column,
    multa                              AS fine_column,
    desconto                           AS discounts_column,
    valordevolucao                     AS refund_values_column,
    valoroutros                        AS other_values_column,

    -- Flags - Consider Previous
    CASE dataanterior
	 	WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS consider_previous_date,
    CASE fornecedoranterior
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END                  AS consider_previous_client_supplier,
    CASE inforadicionalanterior
	 WHEN 1 THEN TRUE 
		 ELSE FALSE END                  AS consider_previous_adicional_information,
    CASE historicoanterior                  
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS consider_previous_history,
    CASE filialanterior                     
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END AS consider_previous_filial,
    CASE bancoanterior                      
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS consider_previous_bank,
    CASE historicosomenteprimeiralinha      
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END AS consider_history_only_fline,

    -- Flags - Layout
    CASE WHEN fk_layout_mestre IS NOT NULL
         THEN true ELSE false
    END                                AS is_default_layout,
    CASE WHEN fk_layout_mestre IS NULL
         THEN true ELSE false
    END                                AS is_layout_master,
    fk_layout_mestre                   AS reference_layout,

    -- Flags - Diversos
    CASE possui_cd_cc
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END                  AS has_d_c_account,
    CASE cpfcnpj_to_part 
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END                  AS copy_third_party_participant_doc,
    CASE solicitarmes
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END                  AS request_month,
    CASE solicitarano                       
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS request_year,
    CASE solicitar_serie                    
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS request_serie,
    CASE solicitar_especie                  
		 WHEN 1 THEN TRUE 
		 ELSE FALSE END 						AS request_especie,
    CURRENT_DATABASE()                AS legacy_contract_id,

    -- Regras Contabeis
    CASE agruparcd
     WHEN 1 THEN TRUE 
		 ELSE FALSE END                          AS consider_dc_for_accounting_rules,
    CASE agrupahistorico
     WHEN 1 THEN TRUE 
		 ELSE FALSE END                          AS consider_history_for_accounting_rules,
    CASE agruparcpfcnpj
     WHEN 1 THEN TRUE 
		 ELSE FALSE END                          AS consider_participant_doc_for_accounting_rules,
    CASE agruparfornecedorconciliacao
     WHEN 1 THEN TRUE 
		 ELSE FALSE END                          AS consider_participant_for_accounting_rules,
    CASE agruparbanco
     WHEN 1 THEN TRUE 
		 ELSE FALSE END                          AS consider_bank_for_accounting_rules,
    CASE agruparfilial
     WHEN 1 THEN TRUE 
		 ELSE FALSE END              AS consider_filial_for_accounting_rules,
    CASE agruparinfadicional
     WHEN 1 THEN TRUE 
		 ELSE FALSE END              AS consider_additional_info_for_accounting_rules,
    FALSE                              AS consider_additional_info_2_for_accounting_rules,
    CASE agruparinfadicional3
     WHEN 1 THEN TRUE 
		 ELSE FALSE END              AS consider_additional_info_3_for_accounting_rules,
    FALSE                              AS consider_rule_extra_for_accounting_rules,

    -- Exportacao / Import
    CASE numdocnohistorico
     WHEN 1 THEN TRUE 
		 ELSE FALSE END AS include_document_number_in_history_when_export,
    CASE historicoremoverquebra
       WHEN 1 THEN TRUE 
		 ELSE FALSE END          AS remove_line_break_in_history_when_export,
    CASE mantervalor
     WHEN 1 THEN TRUE 
		 ELSE FALSE END          AS keep_original_values_when_import,
    parcela_padrao                     AS parcel_separator_when_import,  -- AMBIGUO
    diaadd                             AS day_to_add_when_import,

    -- Exibicao / Interface
    CASE alterar_numerodocumento
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_document_number,  -- AMBIGUO: mesmo campo de change_document_number
    CASE mostrarparcela
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_parcel,
    CASE naoexibir
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS hide_in_list,
    CASE mostrarfornecedor
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_participant,
    CASE mostrarbanco
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_bank,
    CASE editargrid
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS enable_edit_in_grid,
    CASE mostrarfilial
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_filial,
    CASE mostrar_compl
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_complement,
    CASE mostrar_infoadd
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_additional_info_1,
    FALSE             						AS show_additional_info_2,
    CASE mostrar_infoadd3
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS show_additional_info_3,
    CASE alterar_numerodocumento
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS change_document_number,  -- AMBIGUO: mesmo campo de show_document_number
    CASE invertersinal
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS invert_sign,
    CASE imp_lnc_bloqueado
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS import_blocked_entries,
    CASE extrato
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS bank_statement,
    CASE parcela_padrao
          WHEN 1 THEN TRUE 
          ELSE FALSE END               AS bring_1_as_default_parcel,
    COALESCE(confronto_banco, FALSE)	AS confront_consider_banks,

    -- Parametros Personalizados DESCONSIDERAR(RELACIONAMENTO TERNÁRIO)
    --dataparametro                      AS personalized_field_date,
    --valorparametro                     AS personalized_field_value,
    --numdocparametro                    AS personalized_field_document,
    --histparametro                      AS personalized_field_history,

    -- Sem Correspondencia
    NULL                               AS layouts_admin_id,
    NULL                               AS tariff_column,
    NULL                               AS addition_column,
    FALSE                              AS request_password,
    FALSE                              AS request_input,
    FALSE                              AS request_input_title,
    FALSE                              AS participant_marking_enabled,

    -- Impostos (nenhuma correspondencia)
    NULL                               AS pis_column,
    NULL                               AS cofins_column,
    NULL                               AS csll_column,
    NULL                               AS irrf_column,
    NULL                               AS pis_cosirf_column,
    NULL                               AS cofins_cosirf_column,
    NULL                               AS csll_cosirf_column,
    NULL                               AS irpj_cosirf_column,
    NULL                               AS irrfp_column

FROM public.layout
	JOIN layout_empresa ON layout.pk = layout_empresa.fk_layoutimp
WHERE visivel = 1
AND tipo = 'IMP'
ORDER BY layout.pk
</code>

### Emprea
<code>{{base_url}}/api/v1/migration/companies</code>

#### query
<code>
SELECT 
	empresas.pk AS legacy_id,
	pk AS code,
	codigoexterno AS external_code,
	razaosocial as corporate_name,
	REGEXP_REPLACE(cnpj, '[^0-9]', '', 'g') AS cpf_cnpj,
	telefone AS phone,
	endereco AS street,
	numero AS "number",
	complemento AS complement,
	cidade as city,
	cep AS zipcode,
	uf AS "state",
	ramoatividade AS activity_branch,
	telefone AS phone,
	celular AS phone_cell,
	email AS email,
	CURRENT_DATABASE() AS legacy_contract_id,
	CASE 
		WHEN fk_pcontasconc = 0 THEN NULL
		ELSE fk_pcontasconc END 
		AS legacy_plan_id,
	CASE 
		WHEN fk_plano_contas = 0 THEN NULL
		ELSE fk_plano_contas END
		as legacy_rules_sharing_id,
	INITCAP(tributacao) AS tax_regime,
	CASE utilizaparticipante WHEN 1 THEN true ELSE false END as use_participant,
	COALESCE(hab_centrocusto, FALSE) AS use_cost_center,
	COALESCE(auto_pessoa, FALSE) AS use_auto_register_of_people,
	COALESCE(auto_pessoa_wizard, FALSE) AS use_auto_register_of_people_in_rules_accounting
FROM empresas
</code>

### Layout - Emprea
<code>{{base_url}}/api/v1/migration/company-layouts</code>

#### query
<code>
SELECT 
	pk as legacy_id,
	fk_empresa as legacy_company_id,
	fk_layoutimp as legacy_layout_imp,
	fk_layoutexp AS legacy_layout_exp,
	tipo_contab AS type_accounting,
	conta_cred as credit_account,
	conta_deb as debit_account,
	COALESCE(conta_fixa_hab, FALSE) AS account_fixed
FROM layout_empresa
</code>

### Pessoas
<code>{{base_url}}/api/v1/migration/peoples</code>

#### query
<code>
SELECT 
	id AS legacy_id,
	nomerazao AS corporate_name,
	cpfcnpj AS cpf_cnpj,
	CURRENT_DATABASE() AS legacy_contract_id
FROM pessoas
</code>

### Pessoa Vinculos
<code>{{base_url}}/api/v1/migration/people-vinculated</code>

#### Query
<code>
SELECT 
	id AS legacy_id,
	fk_pessoa AS legacy_people_id,
	fk_empresa AS legacy_company_id,
	fk_compartilhamento AS legacy_rules_sharing_id,
	conta_deb AS debit_account,
	conta_cred AS credit_account,
	participante AS participant
FROM pessoas_vinculo
</code>

### Imports
<code>{{base_url}}/api/v1/migration/imports</code>

BATCH SIZE 300

#### Query
<code>
SELECT 
   'IMP-' || fk_layoutempresa AS legacy_id,
    (
        SELECT pk 
        FROM usuarios 
        WHERE administrador = 1 AND pk <> 1 
        LIMIT 1
    ) AS legacy_user_id,
    importacao.fk_empresa as legacy_company_id,
    'completed' AS "status",
    CURRENT_DATABASE() AS legacy_contract_id,
    fk_layoutempresa AS legacy_company_layout_id,
    layout_empresa.saldo_anterior AS previous_balance,
	 COUNT(fk_layoutempresa) > 10000 AS is_big_import,
    1 AS total_files
FROM importacao
	JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
WHERE fk_layout <> 0
GROUP BY importacao.fk_empresa, fk_layoutempresa, layout_empresa.saldo_anterior
ORDER BY fk_layoutempresa
</code>   

### Imports Sessions
<code>{{base_url}}/api/v1/migration/import-sessions</code>

BATCH SIZE 300

#### Query
<code>
SELECT distinct
   'IS-' || fk_layoutempresa AS legacy_id,
   fk_layout AS legacy_layout_id,
   'IMP-' || fk_layoutempresa as legacy_import_id
FROM importacao
	JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
WHERE fk_layout <> 0
</code>

### Regras (async)
<code>{{base_url}}/api/v1/migration/rules</code>

BATCH SIZE 2000

#### Query
<code>
SELECT 
	 regras.id AS legacy_id,
	 layout_empresa.fk_empresa AS legacy_company_id,
	 layout_empresa.fk_layoutimp AS legacy_layout_id,
	 cd AS debit_credit,
	 cpfcnpj AS cpf_cnpj,
	 clifor AS client_supplier,
	 historico AS history,
	 banco AS bank,
	 filial AS filial,
	 infadicional AS additional_information,
	 infadicional_compl AS additional_information_3,
	 token AS token,
	 exclusivo AS "exclusive",
	 idhistorico AS id_history,
	 iddebito AS id_debit,
	 idcredito AS id_credit,
	 historicoexp AS id_history_exp,
	 idcccredito AS id_cc_credit,
	 idccdebito AS id_cc_debit,
	 reprocessar AS reprocess,
	 invalida AS invalid,
	 ROW_NUMBER() OVER (
            PARTITION BY fk_layoutimp
            ORDER BY fk_layoutimp
    ) AS sort_order,
	 historico_imp AS history_value,
	 cpfcnpj_imp AS cpf_cnpj_value,
	 clifor_imp as client_supplier_value,
	 banco_imp as bank_value,
	 filial_imp as filial_value,
	 infadicional_imp as additional_information_value,
	 infadicional_compl_imp as additional_information_3_value,
	 idparticipante AS third_party_participant
FROM regras
	JOIN layout_empresa ON regras.fk_layout_empresa = layout_empresa.pk
WHERE fk_layoutimp <> 0

</code>

### Imports Records
<code>{{base_url}}/api/v1/migration/import-sessions</code>

BATCH SIZE 2000

#### Query
<code>
SELECT
	ip.pk AS legacy_id,
   'IS-' || fk_layoutempresa AS legacy_import_session_id,
   'IMP-' || fk_layoutempresa as legacy_import_id,
   numdocumento as num_doc,
   "data" AS "date",
   historico AS history,
   valor AS "value",
   fornecedor AS client_supplier,
   cd AS debit_credit,
   banco AS bank,
   con_idparticipante AS third_party_participant,
   ip.infadicional AS additional_information,
   ip.complemento AS complement,
   ip.con_iddebito AS debit_account,
   ip.con_idcredito AS credit_account,
   ip.filial AS filial,
   ip.parcela AS parcel,
   ip.historicoexp AS accounting_history,
   ip.cpfcnpj_forn AS cpf_cnpj,
   ip.infadicional3 AS additional_information_3,
   ROW_NUMBER() OVER (
            PARTITION BY fk_layoutempresa
            ORDER BY ip.pk
   ) AS order_numberorder_number,
   ip.cc_debito AS cc_debit,
   ip.cc_credito AS cc_credito,
   ip.desprezado AS not_considered,
   ip.exportado AS was_exported,
   ip.con_idhistorico AS history_code,
   ip.historico_novo AS new_history,
	null as participant_debit,
	null as participant_credit
FROM importacao AS ip
	JOIN layout_empresa ON layout_empresa.pk = fk_layoutempresa
WHERE fk_layout <> 0
ORDER BY fk_layoutempresa
LIMIT 10000
</code>