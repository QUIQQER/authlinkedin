define('package/quiqqer/authlinkedin/bin/classes/LinkedIn', [

    'qui/QUI',
    'qui/classes/DOM',
    'package/quiqqer/authlinkedin/bin/controls/Button',
    'Ajax',

    'css!package/quiqqer/authlinkedin/bin/classes/LinkedIn.css'

], function (QUI, QDOM, LinkedInButton, QUIAjax) {
    "use strict";

    return new Class({

        Extends: QDOM,
        Type: 'package/quiqqer/authlinkedin/bin/classes/LinkedIn',

        Binds: [],
        options: {},

        initialize: function (options) {
            this.parent(options);

            this.$token = null;
            this.$code = null;
            this.$clientId = null;
            this.$accessToken = null;
            this.$tokenExpiresAt = null;
        },

        getButton: function () {
            return new LinkedInButton();
        },

        authenticate: function () {
            return this.getClientId().then(() => {
                if (this.$token && !this.isTokenExpired()) {
                    return this.$token;
                }

                return new Promise((resolve, reject) => {
                    const redirectUri = window.location.origin + URL_OPT_DIR + 'quiqqer/authlinkedin/bin/oauth_callback.php';
                    const state = Math.random().toString(36).slice(2) + Date.now().toString(36);
                    const authUrl = 'https://www.linkedin.com/oauth/v2/authorization'
                        + '?response_type=code'
                        + '&client_id=' + encodeURIComponent(this.$clientId)
                        + '&redirect_uri=' + encodeURIComponent(redirectUri)
                        + '&scope=' + encodeURIComponent('openid profile email')
                        + '&state=' + encodeURIComponent(state);

                    const popup = window.open(
                        authUrl,
                        'linkedin_auth',
                        'width=520,height=640'
                    );

                    if (!popup) {
                        reject('LinkedIn popup blocked');
                        return;
                    }

                    let timer = null;

                    const cleanup = () => {
                        window.removeEventListener('message', onMessage);
                        if (timer) {
                            window.clearInterval(timer);
                        }
                    };

                    const onMessage = (event) => {
                        if (event.origin !== window.location.origin) {
                            return;
                        }
                        const data = event.data || {};

                        console.log('onMessage', data);

                        if (data.provider !== 'linkedin') {
                            return;
                        }

                        cleanup();

                        if (data.state !== state) {
                            reject('LinkedIn state mismatch');
                            return;
                        }

                        if (data.error) {
                            reject(data.error);
                            return;
                        }

                        this.$code = data.code || null;

                        if (!this.$code) {
                            reject('LinkedIn code missing');
                            return;
                        }

                        this.exchangeCode(this.$code, redirectUri).then((tokens) => {
                            this.$token = tokens.id_token || null;
                            this.$accessToken = tokens.access_token || null;
                            this.setTokenExpiry(tokens);

                            if (!this.$token) {
                                reject('LinkedIn id_token missing');
                                return;
                            }

                            resolve(this.$token);
                        }).catch(reject);
                    };

                    window.addEventListener('message', onMessage, false);

                    timer = window.setInterval(() => {
                        if (popup.closed) {
                            cleanup();
                            reject('LinkedIn popup closed');
                        }
                    }, 300);
                });
            });
        },

        /**
         * Exchange authorization code for LinkedIn tokens
         *
         * @param {string} code
         * @param {string} redirectUri
         * @return {Promise}
         */
        exchangeCode: function (code, redirectUri) {
            return new Promise((resolve, reject) => {
                QUIAjax.post('package_quiqqer_authlinkedin_ajax_exchangeCode', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    code: code,
                    redirectUri: redirectUri,
                    onError: reject
                });
            });
        },

        /**
         * Get Client-ID for LinkedIn API requests
         *
         * @return {Promise}
         */
        getClientId: function () {
            if (this.$clientId) {
                return Promise.resolve(this.$clientId);
            }

            return new Promise((resolve, reject) => {
                QUIAjax.get('package_quiqqer_authlinkedin_ajax_getClientId', (clientId) => {
                    this.$clientId = clientId;
                    resolve(clientId);
                }, {
                    'package': 'quiqqer/authlinkedin',
                    onError: reject
                });
            });
        },

        /**
         * Get a Linked id_token for currently connected LinkedIn account
         *
         * @return {Promise}
         */
        getToken: function () {
            if (this.$token && !this.isTokenExpired()) {
                return Promise.resolve(this.$token);
            }

            return this.authenticate();
        },

        /**
         * Check if current token is expired
         *
         * @return {boolean}
         */
        isTokenExpired: function () {
            if (!this.$tokenExpiresAt) {
                return false;
            }

            return Math.floor(Date.now() / 1000) >= this.$tokenExpiresAt;
        },

        /**
         * Derive token expiry from id_token or expires_in
         *
         * @param {Object} tokens
         */
        setTokenExpiry: function (tokens) {
            this.$tokenExpiresAt = null;

            if (tokens && tokens.id_token) {
                try {
                    const parts = tokens.id_token.split('.');
                    if (parts.length === 3) {
                        const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
                        if (payload && payload.exp) {
                            this.$tokenExpiresAt = parseInt(payload.exp, 10);
                            return;
                        }
                    }
                } catch (e) {
                }
            }

            if (tokens && tokens.expires_in) {
                const expiresIn = parseInt(tokens.expires_in, 10);
                if (!Number.isNaN(expiresIn)) {
                    this.$tokenExpiresAt = Math.floor(Date.now() / 1000) + expiresIn;
                }
            }
        },

        /**
         * Get info of LinkedIn profile
         *
         * @return {Promise}
         */
        getProfileInfo: function (token) {
            return new Promise((resolve, reject) => {
                QUIAjax.post('package_quiqqer_authlinkedin_ajax_getDataByToken', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    idToken: token,
                    onError: reject
                });
            });
        },

        /**
         * Connect a LinkedIn account with a quiqqer account
         *
         * @param {number} userId - QUIQQER User ID
         * @param {string} idToken - LinkedIn id_token
         * @return {Promise}
         */
        connectQuiqqerAccount: function (userId, idToken) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_authlinkedin_ajax_connectAccount', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    userId: userId,
                    idToken: idToken,
                    onError: reject
                });
            });
        },

        /**
         * Connect a LinkedIn account with a quiqqer account
         *
         * @param {number} userId - QUIQQER User ID
         * @return {Promise}
         */
        disconnectQuiqqerAccount: function (userId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_authlinkedin_ajax_disconnectAccount', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    userId: userId,
                    onError: reject
                });
            });
        },

        /**
         * Get details of a connected LinkedIn account based on QUIQQER User ID
         *
         * @param {number} userId - QUIQQER User ID
         * @return {Promise}
         */
        getAccountByQuiqqerUserId: function (userId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_authlinkedin_ajax_getAccountByQuiqqerUserId', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    userId: userId,
                    onError: reject
                });
            });
        },

        /**
         * Check if a LinkedIn account is connected to a QUIQQER account
         *
         * @param {string} idToken - LinkedIn API id_token
         * @return {Promise}
         */
        isAccountConnectedToQuiqqer: function (idToken) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_authlinkedin_ajax_isLinkedInAccountConnected', resolve, {
                    'package': 'quiqqer/authlinkedin',
                    idToken: idToken,
                    onError: reject
                });
            });
        }
    });
});
