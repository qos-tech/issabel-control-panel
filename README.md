# Issabel Operator Panel

Painel custom para Issabel/FreePBX que monitora ramais, troncos, chamadas ativas e filas via AMI, com foco em compatibilidade com o Issabel antigo.

## Features

- Monitoramento de ramais SIP, PJSIP e IAX
- Monitoramento de troncos
- Status `online`, `offline`, `busy` e `unknown`
- Detecção de chamadas ativas
- Direção da chamada: entrada, saída e interna
- Tooltip com detalhes da chamada
- Filas via AMI `QueueStatus`
- Nome real das filas via `queues_config`
- Filtro da fila `default`
- Membros logados na fila
- Membros disponíveis, ocupados e pausados
- Chamadas aguardando
- TME e TMA
- Tempo de espera das chamadas aguardando
- Cache e refresh otimizados
- Compatibilidade com PHP antigo

## Requisitos

- Issabel/FreePBX
- Asterisk com AMI habilitado
- PHP compatível com o ambiente do Issabel
- Acesso shell/root para instalação
- Banco MySQL/MariaDB do Issabel para leitura de `devices`, `trunks` e `queues_config`

## Estrutura

```text
backend/
frontend/
install/
docs/
current/control_panel_original/
```

Arquivos principais:

- [backend/api/status.php](backend/api/status.php)
- [backend/lib/](backend/lib/)
- [frontend/index.php](frontend/index.php)
- [frontend/assets/app.js](frontend/assets/app.js)
- [frontend/assets/style.css](frontend/assets/style.css)
- [install/install.sh](install/install.sh)
- [install/uninstall.sh](install/uninstall.sh)

## Instalação Rápida

1. Leve o projeto para o servidor Issabel.
2. Entre na pasta do projeto.
3. Rode o instalador.

```bash
tar -xzf issabel-operator-panel.tar.gz
# ou
unzip issabel-operator-panel.zip
cd /opt/issabel-operator-panel
bash install/install.sh
```

Validação básica:

```bash
php -l /var/www/html/modules/control_panel/api/status.php
find /var/www/html/modules/control_panel/lib -name "*.php" -exec php -l {} \;
curl -skL https://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```

## Atualização

Fluxo recomendado:

```bash
git pull
git status
git add .
git commit -m "Atualiza painel"
bash install/install.sh
```

Se estiver consumindo uma release empacotada, copie a nova versão para o servidor e execute `bash install/install.sh` de novo para sobrescrever os arquivos instalados e recriar o backup automático.

Se houver alteração de assets, revise o cache bust/versionamento no frontend, se aplicável.

## Rollback

O `install.sh` cria backup automático em `/root/control_panel_backup_YYYY-mm-dd_HH-MM-SS/control_panel`.

Restauro manual:

```bash
rm -rf /var/www/html/modules/control_panel
cp -a /root/control_panel_backup_YYYY-mm-dd_HH-MM-SS/control_panel /var/www/html/modules/control_panel
bash install/install.sh
```

Se o projeto estiver em git e for necessário voltar para uma tag:

```bash
git reset --hard TAG
bash install/install.sh
```

## Testes Básicos

```bash
php -l /var/www/html/modules/control_panel/api/status.php
curl -skL https://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
asterisk -rx "queue show"
asterisk -rx "core show channels concise"
```

## Compatibilidade

- PHP antigo do Issabel, com foco em PHP 5.6/7.0+
- Não usa `??`
- Não usa `fn()`
- Não usa destructuring com `[]`
- Não usa typed properties
- Não usa type hints modernos

## Observações Visuais

- Filas aparecem antes de ramais
- A fila `default` é ignorada
- O subtítulo do card da fila mostra o nome real quando disponível
- Membros de fila exibem `display_name` no formato `Ramal 10 - Central <10>`
- O painel é compacto e prioriza leitura rápida

## Roadmap

- Refinar indicadores de tempo por fila
- Ampliar observabilidade do cache
- Melhorar alertas visuais para chamadas e filas críticas
- Adicionar paginação/filtros para ambientes com muitas filas

## Documentação Relacionada

- [docs/README.md](docs/README.md)
- [docs/INSTALL.md](docs/INSTALL.md)
- [docs/API.md](docs/API.md)
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)
- [docs/CHANGELOG.md](docs/CHANGELOG.md)
