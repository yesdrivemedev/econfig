// Configuration centralisee du front

// Le navigateur n'appelle jamais l'api/ directement (X-API-Key resterait secrete
// cote serveur) : tous les appels passent par proxy.php.
// PROXY_URL est defini par includes/layout_footer.php (relatif a $basePath de la
// page courante) avant le chargement de ce script ; le repli ci-dessous ne sert
// que si ce script est charge en dehors du layout commun.
var PROXY_URL = (typeof PROXY_URL !== 'undefined') ? PROXY_URL : 'proxy.php';

// extrnl_rateplan_id par type d'offre mobile (seul HYBRIDE repond pour l'instant)
var EXTRNL_RATEPLAN_ID = {
    POSTPAYEE: 1,
    HYBRIDE: 3,
    PREPAYEE: 6
};

// Echappement HTML avant insertion via innerHTML/template strings
function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Appelle proxy.php pour un endpoint api/ donne (ex: 'mobile/create_offre.php').
// method='GET'  : payload est envoye en query string.
// method='POST' : payload est envoye en corps JSON.
function callApi(endpoint, method, payload) {
    var url = PROXY_URL + '?endpoint=' + encodeURIComponent(endpoint);
    var options = { method: method, headers: {} };

    if (method === 'GET') {
        if (payload) {
            var qs = new URLSearchParams(payload).toString();
            if (qs) url += '&' + qs;
        }
    } else if (payload !== undefined && payload !== null) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
    }

    return fetch(url, options).then(function(response) {
        return response.text().then(function(text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                var snippet = text.substring(0, 200).trim();
                throw new Error('Reponse non-JSON (HTTP ' + response.status + ') : ' + (snippet || '(vide)'));
            }
            return { ok: response.ok, status: response.status, data: data };
        });
    });
}

// Convertit une valeur <input type="date"> (yyyy-mm-dd) en dd-mm-yyyy
function toDdMmYyyy(dateValue) {
    var parts = dateValue.split('-');
    return parts[2] + '-' + parts[1] + '-' + parts[0];
}

// Convertit une valeur <input type="datetime-local"> (yyyy-mm-ddThh:mm) en dd-mm-yyyy hh:mm:ss
function toDdMmYyyyHms(datetimeValue) {
    var parts = datetimeValue.split('T');
    var datePart = toDdMmYyyy(parts[0]);
    var timePart = (parts[1] || '00:00') + ':00';
    return datePart + ' ' + timePart;
}
