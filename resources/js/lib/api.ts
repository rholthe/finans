import axios, { type InternalAxiosRequestConfig } from 'axios';

/**
 * Axios-instans for å snakke med Laravel-API-et.
 *
 * Vi bruker Laravels session-baserte auth (samme origin), så vi sender
 * cookies med hver forespørsel. Laravel legger ved en XSRF-TOKEN-cookie som
 * axios automatisk speiler tilbake som X-XSRF-TOKEN-header (CSRF-beskyttelse).
 */
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Self-healing CSRF: XSRF-TOKEN-cookien byttes hver gang Laravel regenererer
 * sesjonen (f.eks. ved innlogging) og kan bli foreldet i en gammel fane. Da
 * svarer API-et 419 «CSRF token mismatch». Ved et 419-svar henter vi en fersk
 * token via et GET mot /me (går gjennom web-middleware og fornyer cookien) og
 * prøver det opprinnelige kallet på nytt – én gang, for å unngå evig løkke.
 */
api.interceptors.response.use(undefined, async (error) => {
    const config = error.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined;

    if (error.response?.status === 419 && config && !config._csrfRetried) {
        config._csrfRetried = true;
        await api.get('/me');

        return api.request(config);
    }

    return Promise.reject(error);
});

export default api;
