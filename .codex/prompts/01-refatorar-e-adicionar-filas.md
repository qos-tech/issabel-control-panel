# Tarefa Codex 01 — Refatorar painel e adicionar filas

Tenho um módulo atual do Issabel em `frontend/` e `backend/api/status.php` que monitora ramais/troncos via AMI. Quero transformar isso em um mini projeto organizado, mantendo compatibilidade com Issabel antigo.

## Objetivo

Refatorar gradualmente o painel atual, sem quebrar o comportamento existente, e adicionar uma seção de filas com membros logados usando AMI `QueueStatus`.

## Requisitos obrigatórios

1. Manter compatibilidade com PHP 5.6/7.0+.
2. Não usar:
   - operador `??`
   - arrow functions `fn()`
   - destructuring com `[]`
   - typed properties
   - type hints modernos
3. Preservar o JSON atual:
   - `success`
   - `extensions`
   - `trunks`
   - `unknown`
   - `summary`
4. Adicionar uma nova chave no JSON:
   - `queues`
5. Coletar filas usando AMI `QueueStatus`.
6. Em `queues`, retornar:
   - `queue`
   - `name`
   - `strategy`
   - `calls`
   - `holdtime`
   - `completed`
   - `abandoned`
   - `members_total`
   - `members_available`
   - `members_busy`
   - `members_paused`
   - `members`
   - `entries`
7. Para cada membro da fila, retornar:
   - `name`
   - `location`
   - `membership`
   - `penalty`
   - `calls_taken`
   - `last_call`
   - `last_pause`
   - `paused`
   - `in_call`
   - `status_code`
   - `status`
8. Para chamadas aguardando na fila, retornar:
   - `position`
   - `channel`
   - `callerid_num`
   - `callerid_name`
   - `wait`
9. Criar frontend para exibir uma seção `Filas` abaixo dos ramais/troncos.
10. Cada fila deve mostrar:
   - número/nome da fila
   - chamadas aguardando
   - membros logados
   - livres
   - ocupados
   - pausados
   - lista compacta dos membros
11. Manter layout compacto, parecido com o painel atual.
12. Não quebrar o instalador `install/install.sh`.
13. Se a coleta de filas falhar, retornar `queues: []` e manter ramais/troncos funcionando.

## Observações do painel atual

O painel usa AMI local em `127.0.0.1:5038` e obtém a senha com:

```php
require_once "/var/www/html/libs/misc.lib.php";
$amiPassword = obtenerClaveAMIAdmin("/var/www/html/");
```

Mantenha essa lógica.

Também manter cache curto de 2 segundos em:

```text
/tmp/control_panel_status_cache.json
```

## Entrega esperada

- Código PHP válido com `php -l`.
- Frontend renderizando filas quando `queues` existir.
- README atualizado se necessário.
- Sem dependências externas.
