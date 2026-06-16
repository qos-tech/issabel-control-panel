# Issabel Operator Panel

Mini projeto para evoluir o módulo `control_panel` do Issabel.

Objetivo inicial:

- Organizar o painel atual em estrutura de projeto.
- Manter compatibilidade com Issabel antigo.
- Preservar o painel atual de ramais/troncos.
- Adicionar filas com membros logados via AMI `QueueStatus`.

## Estrutura

```text
backend/api/status.php          API atual do painel
backend/lib/                    Local para refatoração gradual do backend
backend/config/                 Configurações futuras
frontend/index.php              Entrada do módulo Issabel
frontend/assets/app.js          Frontend atual
frontend/assets/style.css       Estilos atuais
install/install.sh              Instalador no Issabel
install/uninstall.sh            Rollback/restauração simples
current/control_panel_original  Cópia integral do módulo enviado
.codex/prompts/                 Prompts prontos para trabalhar com Codex
```

## Compatibilidade obrigatória

O código precisa rodar em Issabel antigo. Evitar sintaxe moderna:

- Não usar `??`
- Não usar `fn()`
- Não usar destructuring com `[]`
- Não usar typed properties
- Não usar type hints modernos

Preferir PHP compatível com 5.6/7.0.

## Instalação

No servidor Issabel:

```bash
cd /opt
# copie ou clone este projeto para /opt/issabel-operator-panel
cd /opt/issabel-operator-panel
bash install/install.sh
```

O instalador copia para:

```text
/var/www/html/modules/control_panel
```

Antes de sobrescrever, ele cria backup em:

```text
/root/control_panel_backup_YYYY-mm-dd_HH-MM-SS
```

## Próxima tarefa

Abrir o repositório no Codex e usar o prompt:

```text
.codex/prompts/01-refatorar-e-adicionar-filas.md
```
