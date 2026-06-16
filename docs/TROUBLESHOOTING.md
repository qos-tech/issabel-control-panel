# Troubleshooting

## 1. `curl` retorna `301 Moved Permanently`

Use `https` com `-kL`:

```bash
curl -skL https://127.0.0.1/modules/control_panel/api/status.php
```

## 2. `python -m json.tool` diz que nao conseguiu ler JSON

Causa provavel: HTML, erro PHP ou redirect.

```bash
curl -skLi https://127.0.0.1/modules/control_panel/api/status.php
tail -n 100 /var/log/httpd/error_log
```

## 3. Painel nao atualiza visualmente

Provavel cache do navegador.

Solucoes:

- atualizar a versao dos assets em `app.js?v=...` e `style.css?v=...`
- usar `Ctrl+F5`
- testar em aba anônima

## 4. API tem `queues` mas o painel nao mostra

Verifique se o frontend ainda referencia a chave `queues`:

```bash
grep -RIn "queues" /var/www/html/modules/control_panel/assets/app.js
```

Confira o console do navegador.

## 5. Nome da fila aparece como numero

Causa provavel: `queues_config` sem `descr` ou falha na leitura do banco.

```bash
mysql -u root -p -e "USE asterisk; SELECT extension, descr FROM queues_config;"
```

## 6. Fila `default` aparece

Verifique se o filtro permanece ativo no backend e no frontend.

## 7. Membro aparece como `Local/20@from-internal/n`

Confira a extração do ramal e o `AMPUSER/<ramal>/cidname`.

## 8. `PHP syntax error`

Rode o lint:

```bash
php -l /var/www/html/modules/control_panel/api/status.php
find /var/www/html/modules/control_panel/lib -name "*.php" -exec php -l {} \;
```

## 9. AMI nao responde

```bash
asterisk -rx "manager show settings"
asterisk -rx "queue show"
```

## Comandos uteis

```bash
asterisk -rx "queue show"
asterisk -rx "core show channels concise"
asterisk -rx "pjsip show endpoints"
asterisk -rx "sip show peers"
asterisk -rx "iax2 show peers"
curl -skL https://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```
