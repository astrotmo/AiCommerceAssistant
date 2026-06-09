const ApiService = Shopware.Classes.ApiService;

class AicaDashboardApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'aica/dashboard') {
        super(httpClient, loginService, apiEndpoint);

        this.name = 'aicaDashboardApiService';
    }

    fetchOverview(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/overview`, payload, {
            headers: this.getBasicHeaders(),
        }).then(ApiService.handleResponse.bind(this));
    }

    exportSessions(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/export/sessions`, payload, {
            headers: this.getBasicHeaders(),
            responseType: 'blob',
        });
    }

    exportContacts(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/export/contacts`, payload, {
            headers: this.getBasicHeaders(),
            responseType: 'blob',
        });
    }

    exportMessages(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/export/messages`, payload, {
            headers: this.getBasicHeaders(),
            responseType: 'blob',
        });
    }

    deleteSessions(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/delete/sessions`, payload, {
            headers: this.getBasicHeaders(),
        }).then(ApiService.handleResponse.bind(this));
    }

    deleteContacts(payload = {}) {
        return this.httpClient.post(`_action/${this.apiEndpoint}/delete/contacts`, payload, {
            headers: this.getBasicHeaders(),
        }).then(ApiService.handleResponse.bind(this));
    }
}

const { Application } = Shopware;
const initContainer = Application.getContainer('init');

Application.addServiceProvider('aicaDashboardApiService', () => {
    return new AicaDashboardApiService(
        initContainer.httpClient,
        Shopware.Service('loginService'),
    );
});

export default AicaDashboardApiService;
