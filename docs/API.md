# API

Endpoint principal:

```text
/modules/control_panel/api/status.php
```

Resposta de sucesso:

- `success`
- `extensions`
- `trunks`
- `unknown`
- `summary`
- `queues`

Campos adicionais em `summary`:

- `generated_at`
- `elapsed_ms`
- `cache_hit`

## Exemplo de validacao

```bash
curl -skL https://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```

## Observacoes

- A fila `default` e filtrada pelo backend e pelo frontend
- O nome real da fila vem de `queues_config`
- Membros de fila incluem `display_name` e `extension`
- Tempo de espera e TME/TMA sao enviados em segundos e em formato legivel
