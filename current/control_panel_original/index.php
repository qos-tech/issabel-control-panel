<?php

function _moduleContent(&$smarty, $module_name)
{
    $html = <<<HTML
<link rel="stylesheet" href="/modules/control_panel/assets/style.css">

<div class="qphone-panel">
    <div class="qphone-header">
        <div>
            <h2>Painel do Operador</h2>
            <p>Mesa compacta de status dos ramais e troncos</p>
        </div>

        <div class="qphone-status">
            <span id="last-update">Carregando...</span>
        </div>
    </div>

    <div id="summary" class="summary"></div>

    <div id="extensions-grid" class="extensions-grid">
        <div class="loading">Carregando dispositivos...</div>
    </div>
</div>

<script src="/modules/control_panel/assets/app.js"></script>
HTML;

    return $html;
}
