# Roteiro da entrevista técnica (20 minutos)

Objetivo: explicar o **código real**, as decisões, as limitações e conduzir uma alteração ao vivo, sem inventar respostas. Tudo o que está marcado como *verificado* foi executado durante o desenvolvimento; o que não foi, está marcado como *não ensaiado*.

## Antes de começar (5 min de preparo, fora da entrevista)

```bash
php artisan migrate:fresh --seed          # banco limpo + serviços + admin
php artisan db:seed --class=DemoSeeder     # dados de exemplo (clientes fictícios)
php artisan serve                          # terminal 1
php artisan schedule:work                  # terminal 2 (opcional: demonstrar lembretes)
php artisan queue:work                     # terminal 3 (opcional)
php artisan test                           # confirme que está verde antes de entrar
```

Deixe abertos: a aplicação no navegador, `app/Services/BookingService.php`, `app/Services/AvailabilityService.php`, `config/barbearia.php` e um cliente SQL (ou `mysql`) no banco `barbearia`.

## Linha do tempo

| Min | Tópico | O que mostrar / dizer |
|---|---|---|
| 0–2 | **Problema** | Barbearia que precisa de agendamento sem conflito, autoatendimento (cancelar/remarcar), lembretes e visão financeira. Duas áreas: cliente (sem conta) e barbeiro (login). Os três itens "opcionais" do enunciado foram tratados como obrigatórios. |
| 2–5 | **Demo do agendamento** | `/` → Corte → data → horário → nome/telefone/e-mail → confirmação com resumo. Mostrar que preço/duração/fim vêm do servidor (inspecionar o formulário: só data, hora e dados do cliente são enviados). Mostrar a URL com token longo. |
| 5–7 | **Cadastro de serviços** | `/admin/servicos`: criar com preço `45,50`, tentar duração `0` e preço negativo (mensagens em português), desativar (some da home, histórico intacto). Explicar o snapshot de preço/duração no agendamento. |
| 7–10 | **Prevenção de conflitos** | Tentar sobrepor um horário (recusado) e um encostado (aceito). Abrir `AvailabilityService::overlaps()` (a regra única) e `BookingService::create()` (transação + `day_locks` + `FOR UPDATE`). Explicar por que travar só linhas existentes não basta. Mostrar `tests/Feature/ConcurrencyTest.php` (6 processos disputando o mesmo horário → 1 vence) e citar o teste de mutação. |
| 10–12 | **Cancelar e remarcar** | Na página do token: remarcar (horários livres, sem contar o próprio) e cancelar; horário volta para a lista. Mostrar política de 2h (`BusinessHours::assertCanChange`), remarcação atômica e `appointment_histories`. |
| 12–14 | **Lembretes** | `config/barbearia.php` (24h/2h), `ReminderService`, `routes/console.php`, `SendReminderJob`. Demonstrar: forçar vencimento + `schedule:run` + `queue:work` e mostrar `sent` e o e-mail no log. Explicar `MAIL_MAILER=log` vs SMTP, estados e tentativas. |
| 14–15 | **Faturamento** | `/admin/faturamento`: realizado × previsto, cancelamentos pela data original, ticket médio, preço histórico. Reforçar que "confirmado" ≠ "pago". |
| 15–17 | **Modelagem** | `users`, `services`, `appointments` (snapshot, hash do token), `appointment_histories`, `appointment_reminders` (`UNIQUE appointment_id+type`), `day_locks` (mutex por data). |
| 17–18 | **Testes e planejado × entregue** | `php artisan test` → 81 testes, 0 falhas (resultado real da última execução). Dizer o que **não** foi feito (README §5). |
| 18–20 | **Alteração ao vivo** | Exercício A abaixo (ensaiado). |

## Exercício ao vivo A — "O sábado fecha às 14h" (verificado)

**Pedido do entrevistador:** *aos sábados a barbearia deve fechar às 14h, sem mexer nos demais dias.*

**Arquivos que mudam (3):**

1. `config/barbearia.php`: acrescentar uma exceção por dia da semana ISO (6 = sábado):
   ```php
   'closing_time_overrides' => [6 => '14:00'],
   ```
2. `app/Services/BusinessHours.php`, método `closesAt()`: usar a exceção quando existir.
   ```php
   $time = config('barbearia.closing_time_overrides')[$date->dayOfWeekIso] ?? config('barbearia.closing_time');

   return CarbonImmutable::parse($date->format('Y-m-d').' '.$time, $this->timezone());
   ```
3. `tests/Feature/BookingTest.php`: novo teste.
   ```php
   public function test_sabado_fecha_as_14h(): void
   {
       $service = $this->service(40);
       $this->assertSame('14:00:00', $this->book($service, '2026-11-07', '13:20')->end_time);
       $this->assertRejected(fn () => $this->book($service, '2026-11-07', '13:30'), 'fechamento (14:00)');
       $this->assertNotContains('13:30', app(AvailabilityService::class)->slots(40, CarbonImmutable::parse('2026-11-07', 'America/Sao_Paulo')));
   }
   ```

**Por que só isso muda:** `closesAt()` é consumido por `assertBookable()` (validação no backend) e por `candidateStarts()` (lista de horários). Como as duas usam o mesmo método, a tela e o backend continuam coerentes, e remarcação herda a regra automaticamente.

**Como validar:**
```bash
php artisan test --filter=BookingTest     # teste novo + os existentes
php vendor/bin/pint --test
php artisan test                           # suíte completa
```
**Resultado do ensaio:** Pint `passed` e suíte completa com **82 testes passando** (81 + o novo). O ensaio foi feito numa branch descartável; o projeto entregue **não** contém essa alteração.

## Exercício B — "Mudar a política de cancelamento para 4h" (verificado)

- **Código:** nenhuma alteração. Basta `BARBEARIA_CANCEL_MIN_HOURS=4` no `.env` (e reiniciar o servidor/worker, se usar `config:cache`, rodar `php artisan config:clear`). A página do token passa a mostrar "4 hora(s)".
- **Testes:** a base de testes (`tests/TestCase.php`) fixa a política em 2h (`CANCEL_MIN_HOURS = 2`), então rodar com o `.env` em 4h **não** quebra nada (verificado: 81 passando).
- **Para demonstrar que os testes "enxergam" a regra:** mude a constante `CANCEL_MIN_HOURS` para `4` e rode `php artisan test --filter=CancellationTest`. No ensaio, falhou exatamente **1** teste: *"cancelamento com exatamente duas horas de antecedência é permitido"*, porque ele usa um atendimento a 2h de distância. Isso mostra o limite exato da regra.
- Arquivos envolvidos: `config/barbearia.php` (lê o env), `app/Services/BusinessHours.php` (`assertCanChange`), `tests/TestCase.php`, `tests/Feature/CancellationTest.php`, `tests/Feature/RescheduleTest.php`.

## Outras alterações possíveis (não ensaiadas)

Se o entrevistador pedir algo diferente, estes são os pontos de partida, sem garantia de resultado até serem executados:

| Pedido | Onde mexer | Testes a rodar |
|---|---|---|
| Aceitar telefone fixo/celular só com DDD específico | `BookingRequest::rules()` | `BookingTest` |
| Lembrete adicional de 1 dia e meio | `config/barbearia.php` → `reminders` | `ReminderTest` |
| Almoço bloqueado (12h–13h) | `BusinessHours::candidateStarts()` e `assertBookable()`, e a regra de conflito | `BookingTest`, `RescheduleTest` |
| Relatório por período | `RevenueReport` + `ReportController` + view | `AgendaAndReportTest` |

## Perguntas prováveis e respostas honestas

- **Por que `day_locks` e não só `lockForUpdate` nos agendamentos?** Se ainda não existe agendamento no horário, não há linha para travar; duas transações poderiam inserir ao mesmo tempo. A linha por data é um mutex que existe sempre.
- **E se o MySQL reportar deadlock?** Criar só trava o dia; remarcar trava agendamento → dia; a ordem é fixa e as transações têm 3 tentativas. Nos testes paralelos não houve erros inesperados.
- **Por que o token tem hash e também cifrado?** O hash serve para busca sem expor o token; a versão cifrada existe só para montar o link do e-mail. Trade-off assumido e documentado.
- **O e-mail com link não contradiz "não expor tokens"?** É a interpretação adotada (README §4): o link só vai para o e-mail do próprio cliente e o token não vai para logs de erro; com `MAIL_MAILER=log` ele aparece no log, por isso é modo de desenvolvimento.
- **O que garante que o lembrete não sai duas vezes?** `UNIQUE(appointment_id, type)` + lock da linha no envio + só envia se `pending` (teste com várias execuções).
- **O que o relatório chama de receita?** Realizado = concluídos; previsto = confirmados; nada afirma pagamento.
- **O que falta?** Publicação, SMTP real, verificação visual em navegadores, mais de um profissional, cancelamento pelo painel (README §5 e §7).
- **Quanto foi feito com IA?** Grande parte do código e dos testes; revisão com testes de mutação e execução real (README §6). Esteja pronto para abrir qualquer arquivo e explicá-lo.

## Checklist final antes da entrevista

- [ ] `php artisan test` verde na sua máquina (e `php vendor/bin/pint --test`).
- [ ] `ADMIN_*` e `DB_*` definidos por você no `.env`; nada sensível no repositório.
- [ ] MySQL em execução; `schedule:work` e `queue:work` abertos se for demonstrar lembretes.
- [ ] Você leu `BookingService`, `AvailabilityService`, `ReminderService` e `RevenueReport` e sabe explicar cada decisão.
