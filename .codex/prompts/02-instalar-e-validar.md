# Tarefa Codex 02 — Validar instalação no Issabel

Revise os scripts `install/install.sh` e `install/uninstall.sh`.

Garanta que:

1. O instalador faça backup antes de sobrescrever.
2. Copie os arquivos corretos para `/var/www/html/modules/control_panel`.
3. Execute `php -l` nos arquivos PHP instalados.
4. Não dependa de Composer, npm ou internet.
5. Mantenha permissões compatíveis com Issabel.
6. O rollback esteja documentado em `docs/README.md`.
