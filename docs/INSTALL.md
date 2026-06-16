# Instalacao

## Requisitos

- Issabel/FreePBX
- Asterisk com AMI habilitado
- PHP compatível com o ambiente do Issabel
- Acesso shell/root
- Banco MySQL/MariaDB do Issabel

## Instalacao rapida

```bash
tar -xzf issabel-operator-panel.tar.gz
# ou
unzip issabel-operator-panel.zip
cd /opt/issabel-operator-panel
bash install/install.sh
```

O instalador:

- faz backup automatico de `/var/www/html/modules/control_panel` se ele existir
- copia `frontend/index.php` para o modulo
- copia `frontend/assets`
- copia `backend/api`
- copia `backend/lib`
- ajusta permissões para `asterisk` ou `apache`, quando possivel

## Validacao

```bash
php -l /var/www/html/modules/control_panel/api/status.php
find /var/www/html/modules/control_panel/lib -name "*.php" -exec php -l {} \;
curl -skL https://127.0.0.1/modules/control_panel/api/status.php | python -m json.tool
```

## Atualizacao

```bash
git pull
git status
bash install/install.sh
```

Se houver assets com cache forte no navegador, atualize o versionamento no HTML/JS ou faça um hard refresh.

## Rollback

Use o backup criado pelo instalador:

```bash
rm -rf /var/www/html/modules/control_panel
cp -a /root/control_panel_backup_YYYY-mm-dd_HH-MM-SS/control_panel /var/www/html/modules/control_panel
bash install/install.sh
```

Se o projeto estiver versionado em git, também é possível voltar a uma tag:

```bash
git reset --hard TAG
bash install/install.sh
```

## Desinstalacao

```bash
bash install/uninstall.sh
```
