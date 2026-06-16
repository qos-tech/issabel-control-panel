# Control Panel

Mini projeto do modulo `control_panel` para Issabel/FreePBX, mantendo o endpoint atual e adicionando monitoramento de filas via AMI `QueueStatus`.

- A fila `default` e ignorada no backend e no frontend
- Membros de fila sao exibidos preferencialmente pelo `CallerID Name` do ramal, via `AMPUSER/<ramal>/cidname`

## Compatibilidade

- PHP antigo do Issabel, com foco em PHP 5.6/7.0+
- Sem `??`, `fn()`, destructuring com `[]`, typed properties ou recursos modernos obrigatorios
- Sem dependencias externas

## Instalacao

No servidor Issabel:

```bash
cd /opt/issabel-operator-panel
bash install/install.sh
```

O instalador:

- cria backup automatico de `/var/www/html/modules/control_panel` se ele existir
- copia `frontend/index.php`
- copia `frontend/assets`
- copia `backend/api`
- copia `backend/lib`
- ajusta ownership para `asterisk` ou `apache`, quando existir

## Atualizacao

Para atualizar uma instalacao existente:

```bash
cd /opt/issabel-operator-panel
git pull
bash install/install.sh
```

O backup anterior continua salvo em `/root/control_panel_backup_DATA_HORA`.

## Rollback

Escolha um backup criado pelo instalador e restaure:

```bash
rm -rf /var/www/html/modules/control_panel
cp -a /root/control_panel_backup_DATA_HORA/control_panel /var/www/html/modules/control_panel
chown -R asterisk:asterisk /var/www/html/modules/control_panel 2>/dev/null || chown -R apache:apache /var/www/html/modules/control_panel
```

## Teste da API

Validacoes manuais esperadas no servidor Issabel:

```bash
php -l /var/www/html/modules/control_panel/api/status.php
curl -s http://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```

## Teste do AMI QueueStatus

Conferir se o Asterisk responde com dados de filas:

```bash
asterisk -rx "queue show"
```

Depois validar se o endpoint retorna a chave `queues`:

```bash
curl -s http://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```

Observacoes:

- a fila `default` nao deve aparecer no JSON nem no painel
- membros devem aparecer como `Ramal 20 - Nome`, com fallback para o numero do ramal

## Desinstalacao

```bash
bash install/uninstall.sh
```

O script cria um backup em `/root/control_panel_removed_DATA_HORA/control_panel` antes de remover o modulo.
