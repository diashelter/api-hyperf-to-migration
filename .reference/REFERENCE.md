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
        WHEN 'V' THEN 'ATIVO' 
        ELSE 'SUSPENSO' 
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
Campos Disponíveis       | Banco Desktop
|------------------------|-------|
legacy_id                | 'CU-'+pk
legacy_user_id           | pk    
email                    | email
legacy_contract_id       | CURRENT_DATABASE()
status                   | inativo === false //se sim enviar true | se não enviar false
is_admin                 | administrador

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

### Emprea

### query
<code>
SELECT 
	empresas.pk AS legacy_id,
	razaosocial as corporate_name,
	REGEXP_REPLACE(cnpj, '[^0-9]', '', 'g') AS cpf_cnpj,
	email AS email,
	telefone AS phone,
	cidade as city,
	uf AS "state",
	CURRENT_DATABASE() AS legacy_contract_id,
	CASE 
		WHEN fk_pcontasconc = 0 THEN NULL
		ELSE fk_pcontasconc END 
		AS legacy_plan_id,
	CASE 
		WHEN fk_plano_contas = 0 THEN NULL
		ELSE fk_plano_contas END
		as legacy_rules_sharing_id,
	empresas.pk AS code,
	INITCAP(tributacao) AS tax_regime
FROM empresas
</code>

