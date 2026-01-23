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
        },

        getButton: function () {
            return new LinkedInButton();
        },

        authenticate: function () {
            return this.loadLinkedInScript().then(() => {
                return this.getClientId();
            }).then(() => {
                if (typeof window.AppleID === 'undefined') {
                    return Promise.reject('LinkedIn is not defined');
                }

                const redirectURI = window.location.origin + URL_OPT_DIR + 'quiqqer/authlinkedin/bin/oauth_callback.php';

                AppleID.auth.init({
                    clientId: this.$clientId,
                    scope: 'name email',
                    redirectURI: redirectURI,
                    usePopup: true
                });

                return AppleID.auth.signIn().then((response) => {
                    // response.authorization.code (für Backend)
                    // response.authorization.id_token (optional, für JWT-Daten)

                    this.$token = response.authorization.id_token;
                    this.$code = response.authorization.code;
                });
            });
        },

        loadLinkedInScript: function () {
            return new Promise((resolve, reject) => {
                const existing = document.querySelector(
                    'script[src*="appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js"]'
                );

                if (existing) {
                    resolve();
                    return;
                }


                // Workaround für AMD/RequireJS-Konflikt
                let oldDefine = window.define;
                window.define = undefined;

                const script = document.createElement('script');
                script.src = 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js';
                script.async = true;
                script.defer = true;
                script.onload = function () {
                    window.define = oldDefine; // restore define
                    resolve();
                };
                script.onerror = function () {
                    window.define = oldDefine;
                    reject();
                };
                document.head.appendChild(script);
            }).then(() => {
                this.$loaded = true;
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
            if (this.$token) {
                return Promise.resolve(this.$token);
            }

            return this.authenticate();
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