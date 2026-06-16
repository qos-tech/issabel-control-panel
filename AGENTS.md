# Instruções para Codex

Este projeto é um módulo para Issabel/FreePBX chamado `control_panel`.

## Regras obrigatórias

1. Manter compatibilidade com PHP antigo do Issabel.
2. Não usar operador `??`.
3. Não usar arrow functions `fn()`.
4. Não usar destructuring com `[]`.
5. Não usar typed properties.
6. Não quebrar o JSON atual da API.
7. Preservar as chaves existentes:
   - `success`
   - `extensions`
   - `trunks`
   - `unknown`
   - `summary`
8. Adicionar filas em uma nova chave `queues`.
9. Não alterar a forma atual de obter senha AMI:

```php
require_once "/var/www/html/libs/misc.lib.php";
$amiPassword = obtenerClaveAMIAdmin("/var/www/html/");
```

10. Manter cache curto em `/tmp/control_panel_status_cache.json`.

## Estilo de código

- Funções simples.
- Classes simples somente quando ajudar a separar responsabilidade.
- Sem dependências externas.
- Sem Composer obrigatório.
- Código tolerante a erro: se filas falharem, o painel de ramais/troncos deve continuar funcionando.

## Testes manuais esperados

No servidor Issabel:

```bash
php -l /var/www/html/modules/control_panel/api/status.php
curl -s http://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
asterisk -rx "queue show"
```
