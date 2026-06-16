var statusRequestInFlight = false;

function loadStatus() {
    if (statusRequestInFlight) {
        return;
    }

    statusRequestInFlight = true;

    var grid = document.getElementById('extensions-grid');
    var lastUpdate = document.getElementById('last-update');
    var summary = document.getElementById('summary');

    fetch('/modules/control_panel/api/status.php?_=' + Date.now())
        .then(function (response) {
            return response.text();
        })
        .then(function (text) {
            var data = null;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('resposta JSON invalida');
            }

            if (!data || !data.success) {
                var errorMessage = data && data.error ? data.error : 'falha desconhecida';
                grid.innerHTML = '<div class="loading">Erro: ' + escapeHtml(errorMessage) + '</div>';
                return;
            }

            renderPanel(data, grid, summary, lastUpdate);
        })
        .catch(function (error) {
            grid.innerHTML = '<div class="loading">Erro ao carregar painel: ' + escapeHtml(error.message) + '</div>';
        })
        .then(function () {
            statusRequestInFlight = false;
        });
}

function renderPanel(data, grid, summary, lastUpdate) {
    var extensions = ensureArray(data.extensions);
    var trunks = ensureArray(data.trunks);
    var unknown = ensureArray(data.unknown);
    var queues = ensureArray(data.queues).filter(function (queue) {
        return !isDefaultQueue(queue);
    });

    var totalExtensions = extensions.length;
    var online = countByStatus(extensions, 'online');
    var busy = countByStatus(extensions, 'busy');
    var ringing = countByStatus(extensions, 'ringing');
    var offline = countByStatus(extensions, 'offline');
    var unknownCount = countByStatus(extensions, 'unknown');

    summary.innerHTML =
        '<div class="summary-card"><strong>' + totalExtensions + '</strong><span>Ramais</span></div>' +
        '<div class="summary-card"><strong>' + online + '</strong><span>Livre</span></div>' +
        '<div class="summary-card"><strong>' + busy + '</strong><span>Ocupado</span></div>' +
        '<div class="summary-card"><strong>' + ringing + '</strong><span>Tocando</span></div>' +
        '<div class="summary-card"><strong>' + offline + '</strong><span>Offline</span></div>' +
        '<div class="summary-card"><strong>' + unknownCount + '</strong><span>Desc.</span></div>' +
        '<div class="summary-card"><strong>' + trunks.length + '</strong><span>Troncos</span></div>' +
        '<div class="summary-card"><strong>' + queues.length + '</strong><span>Filas</span></div>';

    var html = '';

    html += renderQueuesSection(queues);
    html += renderSection('Ramais', extensions);

    if (trunks.length > 0) {
        html += renderSection('Troncos', trunks);
    }

    if (unknown.length > 0) {
        html += renderSection('Desconhecidos', unknown);
    }

    if (html === '') {
        html = '<div class="loading">Nenhum dispositivo encontrado.</div>';
    }

    grid.innerHTML = html;
    lastUpdate.innerHTML = 'Atualizado em ' + new Date().toLocaleTimeString();
}

function renderSection(title, items) {
    if (!items || items.length === 0) {
        return '';
    }

    var cards = items.map(function (item) {
        return renderCard(item);
    }).join('');

    return '' +
        '<div class="device-section">' +
            '<h3>' + escapeHtml(title) + '</h3>' +
            '<div class="device-grid">' + cards + '</div>' +
        '</div>';
}

function renderCard(item) {
    var cardClass = getCardClass(item);
    var label = getStatusLabel(item);
    var name = item && item.name ? item.name : (item && item.extension ? item.extension : '-');
    var description = item && item.label && item.label !== name ? item.label : '';
    var tech = item && item.tech ? item.tech : '';
    var contactStatus = item && item.contact_status ? item.contact_status : '';
    var deviceState = item && item.device_state ? item.device_state : '';
    var activeChannels = item && item.active_channels ? item.active_channels : '0';
    var directionLabel = item && item.direction_label ? item.direction_label : '';
    var otherParty = item && item.other_party ? item.other_party : '';
    var calleridNum = item && item.callerid_num ? item.callerid_num : '';
    var calleridName = item && item.callerid_name ? item.callerid_name : '';
    var connectedLineNum = item && item.connected_line_num ? item.connected_line_num : '';
    var connectedLineName = item && item.connected_line_name ? item.connected_line_name : '';
    var currentExten = item && item.current_exten ? item.current_exten : '';
    var currentContext = item && item.current_context ? item.current_context : '';
    var currentApplication = item && item.current_application ? item.current_application : '';

    var titleParts = [
        'Dispositivo: ' + name,
        tech ? 'Tecnologia: ' + tech : '',
        description ? 'Descricao: ' + description : '',
        'Status: ' + label,
        directionLabel && cardClass === 'busy' ? 'Direcao: ' + directionLabel : '',
        otherParty && cardClass === 'busy' ? 'Outra ponta: ' + otherParty : '',
        calleridNum ? 'CallerID: ' + (calleridName ? calleridName + ' ' : '') + calleridNum : '',
        connectedLineNum ? 'Connected Line: ' + (connectedLineName ? connectedLineName + ' ' : '') + connectedLineNum : '',
        currentExten ? 'Exten atual: ' + currentExten : '',
        currentContext ? 'Contexto: ' + currentContext : '',
        currentApplication ? 'Aplicacao: ' + currentApplication : '',
        deviceState ? 'Device State: ' + deviceState : '',
        contactStatus ? 'Contato: ' + contactStatus : '',
        'Canais ativos: ' + activeChannels
    ].filter(function (part) {
        return !!part;
    });

    return '' +
        '<div class="extension-card ' + escapeHtml(cardClass) + '" title="' + escapeHtml(titleParts.join(' | ')) + '">' +
            '<div class="extension-number">' + escapeHtml(name) + '</div>' +
            '<div class="extension-label">' + escapeHtml(label) + '</div>' +
        '</div>';
}

function renderQueuesSection(queues) {
    if (!queues || queues.length === 0) {
        return '';
    }

    var cards = queues.map(function (queue) {
        return renderQueueCard(queue);
    }).join('');

    return '' +
        '<div class="device-section queue-section">' +
            '<h3>Filas</h3>' +
            '<div class="queue-grid">' + cards + '</div>' +
        '</div>';
}

function renderQueueCard(queue) {
    var queueId = queue && queue.queue ? queue.queue : '-';
    var queueName = queue && queue.name && queue.name !== queueId ? queue.name : '';
    var waiting = toInt(queue && queue.calls);
    var membersTotal = toInt(queue && queue.members_total);
    var membersAvailable = toInt(queue && queue.members_available);
    var membersBusy = toInt(queue && queue.members_busy);
    var membersPaused = toInt(queue && queue.members_paused);
    var members = ensureArray(queue && queue.members);
    var entries = ensureArray(queue && queue.entries);
    var memberList = members.map(function (member) {
        return renderQueueMember(member);
    }).join('');
    var titleParts = [
        'Fila: ' + queueId,
        queueName ? 'Nome: ' + queueName : '',
        'Estrategia: ' + (queue && queue.strategy ? queue.strategy : ''),
        'Chamadas aguardando: ' + waiting,
        'Membros logados: ' + membersTotal,
        'Livres: ' + membersAvailable,
        'Ocupados: ' + membersBusy,
        'Pausados: ' + membersPaused,
        'Entradas aguardando: ' + entries.length
    ].filter(function (part) {
        return !!part;
    });

    return '' +
        '<div class="queue-card" title="' + escapeHtml(titleParts.join(' | ')) + '">' +
            '<div class="queue-card-header">' +
                '<div class="queue-title">Fila ' + escapeHtml(queueId) + '</div>' +
                '<div class="queue-calls">Aguardando: ' + waiting + '</div>' +
            '</div>' +
            '<div class="queue-subtitle">' + escapeHtml(queueName || 'Fila') + '</div>' +
            '<div class="queue-stats">' +
                '<span>Logados: ' + membersTotal + '</span>' +
                '<span>Livres: ' + membersAvailable + '</span>' +
                '<span>Ocupados: ' + membersBusy + '</span>' +
                '<span>Pausados: ' + membersPaused + '</span>' +
            '</div>' +
            '<div class="queue-members">' + memberList + '</div>' +
        '</div>';
}

function renderQueueMember(member) {
    var name = getQueueMemberLabel(member);
    var status = member && member.status ? member.status : 'Desconhecido';
    var statusClass = getQueueMemberClass(member);
    var titleParts = [
        'Membro: ' + name,
        member && member.location ? 'Local: ' + member.location : '',
        member && member.extension ? 'Ramal: ' + member.extension : '',
        'Status: ' + status,
        'Atendidas: ' + (member && member.calls_taken ? member.calls_taken : '0'),
        'Ultima chamada: ' + (member && member.last_call ? member.last_call : '0')
    ].filter(function (part) {
        return !!part;
    });

    return '' +
        '<span class="queue-member ' + escapeHtml(statusClass) + '" title="' + escapeHtml(titleParts.join(' | ')) + '">' +
            escapeHtml(name) +
        '</span>';
}

function getQueueMemberLabel(member) {
    if (member && member.display_name) {
        return member.display_name;
    }

    if (member && member.extension) {
        return member.extension;
    }

    if (member && member.location) {
        return member.location;
    }

    return '-';
}

function getQueueMemberClass(member) {
    if (!member) {
        return 'unknown';
    }

    if (member.paused === '1') {
        return 'paused';
    }

    if (member.in_call === '1') {
        return 'busy';
    }

    if (member.status_code === '1') {
        return 'online';
    }

    if (member.status_code === '2' || member.status_code === '3' || member.status_code === '7') {
        return 'busy';
    }

    return 'unknown';
}

function countByStatus(items, statusName) {
    return items.filter(function (item) {
        return getCardClass(item) === statusName;
    }).length;
}

function getCardClass(item) {
    var status = String(item && item.status ? item.status : '').toLowerCase();
    var state = String(item && item.device_state ? item.device_state : '').toLowerCase();
    var contact = String(item && item.contact_status ? item.contact_status : '').toLowerCase();
    var activeChannels = Number(item && item.active_channels ? item.active_channels : 0);

    if (status === 'ringing') {
        return 'ringing';
    }

    if (status === 'busy') {
        return 'busy';
    }

    if (status === 'online') {
        return 'online';
    }

    if (status === 'offline') {
        return 'offline';
    }

    if (state.indexOf('ring') !== -1) {
        return 'ringing';
    }

    if (state.indexOf('not in use') !== -1) {
        if (contact === 'unreachable' || contact === 'unknown') {
            return 'offline';
        }

        return 'online';
    }

    if (state.indexOf('busy') !== -1 || state.indexOf('in use') !== -1 || activeChannels > 0) {
        return 'busy';
    }

    if (contact === 'reachable' || contact === 'nonqualified' || contact.indexOf('ok') !== -1) {
        return 'online';
    }

    if (contact === 'unreachable' || contact === 'unknown') {
        return 'offline';
    }

    return 'unknown';
}

function getStatusLabel(item) {
    var cardClass = getCardClass(item);

    if (cardClass === 'ringing') {
        return 'Tocando';
    }

    if (cardClass === 'busy') {
        if (item && item.direction_label) {
            return item.direction_label;
        }

        return 'Ocupado';
    }

    if (cardClass === 'online') {
        return 'Livre';
    }

    if (cardClass === 'offline') {
        return 'Offline';
    }

    return 'Desc.';
}

function ensureArray(value) {
    return Array.isArray(value) ? value : [];
}

function isDefaultQueue(queue) {
    var queueId = String(queue && queue.queue ? queue.queue : '').toLowerCase();
    var queueName = String(queue && queue.name ? queue.name : '').toLowerCase();

    return queueId === 'default' || queueName === 'default';
}

function toInt(value) {
    var parsed = parseInt(value, 10);

    return isNaN(parsed) ? 0 : parsed;
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

loadStatus();
setInterval(loadStatus, 2000);
