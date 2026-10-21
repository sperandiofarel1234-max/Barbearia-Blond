# Barbearia — Sistema de agendamento

Desafio técnico (processo seletivo Skala Code): aplicação web em **Laravel 12 + MySQL** para agendamento de horários em uma barbearia, com área pública para o cliente e área administrativa para o barbeiro.

> Roteiro da apresentação técnica (20 min) e exercício ao vivo: [`docs/ROTEIRO_ENTREVISTA.md`](docs/ROTEIRO_ENTREVISTA.md).

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Como executar do zero](#2-como-executar-do-zero)
3. [Funcionalidades implementadas](#3-funcionalidades-implementadas)
4. [Decisões e suposições](#4-decisões-e-suposições)
5. [Planejado versus entregue](#5-planejado-versus-entregue)
6. [Uso de IA](#6-uso-de-ia)
7. [Limitações e próximos passos](#7-limitações-e-próximos-passos)
8. [Demonstração](#8-demonstração)

---

## 1. Visão geral

**Problema.** Uma barbearia precisa receber agendamentos sem conflito de horários, deixar o cliente cancelar/remarcar sozinho, lembrar o cliente por e-mail e dar ao barbeiro uma agenda diária e um relatório de faturamento.

**Objetivo.** Entregar o fluxo completo, com regras de negócio centralizadas, proteção contra agendamentos simultâneos no mesmo horário, testes automatizados e documentação.

**Tecnologias**

| Item | Uso |
|---|---|
| PHP 8.3 (exige ≥ 8.2) / Laravel 12 | Aplicação, Eloquent, Blade, Scheduler, filas, notificações |
| MySQL 8.0 (InnoDB) | Banco principal; a estratégia de concorrência depende de `SELECT ... FOR UPDATE` |
| Blade + HTML/CSS | Interface responsiva simples, CSS embutido (sem build de front-end, **Node não é necessário**) |
| PHPUnit 11 | 81 testes, rodando em um MySQL real |
| Laravel Pint | Formatação (`php vendor/bin/pint --test` sem pendências) |

Nenhuma biblioteca externa foi adicionada além do que já vem no esqueleto do Laravel. A autenticação administrativa é feita à mão (login por sessão + flag `is_admin`), sem pacotes de autenticação.

---

## 2. Como executar do zero

### 2.1 Pré-requisitos

- PHP **8.2 ou superior** (testado com 8.3.6) com as extensões `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `intl`.
- Composer 2.x (testado com 2.8.12).
- MySQL **8.0** (testado com 8.0.46). MariaDB não foi testado.
- Git.

### 2.2 Clonar e instalar

```bash
git clone <URL-DO-REPOSITORIO> barbearia
cd barbearia
composer install
cp .env.example .env
php artisan key:generate
```

### 2.3 Banco de dados

Crie os bancos (aplicação e testes) e um usuário. Escolha **você mesmo** a senha; ela não faz parte do repositório.

```sql
CREATE DATABASE barbearia      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE barbearia_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'barbearia'@'localhost' IDENTIFIED BY 'DEFINA_UMA_SENHA';
GRANT ALL ON barbearia.*      TO 'barbearia'@'localhost';
GRANT ALL ON barbearia_test.* TO 'barbearia'@'localhost';
FLUSH PRIVILEGES;
```

No `.env`, preencha:

```dotenv
DB_DATABASE=barbearia
DB_USERNAME=barbearia
DB_PASSWORD=DEFINA_UMA_SENHA

# Administrador criado pelo seeder (use uma senha forte e própria)
ADMIN_NAME="Barbeiro"
ADMIN_EMAIL=seu-email@exemplo.com
ADMIN_PASSWORD=DEFINA_UMA_SENHA_FORTE
```

> O `.env` está no `.gitignore`. O `.env.example` mantém `DB_PASSWORD`, `ADMIN_PASSWORD` e `APP_KEY` vazios de propósito.

### 2.4 Migrations, seeders e administrador

```bash
php artisan migrate --seed
```

O seeder cria os 3 serviços iniciais (Corte 40 min R$ 50,00; Barba 20 min R$ 30,00; Corte e barba 60 min R$ 70,00) e o usuário administrador a partir de `ADMIN_*`. Se `ADMIN_EMAIL`/`ADMIN_PASSWORD` estiverem vazios, o seeder **avisa e não cria** o administrador. Rodar de novo atualiza o mesmo usuário (não duplica).

Dados de exemplo para demonstração (opcional, com clientes fictícios `@demo.example.com`):

```bash
php artisan db:seed --class=DemoSeeder
```

### 2.5 Servidor

```bash
php artisan serve          # http://localhost:8000
```

- Cliente: `http://localhost:8000/`
- Barbeiro: `http://localhost:8000/login` (use o e-mail e a senha definidos em `ADMIN_*`)

### 2.6 Scheduler e worker de fila (lembretes)

Os lembretes precisam de **dois** processos em execução:

```bash
php artisan schedule:work   # a cada minuto roda `reminders:dispatch` e enfileira os lembretes vencidos
php artisan queue:work      # processa a fila (QUEUE_CONNECTION=database) e envia os e-mails
```

Sem o `queue:work`, os jobs ficam na tabela `jobs` e nada é enviado. Para executar uma vez, manualmente: `php artisan schedule:run && php artisan queue:work --stop-when-empty`.

### 2.7 E-mail

- **Padrão (desenvolvimento): `MAIL_MAILER=log`.** Nenhum e-mail real sai; a mensagem é gravada em `storage/logs/laravel.log`. Nesse modo, o estado `sent` significa "entregue ao transporte configurado", ou seja, **escrito no log**.
- **Envio real:** configure SMTP no `.env` e reinicie o worker:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.seuprovedor.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_SCHEME=null
MAIL_FROM_ADDRESS="contato@suabarbearia.com.br"
MAIL_FROM_NAME="${APP_NAME}"
```

O envio por SMTP real **não foi testado** neste projeto (ver [limitações](#7-limitações-e-próximos-passos)). Os testes usam o transporte `array` e um transporte que falha de propósito.

### 2.8 Testes

```bash
php artisan test
```

- Usam o banco **`barbearia_test`** (definido em `phpunit.xml`) com as mesmas credenciais do `.env`.
- ⚠️ O teste de concorrência usa `migrate:fresh` nesse banco. **Nunca aponte os testes para um banco com dados reais.**
- O teste de concorrência dispara processos PHP paralelos que usam o relógio real; os demais fixam a data em 02/11/2026 08:00 (America/Sao_Paulo).
- A política de 2h é fixada na base dos testes, portanto o resultado não depende do `BARBEARIA_CANCEL_MIN_HOURS` do seu `.env`.

Resultado da última execução (21/10/2026): **81 testes, 0 falhas, ~400 assertions** (o total de assertions varia em ±2 porque o teste de concorrência percorre uma quantidade de linhas que depende de quais requisições simultâneas vencem). Formatação: `php vendor/bin/pint --test` → `passed`.

> Se `vendor/bin/pint` der "Permission denied", execute por `php vendor/bin/pint` (o problema é apenas o bit de execução do script).

### 2.9 Produção (resumo)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio
```

1. `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache`.
2. **Scheduler**, uma linha no cron do servidor: `* * * * * cd /caminho/barbearia && php artisan schedule:run >> /dev/null 2>&1`
3. **Worker**, mantido vivo pelo Supervisor (ou systemd), reiniciado a cada deploy com `php artisan queue:restart`:

```ini
[program:barbearia-worker]
command=php /caminho/barbearia/artisan queue:work --sleep=3 --tries=1 --max-time=3600
autostart=true
autorestart=true
user=www-data
```

4. Defina `MAIL_MAILER=smtp` e as credenciais. O `.env` de produção fica fora do repositório.
5. Troque a senha do administrador. Não use senhas de demonstração.

Plataformas: Laravel tradicional precisa de PHP-FPM, MySQL, cron e um worker persistente (VPS, Render, Railway, Fly.io etc.). **Vercel não executa diretamente uma aplicação Laravel tradicional com banco, cron e worker**; não foi feita publicação online neste projeto.

---

## 3. Funcionalidades implementadas

Tudo abaixo está implementado **e coberto por testes** (e os fluxos principais foram exercitados também em servidor real).

- [x] **Cadastro de serviços (CRUD):** criar, editar, listar e ativar/desativar. Validação de nome, duração (inteiro positivo) e preço (≥ 0, 2 casas, aceita `50,00`). Serviços nunca são apagados.
- [x] **Agendamento público sem conta:** serviço → data → horário → nome, telefone e e-mail → confirmação com resumo.
- [x] **Horário de funcionamento:** segunda a sábado, 9h–18h (America/Sao_Paulo), grade de 10 em 10 minutos, término exato às 18h aceito, às 18h10 recusado; domingo e datas/horários passados recusados.
- [x] **Prevenção de conflitos:** regra de sobreposição de intervalos, com proteção transacional contra requisições simultâneas.
- [x] **Agenda diária do barbeiro:** por data, ordenada no banco, com cliente, telefone, serviço, duração, horários, preço e status; mensagem quando vazia.
- [x] **Cancelamento pelo cliente:** link com token seguro, política de antecedência, preserva o registro e o histórico, libera o horário, invalida lembretes pendentes.
- [x] **Remarcação pelo cliente:** mesma disponibilidade e política; atômica; preserva preço e duração; registra histórico; recalcula lembretes.
- [x] **Lembretes por e-mail:** 24h e 2h antes (só os que ainda estão no futuro), estados pendente/enviado/falho/cancelado, Scheduler + fila, tentativas e erro registrados, sem duplicidade.
- [x] **Relatório de faturamento diário:** realizado × previsto, cancelamentos, ticket médio, lista dos atendimentos, valores em R$ com preço histórico.
- [x] **Conclusão de atendimento** (ação administrativa) com transições de status validadas.
- [x] **Segurança:** autenticação e autorização administrativas, CSRF, validação no servidor, `$fillable` restrito, escape do Blade, tokens com 256 bits (somente o hash fica pesquisável), throttle em endpoints públicos e no login, 404 idêntico para tokens inválidos, cabeçalhos `no-referrer`/`no-store` na página do token.

### Rotas principais

| Área | Rotas |
|---|---|
| Público | `/` · `/agendar/{serviço}?date=` · `POST /agendar/{serviço}` |
| Gerenciamento (token) | `/agendamento/{token}` · `…/cancelar` · `…/remarcar` |
| Admin | `/login` · `/admin` · `/admin/servicos` · `/admin/agenda?date=` · `/admin/faturamento?date=` · `POST /admin/agendamentos/{id}/concluir` |

### Arquivos importantes

| Arquivo | Responsabilidade |
|---|---|
| `config/barbearia.php` | Fonte única: dias/horários, grade, política de cancelamento, lembretes |
| `app/Services/BusinessHours.php` | Regras de funcionamento e política de antecedência |
| `app/Services/AvailabilityService.php` | Horários livres e a **única** regra de sobreposição |
| `app/Services/BookingService.php` | Criar, remarcar, cancelar, concluir (transações e locks) |
| `app/Services/ReminderService.php` | Programar, despachar e enviar lembretes |
| `app/Services/RevenueReport.php` | Cálculo do faturamento |
| `app/Enums/AppointmentStatus.php` | Status e transições permitidas |
| `app/Jobs/SendReminderJob.php`, `app/Console/Commands/DispatchReminders.php`, `routes/console.php` | Fila e Scheduler |
| `database/migrations/*` | Esquema |
| `tests/Feature/*` | Testes |

---

## 4. Decisões e suposições

**Tecnologias.** Laravel 12 e MySQL 8 conforme o enunciado. Sem pacote de autenticação e sem build de front-end, para manter o projeto simples e fácil de explicar.

**Modelagem.** `services`, `appointments`, `appointment_histories`, `appointment_reminders`, `day_locks` e `users` (com `is_admin`).
- `appointments` guarda um **snapshot** de duração e preço no momento da contratação; mudar o serviço depois não altera o histórico nem o relatório.
- `service_id` usa `restrictOnDelete`: serviços só são desativados.
- Dinheiro em `DECIMAL(10,2)` (cast `decimal:2`, soma e média feitas no MySQL, sem ponto flutuante).
- Data (`DATE`) e horários (`TIME`) são armazenados como horário de parede de America/Sao_Paulo.
- `appointment_histories` registra criação, remarcação (data/hora antiga e nova), cancelamento e conclusão.

**Horário de funcionamento e intervalos.** Regras em `config/barbearia.php`, aplicadas por `BusinessHours` em disponibilidade, criação e remarcação. O backend também exige que o início caia na grade de 10 min, de modo que só são aceitos os horários que a tela oferece. Sem almoço ou feriados (não solicitados).

**Estados e transições.**

```
confirmado ──► cancelado   (terminal; não volta a ocupar a agenda)
confirmado ──► concluído   (terminal; só depois do horário de início)
```

Toda mudança passa por `Appointment::transitionTo()`, que consulta `AppointmentStatus`. Cancelados ficam fora da ocupação; concluídos continuam ocupando.

**Política de cancelamento e remarcação.** Permitidos até **2 horas antes** do início (exatamente 2h ainda é permitido), configurável em `BARBEARIA_CANCEL_MIN_HOURS`. A mesma verificação (`BusinessHours::assertCanChange`) é usada na interface e no backend. Um serviço desativado depois não impede remarcar um agendamento já existente (a regra de "serviço inativo" vale para **novos** agendamentos).

**Estratégia de concorrência.** Travar linhas de agendamentos existentes não impede duas inserções simultâneas num horário vazio. Por isso há uma linha por data em `day_locks`:

1. fora da transação, `INSERT IGNORE` da linha da data;
2. dentro da transação, `SELECT ... FOR UPDATE` nessa linha;
3. com o lock, relê os agendamentos do dia e aplica a regra `inicio_existente < fim_novo E fim_existente > inicio_novo`;
4. grava e faz commit.

Criar e remarcar passam pelo mesmo mecanismo, então operações no mesmo dia são serializadas e a segunda enxerga a primeira. Ordem de travas: agendamento → dia (criar só trava o dia), o que evita deadlock; as transações ainda têm até 3 tentativas. Foi validado com processos PHP paralelos no MySQL e com mutações propositais (remover o lock ou trocar `<` por `<=` faz os testes falharem).

**Token público.** 32 bytes de `random_bytes` (64 hex). A busca usa o SHA-256 do token (coluna única); o token em si fica também **cifrado com `APP_KEY`** (`manage_token`) porque os e-mails precisam montar o link. Não há ID sequencial na rota pública, tokens inválidos retornam o mesmo 404, e há throttle.

**Canal de notificação.** E-mail pelo sistema de Notifications do Laravel (confirmação e lembretes). O enunciado pede que as notificações não exponham tokens e, ao mesmo tempo, tragam um link seguro de gerenciamento. Interpretação adotada: **o link do próprio cliente é enviado somente ao e-mail dele**, sem telefone nem outros dados; o token não é registrado em logs de erro da aplicação nem nas mensagens de falha. Atenção: com `MAIL_MAILER=log`, a mensagem completa (com o link) é gravada no log, então esse modo é apenas para desenvolvimento.

**Lembretes.** Uma linha por (agendamento, tipo) com restrição `UNIQUE`; o job trava a linha e só envia se ainda estiver `pending`, o que evita duplicidade mesmo com várias execuções. Falha: guarda a tentativa e um erro sem e-mail/telefone/token, reagenda para 5 minutos depois e, na 3ª tentativa, marca `failed` (sem reenvio automático depois disso). Lembretes cuja hora já passou não são criados; se o atendimento já começou, o lembrete é cancelado em vez de enviado.

**Critérios de faturamento** (`RevenueReport`):

| Indicador | Definição |
|---|---|
| Data de referência | data do agendamento (não a do cancelamento); remarcar muda a data, então nada é contado duas vezes |
| Faturamento realizado | soma dos preços históricos dos **concluídos** na data |
| Receita prevista | soma dos preços históricos dos **confirmados** (não significa pagamento) |
| Cancelamentos | quantidade de **cancelados** com aquela data; não entram em nenhum valor |
| Ticket médio | realizado ÷ concluídos (só se > 0), arredondado a 2 casas no MySQL |

---

## 5. Planejado versus entregue

**Plano inicial:** as 10 etapas do enunciado (ambiente → banco → serviços → agendamento/concorrência → admin → cancelamento/remarcação → lembretes → faturamento → testes → documentação).

**Concluído:** todas as funcionalidades obrigatórias, incluindo os três itens que o enunciado original trata como opcionais (cancelamento/remarcação, lembretes e faturamento).

**Mudanças de escopo / desvios:**
- O ambiente de desenvolvimento não tinha acesso ao Packagist, então o projeto partiu de um esqueleto Laravel 12 com `vendor/` fornecido (equivalente ao `composer create-project`).
- Foi adicionada a coluna `manage_token` (cifrada) para viabilizar o link nos e-mails (ver decisão acima).
- Foi adicionada a regra "concluir só depois do horário de início", que não estava no enunciado.
- Foram adicionados cabeçalhos `Referrer-Policy`/`Cache-Control` na página do token.

**Não concluído e motivos:**
- **Publicação no GitHub e deploy online:** dependem de decisão/credenciais do candidato; não foram feitos.
- **Envio por SMTP real:** não havia provedor configurado; o fluxo foi validado com `log`/`array` e com um transporte que falha de propósito.
- **Verificação visual em navegador:** a interface foi conferida por requisições HTTP e testes, não por inspeção visual em navegadores/dispositivos.
- **`composer audit`:** não foi executado (acesso ao Packagist bloqueado no ambiente).

---

## 6. Uso de IA

**Ferramenta:** Claude (Anthropic), via Claude Code, modelo Sonnet 5.5, que escreveu a maior parte do código, dos testes e desta documentação.

**Prompts principais realmente utilizados**

1. **Enunciado completo (prompt mestre).** Pedia papel de engenheira sênior em Laravel, escopo completo (com cancelamento/remarcação, lembretes e faturamento tratados como obrigatórios), regras de horário e conflito, concorrência transacional no MySQL, token seguro, testes executados de verdade, README e commits reais, e proibia declarar como feito o que não foi verificado.
2. **"2"**, em resposta à pergunta sobre como seguir quando o Packagist estava bloqueado (opção: eu enviar um esqueleto Laravel já com `vendor/`). Em seguida enviei o `barbearia.7z`.
3. **"Continue sem interromper o trabalho. Resolva a formatação do Laravel Pint da maneira correta, sem ignorar erros. Finalize o README… Prepare também o roteiro da entrevista… Revise a segurança… Não invente resultados nem publique nada no GitHub sem confirmar comigo qual repositório utilizar."**

**Como o resultado foi revisado e testado**
- Os 81 testes rodam contra MySQL real; o teste de concorrência usa processos paralelos.
- **Testes de mutação manuais:** troquei `<`/`>` por `<=`/`>=` na regra de sobreposição e removi o `FOR UPDATE` do lock; em ambos os casos os testes falharam, e o código foi restaurado.
- Fluxo real fora dos testes: servidor `artisan serve`, agendamento via HTTP, `schedule:run` + `queue:work` enviando o lembrete (uma única vez), login e telas administrativas.
- Erros encontrados e corrigidos durante o trabalho: testes que dependiam do `.env`, asserções incorretas, closures na mesma linha no teste de concorrência (limitação do `laravel/serializable-closure`) e e-mail com rodapé em inglês.
- **Cabe ao candidato** ler o código antes de apresentá-lo: o roteiro em `docs/` indica onde olhar para cada decisão.

---

## 7. Limitações e próximos passos

**Limitações reais**
- Existe **uma única agenda** (um barbeiro/cadeira): dois clientes não podem ser atendidos ao mesmo tempo.
- O barbeiro **não cria, cancela nem remarca** agendamentos pela área administrativa (só conclui); cancelamentos fora da política exigem contato e intervenção direta no banco.
- Lembretes só por e-mail, sem WhatsApp/SMS; sem retentativa manual de lembretes `failed`.
- Horários são "de parede" em America/Sao_Paulo; não há tratamento de horário de verão (o Brasil não o adota atualmente).
- Sem recuperação do link por quem o perdeu (o cliente depende do e-mail/página de confirmação).
- Um administrador por vez via seeder; sem tela de gestão de usuários nem recuperação de senha.
- Throttle usa o cache configurado (`database` por padrão); em produção com várias instâncias, use um cache compartilhado (Redis).
- Testado apenas em MySQL 8.0.46 e PHP 8.3; SMTP real, MariaDB e navegadores não foram exercitados.

**Melhorias futuras**
- Mais de um profissional (agenda por profissional) e bloqueios/folgas/feriados.
- Cancelar/remarcar pelo painel, com motivo; notificação ao barbeiro.
- Integração com pagamento (só então "faturamento realizado" poderia significar "pago").
- WhatsApp/SMS, página "esqueci meu link", relatório por período e exportação.
- CI (GitHub Actions) rodando Pint e os testes com serviço MySQL; `composer audit`.

---

## 8. Demonstração

**Dados de exemplo:** `php artisan db:seed --class=DemoSeeder` cria atendimentos de hoje (2 concluídos, 1 cancelado, 1 confirmado) e 2 de amanhã, com clientes fictícios.

**Roteiro rápido**

1. **Agendar:** abra `/`, escolha *Corte*, uma data e um horário, informe nome, telefone e e-mail e confirme. Guarde o endereço da página de resumo (é o link seguro).
2. **Conflito:** em outra aba, tente agendar um horário que se sobreponha ao anterior; o sistema recusa. Horários encostados (fim = início do próximo) são aceitos.
3. **Remarcar e cancelar:** na página do agendamento, remarque para outro horário e depois cancele; volte a `/agendar/{serviço}` e veja o horário liberado.
4. **Barbeiro:** entre em `/login` com as credenciais de `ADMIN_*` do **seu** `.env`; veja a agenda do dia, cadastre/edite/desative um serviço e conclua um atendimento (a ação aparece quando o horário já começou).
5. **Faturamento:** `/admin/faturamento` para a data; confira realizado × previsto e cancelamentos.
6. **Lembretes:** com o agendamento criado, force o vencimento e processe manualmente:

```bash
php artisan tinker --execute="App\Models\AppointmentReminder::query()->update(['remind_at' => now()->subMinute()]);"
php artisan schedule:run && php artisan queue:work --stop-when-empty
grep -a "Subject: Lembrete" storage/logs/laravel.log   # com MAIL_MAILER=log
```

**Acesso administrativo:** o e-mail e a senha são os que você definiu em `ADMIN_EMAIL`/`ADMIN_PASSWORD` no `.env`. Nenhuma credencial é versionada; não use senhas de demonstração fora do ambiente local.
